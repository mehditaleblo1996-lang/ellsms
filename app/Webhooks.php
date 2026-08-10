<?php
/**
 * ELLSMS — webhook endpoints, outbox events, and delivery signing (Phase 12).
 *
 * Three concerns live here, deliberately kept in one file since they share the same small set of
 * tables and none is large enough alone to justify a split (matches app/wallet.php's own "no
 * class/namespace, one cohesive service file" convention):
 *  - endpoint CRUD + fail-closed SSRF URL validation (STEP 29)
 *  - envelope-encrypted secret storage/rotation (STEP 30)
 *  - event outbox + HMAC signing (STEP 27/28/31/32)
 *
 * cron/webhook-worker.php owns the actual delivery attempt loop (claim/HTTP call/retry) — this file
 * provides everything that loop needs (URL re-validation, decrypted secret, signature, retry
 * classification) but does not itself perform any outbound HTTP request.
 */

declare(strict_types=1);

/* ==========================================================================
   Configuration
   ========================================================================== */

function webhook_config_timeout_seconds(): int {
    return max(1, (int)(env('WEBHOOK_TIMEOUT_SECONDS', '10') ?? '10'));
}

function webhook_config_max_attempts(): int {
    return max(1, (int)(env('WEBHOOK_MAX_ATTEMPTS', '8') ?? '8'));
}

function webhook_config_max_response_bytes(): int {
    return max(256, (int)(env('WEBHOOK_MAX_RESPONSE_BYTES', '4096') ?? '4096'));
}

function webhook_config_require_https(): bool {
    return (env('WEBHOOK_REQUIRE_HTTPS', '1') ?? '1') === '1';
}

/** Consecutive permanent-failure deliveries before an endpoint is auto-disabled (STEP 37) — not one transient blip, a sustained pattern. */
const WEBHOOK_AUTO_DISABLE_THRESHOLD = 20;

/** Replay-window tolerance for signature verification examples/reference code (STEP 31/32). */
const WEBHOOK_SIGNATURE_TOLERANCE_SECONDS = 300;

/* ==========================================================================
   SSRF-safe URL validation (STEP 29) — checked at creation AND re-checked by
   cron/webhook-worker.php immediately before every delivery attempt, since a hostname's DNS answer
   can legitimately change between the two (DNS rebinding). Fail closed: anything that cannot be
   proven safe is rejected, never "allowed because we couldn't tell."
   ========================================================================== */

/** CIDR blocks no webhook destination may ever resolve to — private/loopback/link-local/reserved, both address families. Reuses ip_in_cidr() from app/bootstrap.php. */
const WEBHOOK_BLOCKED_IP_RANGES = [
    // IPv4
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
    '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16',
    '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    '255.255.255.255/32',
    // IPv6
    '::1/128', '::/128', '64:ff9b::/96', '100::/64', '2001:db8::/32',
    'fc00::/7', 'fe80::/10', 'ff00::/8',
];

function webhook_ip_is_blocked(string $ip): bool {
    // IPv4-mapped IPv6 (::ffff:a.b.c.d) must be checked against the IPv4 ranges too, or
    // ::ffff:169.254.169.254 would sail past every IPv4-shaped rule above.
    if (str_starts_with($ip, '::ffff:') && str_contains(substr($ip, 7), '.')) {
        $ip = substr($ip, 7);
    }
    foreach (WEBHOOK_BLOCKED_IP_RANGES as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Resolves $host to every A/AAAA record it currently has and reports whether ANY of them lands in a
 * blocked range — a hostname with even one blocked answer is rejected outright rather than "hope the
 * app happens to connect to a safe one," since we don't control which answer curl's own resolver
 * picks. Returns null (treated as a validation failure by the caller) if resolution itself fails —
 * an unresolvable host is not "safe by default," it's unusable.
 */
function webhook_resolve_and_check(string $host): ?bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !webhook_ip_is_blocked($host);
    }
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if ($records === false || $records === []) {
        return null;
    }
    foreach ($records as $r) {
        $ip = $r['ip'] ?? $r['ipv6'] ?? null;
        if ($ip !== null && webhook_ip_is_blocked((string)$ip)) {
            return false;
        }
    }
    return true;
}

/**
 * Test-only escape hatch for the private/loopback IP block below — mirrors the EXACT same pattern
 * cron/load-test.php (ELLSMS_ALLOW_LOAD_TEST) and cron/dr-drill.php already established: an
 * explicit opt-in env var, honored ONLY when this also isn't a production environment. Without
 * this, real webhook DELIVERY (signing, retry classification, response handling) could never be
 * integration-tested end-to-end against a real HTTP receiver, since every reachable address in any
 * local/CI/Docker test topology is itself inside a blocked range (loopback or RFC1918) by
 * definition. cron/config-check.php FAILs if this is ever set with APP_ENV=production, exactly
 * like the load-test flag.
 */
function webhook_local_targets_allowed(): bool {
    return (env('WEBHOOK_ALLOW_PRIVATE_TARGETS', '0') ?? '0') === '1' && app_env() !== 'production';
}

/**
 * Full fail-closed validation of a candidate webhook URL (STEP 29). Returns ['ok'=>true] or
 * ['ok'=>false,'reason'=>machine-readable code].
 */
function webhook_url_validate(string $url): array {
    if (strlen($url) > 2048) {
        return ['ok' => false, 'reason' => 'url_too_long'];
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
        return ['ok' => false, 'reason' => 'url_invalid'];
    }
    $scheme = strtolower($parts['scheme']);
    if (webhook_config_require_https() && $scheme !== 'https') {
        return ['ok' => false, 'reason' => 'https_required'];
    }
    if (!in_array($scheme, ['https', 'http'], true)) {
        return ['ok' => false, 'reason' => 'unsupported_scheme'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'reason' => 'credentials_in_url'];
    }
    if (webhook_local_targets_allowed()) {
        return ['ok' => true];
    }

    $host = strtolower($parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return ['ok' => false, 'reason' => 'blocked_host'];
    }

    $resolved = webhook_resolve_and_check($host);
    if ($resolved === null) {
        return ['ok' => false, 'reason' => 'dns_resolution_failed'];
    }
    if ($resolved === false) {
        return ['ok' => false, 'reason' => 'blocked_ip_range'];
    }
    return ['ok' => true];
}

/* ==========================================================================
   Secret envelope encryption (STEP 30) — AES-256-GCM keyed by WEBHOOK_MASTER_KEY, so the raw
   signing secret is recoverable at delivery time (unlike an API key's secret, which never needs to
   be recovered, only verified — see app/ApiKeys.php's docblock for that contrast).
   ========================================================================== */

class WebhookMasterKeyException extends RuntimeException {
}

/** Decoded 32-byte key, or throws — every caller must treat a missing/malformed key as a hard configuration failure, never silently skip encryption. */
function webhook_master_key(): string {
    $raw = (string)(env('WEBHOOK_MASTER_KEY', '') ?? '');
    if ($raw === '') {
        throw new WebhookMasterKeyException('WEBHOOK_MASTER_KEY is not configured');
    }
    $key = base64_decode($raw, true);
    if ($key === false || strlen($key) !== 32) {
        throw new WebhookMasterKeyException('WEBHOOK_MASTER_KEY must be 32 bytes, base64-encoded');
    }
    return $key;
}

/** Returns [ciphertext, nonce, tag] — all raw binary, stored in their own VARBINARY columns. */
function webhook_encrypt_secret(string $secret): array {
    $key = webhook_master_key();
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('webhook secret encryption failed');
    }
    return [$ciphertext, $nonce, $tag];
}

function webhook_decrypt_secret(string $ciphertext, string $nonce, string $tag): ?string {
    try {
        $key = webhook_master_key();
    } catch (WebhookMasterKeyException) {
        return null;
    }
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plain === false ? null : $plain;
}

/* ==========================================================================
   Endpoint CRUD (tenant-scoped, Invariant B — every function here takes $organizationId and never
   returns/touches a row belonging to a different one).
   ========================================================================== */

function webhook_endpoint_generate_secret(): string {
    return api_key_b64url(random_bytes(32));
}

function webhook_endpoint_create(int $organizationId, int $actorUserId, string $url, string $description, array $eventTypes): array {
    $urlCheck = webhook_url_validate($url);
    if (!$urlCheck['ok']) {
        return ['ok' => false, 'reason' => $urlCheck['reason']];
    }
    $normalizedEvents = WebhookEvents::normalize($eventTypes);
    if ($normalizedEvents === null) {
        return ['ok' => false, 'reason' => 'invalid_event_types'];
    }

    $secret = webhook_endpoint_generate_secret();
    [$ciphertext, $nonce, $tag] = webhook_encrypt_secret($secret);

    db()->prepare(
        'INSERT INTO ellsms_webhook_endpoints
            (organization_id, url, description, secret_ciphertext, secret_nonce, secret_tag, enabled, event_types_json, created_by_user_id)
         VALUES (?,?,?,?,?,?,1,?,?)'
    )->execute([$organizationId, $url, mb_strimwidth(trim($description), 0, 160, ''), $ciphertext, $nonce, $tag, json_encode($normalizedEvents), $actorUserId]);
    $id = (int)db()->lastInsertId();

    Logger::info('webhook.endpoint.created', ['organization_id' => $organizationId, 'endpoint_id' => $id, 'actor_user_id' => $actorUserId]);
    audit($actorUserId, 'webhook.endpoint.created', "endpoint_id={$id}");

    return ['ok' => true, 'id' => $id, 'secret' => $secret];
}

function webhook_endpoint_list(int $organizationId): array {
    $st = db()->prepare(
        'SELECT id, url, description, enabled, event_types_json, consecutive_failures, last_success_at,
                last_failure_at, last_error_code, disabled_reason, created_by_user_id, created_at, updated_at
         FROM ellsms_webhook_endpoints WHERE organization_id = ? ORDER BY created_at DESC'
    );
    $st->execute([$organizationId]);
    return array_map(static function (array $row): array {
        $row['event_types'] = json_decode($row['event_types_json'], true) ?: [];
        unset($row['event_types_json']);
        return $row;
    }, $st->fetchAll());
}

function webhook_endpoint_find(int $organizationId, int $endpointId): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_webhook_endpoints WHERE id = ? AND organization_id = ?');
    $st->execute([$endpointId, $organizationId]);
    $row = $st->fetch();
    return $row ?: null;
}

function webhook_endpoint_update(int $organizationId, int $endpointId, int $actorUserId, array $changes): array {
    $endpoint = webhook_endpoint_find($organizationId, $endpointId);
    if (!$endpoint) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $sets = [];
    $params = [];

    if (array_key_exists('url', $changes)) {
        $urlCheck = webhook_url_validate($changes['url']);
        if (!$urlCheck['ok']) {
            return ['ok' => false, 'reason' => $urlCheck['reason']];
        }
        $sets[] = 'url = ?';
        $params[] = $changes['url'];
    }
    if (array_key_exists('description', $changes)) {
        $sets[] = 'description = ?';
        $params[] = mb_strimwidth(trim((string)$changes['description']), 0, 160, '');
    }
    if (array_key_exists('event_types', $changes)) {
        $normalizedEvents = WebhookEvents::normalize($changes['event_types']);
        if ($normalizedEvents === null) {
            return ['ok' => false, 'reason' => 'invalid_event_types'];
        }
        $sets[] = 'event_types_json = ?';
        $params[] = json_encode($normalizedEvents);
    }
    if (array_key_exists('enabled', $changes)) {
        $sets[] = 'enabled = ?';
        $params[] = $changes['enabled'] ? 1 : 0;
        // A manual re-enable clears any auto-disable bookkeeping and gives the endpoint a clean
        // slate — otherwise it could immediately auto-disable again on the very next failure.
        if ($changes['enabled']) {
            $sets[] = 'consecutive_failures = 0';
            $sets[] = 'disabled_reason = NULL';
        }
    }
    if (!$sets) {
        return ['ok' => true, 'reason' => 'no_changes'];
    }

    $params[] = $endpointId;
    db()->prepare('UPDATE ellsms_webhook_endpoints SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    Logger::info('webhook.endpoint.updated', ['organization_id' => $organizationId, 'endpoint_id' => $endpointId, 'actor_user_id' => $actorUserId, 'fields' => array_keys($changes)]);
    audit($actorUserId, 'webhook.endpoint.updated', "endpoint_id={$endpointId}");
    return ['ok' => true];
}

function webhook_endpoint_rotate_secret(int $organizationId, int $endpointId, int $actorUserId): array {
    $endpoint = webhook_endpoint_find($organizationId, $endpointId);
    if (!$endpoint) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $secret = webhook_endpoint_generate_secret();
    [$ciphertext, $nonce, $tag] = webhook_encrypt_secret($secret);
    db()->prepare('UPDATE ellsms_webhook_endpoints SET secret_ciphertext = ?, secret_nonce = ?, secret_tag = ? WHERE id = ?')
        ->execute([$ciphertext, $nonce, $tag, $endpointId]);
    Logger::info('webhook.endpoint.secret_rotated', ['organization_id' => $organizationId, 'endpoint_id' => $endpointId, 'actor_user_id' => $actorUserId]);
    audit($actorUserId, 'webhook.endpoint.secret_rotated', "endpoint_id={$endpointId}");
    return ['ok' => true, 'secret' => $secret];
}

/** Real DELETE when no delivery history references this endpoint yet; otherwise a permanent disable (FK RESTRICT protects delivery history from ever dangling — see the migration). */
function webhook_endpoint_delete(int $organizationId, int $endpointId, int $actorUserId): array {
    $endpoint = webhook_endpoint_find($organizationId, $endpointId);
    if (!$endpoint) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    try {
        $deleted = db()->prepare('DELETE FROM ellsms_webhook_endpoints WHERE id = ?');
        $deleted->execute([$endpointId]);
        Logger::info('webhook.endpoint.deleted', ['organization_id' => $organizationId, 'endpoint_id' => $endpointId, 'actor_user_id' => $actorUserId]);
        audit($actorUserId, 'webhook.endpoint.deleted', "endpoint_id={$endpointId}");
        return ['ok' => true, 'mode' => 'deleted'];
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        db()->prepare("UPDATE ellsms_webhook_endpoints SET enabled = 0, disabled_reason = 'deleted_with_delivery_history' WHERE id = ?")->execute([$endpointId]);
        Logger::info('webhook.endpoint.disabled_instead_of_deleted', ['organization_id' => $organizationId, 'endpoint_id' => $endpointId, 'reason' => 'has_delivery_history']);
        audit($actorUserId, 'webhook.endpoint.disabled_instead_of_deleted', "endpoint_id={$endpointId}");
        return ['ok' => true, 'mode' => 'disabled'];
    }
}

/* ==========================================================================
   Delivery-outcome bookkeeping (STEP 37) — called by cron/webhook-worker.php after every attempt.
   ========================================================================== */

function webhook_endpoint_record_success(int $endpointId): void {
    db()->prepare('UPDATE ellsms_webhook_endpoints SET consecutive_failures = 0, last_success_at = NOW() WHERE id = ?')->execute([$endpointId]);
}

/** $terminal = this delivery attempt reached a TERMINAL failure (permanent, or retries exhausted) — only terminal failures count toward auto-disable, a mid-retry attempt does not. */
function webhook_endpoint_record_failure(int $endpointId, string $errorCode, bool $terminal): void {
    db()->prepare('UPDATE ellsms_webhook_endpoints SET last_failure_at = NOW(), last_error_code = ? WHERE id = ?')->execute([$errorCode, $endpointId]);
    if (!$terminal) {
        return;
    }
    $db = db();
    $db->prepare('UPDATE ellsms_webhook_endpoints SET consecutive_failures = consecutive_failures + 1 WHERE id = ?')->execute([$endpointId]);
    $st = $db->prepare('SELECT consecutive_failures, enabled FROM ellsms_webhook_endpoints WHERE id = ?');
    $st->execute([$endpointId]);
    $row = $st->fetch();
    if ($row && (int)$row['enabled'] === 1 && (int)$row['consecutive_failures'] >= WEBHOOK_AUTO_DISABLE_THRESHOLD) {
        $db->prepare("UPDATE ellsms_webhook_endpoints SET enabled = 0, disabled_reason = 'auto_disabled_excessive_failures' WHERE id = ?")->execute([$endpointId]);
        Logger::warning('webhook.endpoint.auto_disabled', ['endpoint_id' => $endpointId, 'consecutive_failures' => $row['consecutive_failures']]);
    }
}

/* ==========================================================================
   Event outbox + fan-out (STEP 27/32) — call this AFTER the triggering business transaction has
   already committed (never from inside it): announcing an event for a change that later rolls back
   would hand subscribers a webhook for something that never actually happened.
   ========================================================================== */

function webhook_uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Records one outbox event and fans it out to every ENABLED endpoint in $organizationId currently
 * subscribed to $eventType — creating one ellsms_webhook_deliveries row per matching endpoint, all
 * referencing the SAME event_uuid (STEP 32: retries of any one delivery never mint a new logical
 * event). A organization with zero matching endpoints still records the event row (useful audit
 * trail / lets an endpoint added later optionally be backfilled by an operator), it just fans out to
 * nothing.
 */
function webhook_event_emit(int $organizationId, string $eventType, string $resourceType, string $resourceId, array $data): ?string {
    if (!WebhookEvents::isValid($eventType)) {
        Logger::error('webhook.event.invalid_type', ['event_type' => $eventType]);
        return null;
    }
    $eventUuid = webhook_uuid4();
    $payload = [
        'event_id'        => $eventUuid,
        'event_type'      => $eventType,
        'created_at'      => date('c'),
        'organization_id' => $organizationId,
        'api_version'     => 'v1',
        'data'            => $data,
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return db_transaction(function (PDO $db) use ($organizationId, $eventUuid, $eventType, $resourceType, $resourceId, $payloadJson): string {
        $db->prepare(
            'INSERT INTO ellsms_webhook_events (event_uuid, organization_id, event_type, resource_type, resource_id, payload_json)
             VALUES (?,?,?,?,?,?)'
        )->execute([$eventUuid, $organizationId, $eventType, $resourceType, $resourceId, $payloadJson]);
        $eventId = (int)$db->lastInsertId();

        $subscribers = $db->prepare("SELECT id FROM ellsms_webhook_endpoints WHERE organization_id = ? AND enabled = 1 AND JSON_CONTAINS(event_types_json, ?)");
        $subscribers->execute([$organizationId, json_encode($eventType)]);
        $endpointIds = array_map('intval', $subscribers->fetchAll(PDO::FETCH_COLUMN));

        $ins = $db->prepare('INSERT INTO ellsms_webhook_deliveries (event_id, endpoint_id, status) VALUES (?,?,\'pending\')');
        foreach ($endpointIds as $endpointId) {
            $ins->execute([$eventId, $endpointId]);
        }

        Logger::info('webhook.event.emitted', ['organization_id' => $organizationId, 'event_type' => $eventType, 'event_id' => $eventUuid, 'fanout_count' => count($endpointIds)]);
        return $eventUuid;
    });
}

/**
 * Sends a test event to exactly ONE endpoint (STEP 36), bypassing the normal subscription-list
 * fan-out webhook_event_emit() does — an operator explicitly testing endpoint #5 wants exactly one
 * delivery attempt against #5, not one against every endpoint that happens to subscribe to the
 * event type being borrowed for the synthetic payload. The event row itself is still recorded
 * (audit trail), just fanned out to a single, caller-specified endpoint rather than by subscription.
 */
function webhook_event_emit_to_endpoint(int $organizationId, int $endpointId, string $eventType, string $resourceType, string $resourceId, array $data): ?string {
    if (!WebhookEvents::isValid($eventType)) {
        return null;
    }
    $eventUuid = webhook_uuid4();
    $payload = [
        'event_id' => $eventUuid, 'event_type' => $eventType, 'created_at' => date('c'),
        'organization_id' => $organizationId, 'api_version' => 'v1', 'data' => $data,
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return db_transaction(function (PDO $db) use ($organizationId, $endpointId, $eventUuid, $eventType, $resourceType, $resourceId, $payloadJson): string {
        $db->prepare(
            'INSERT INTO ellsms_webhook_events (event_uuid, organization_id, event_type, resource_type, resource_id, payload_json)
             VALUES (?,?,?,?,?,?)'
        )->execute([$eventUuid, $organizationId, $eventType, $resourceType, $resourceId, $payloadJson]);
        $eventId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_webhook_deliveries (event_id, endpoint_id, status) VALUES (?,?,\'pending\')')->execute([$eventId, $endpointId]);
        return $eventUuid;
    });
}

/* ==========================================================================
   Signing (STEP 31) — canonical input is "{timestamp}.{raw_body}", HMAC-SHA256, hex-encoded.
   ========================================================================== */

function webhook_signature_compute(string $secret, string $timestamp, string $rawBody): string {
    return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
}

/** Reference verifier (documented/tested — see docs/webhooks.md) a receiver would implement independently; kept here too so app code and docs can never drift apart. */
function webhook_signature_verify(string $secret, string $timestamp, string $rawBody, string $providedSignatureHex, int $toleranceSeconds = WEBHOOK_SIGNATURE_TOLERANCE_SECONDS): bool {
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > $toleranceSeconds) {
        return false;
    }
    $expected = webhook_signature_compute($secret, $timestamp, $rawBody);
    return hash_equals($expected, $providedSignatureHex);
}

/* ==========================================================================
   Delivery claim (STEP 33) — deliberately the SAME atomic-UPDATE claim shape as
   bulk_claim_items() (app/backend.php, Phase 4): a plain `UPDATE ... ORDER BY id LIMIT n` rather
   than `SELECT ... FOR UPDATE SKIP LOCKED`, because Phase 4's own BulkItemConcurrencyTest already
   proved SKIP LOCKED can silently under-claim under genuine concurrent load on this exact MySQL
   version — no reason to risk repeating that bug in a second, independently-written claim query.
   ========================================================================== */

function webhook_claim_deliveries(PDO $db, int $limit): array {
    $workerId = worker_id();
    $leaseSeconds = webhook_config_timeout_seconds() + 30; // comfortably longer than one HTTP attempt's own timeout
    $claimToken = $workerId . ':' . bin2hex(random_bytes(4));

    db_transaction(function (PDO $db) use ($limit, $claimToken, $leaseSeconds): void {
        $duePending = $db->prepare(
            "UPDATE ellsms_webhook_deliveries
             SET status='processing', claimed_by=?, claimed_at=NOW(),
                 lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
             WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id LIMIT {$limit}"
        );
        $duePending->execute([$claimToken, $leaseSeconds]);
        $remaining = $limit - $duePending->rowCount();

        if ($remaining > 0) {
            $expiredLease = $db->prepare(
                "UPDATE ellsms_webhook_deliveries
                 SET claimed_by=?, claimed_at=NOW(), lease_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), attempt_count=attempt_count+1
                 WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()
                 ORDER BY id LIMIT {$remaining}"
            );
            $expiredLease->execute([$claimToken, $leaseSeconds]);
        }
    });

    $sel = $db->prepare(
        "SELECT d.*, e.event_uuid, e.event_type, e.payload_json, ep.url, ep.secret_ciphertext, ep.secret_nonce, ep.secret_tag, ep.enabled AS endpoint_enabled
         FROM ellsms_webhook_deliveries d
         JOIN ellsms_webhook_events e ON e.id = d.event_id
         JOIN ellsms_webhook_endpoints ep ON ep.id = d.endpoint_id
         WHERE d.claimed_by = ? ORDER BY d.id"
    );
    $sel->execute([$claimToken]);
    return $sel->fetchAll();
}

/* ==========================================================================
   Retry classification (STEP 34) — shared by cron/webhook-worker.php.
   ========================================================================== */

const WEBHOOK_RETRYABLE_HTTP_STATUSES = [408, 425, 429, 500, 502, 503, 504];
const WEBHOOK_PERMANENT_HTTP_STATUSES = [400, 401, 403, 404, 410, 422];

function webhook_http_status_is_retryable(int $status): bool {
    if (in_array($status, WEBHOOK_PERMANENT_HTTP_STATUSES, true)) {
        return false;
    }
    if (in_array($status, WEBHOOK_RETRYABLE_HTTP_STATUSES, true)) {
        return true;
    }
    // Any other 2xx/3xx never reaches this classifier (2xx is success, curl without
    // FOLLOWLOCATION treats 3xx as a plain response body); an unexpected 4xx not explicitly listed
    // is treated as permanent (the far more common shape for "client error"), an unexpected 5xx as
    // retryable — errs toward the safer interpretation for each class rather than guessing.
    return $status >= 500;
}

/* ==========================================================================
   Actual delivery attempt (STEP 29/31/33/35) — the only place this codebase makes an outbound HTTP
   call to a customer-controlled URL, so every SSRF/timeout/response-limiting control lives right
   here, in one auditable function.
   ========================================================================== */

/**
 * Performs ONE delivery attempt for a claimed row from webhook_claim_deliveries(). Returns
 * ['outcome' => 'delivered'|'retry'|'permanent_failure', 'http_status' => ?int, 'error_code' =>
 * ?string, 'response_excerpt' => ?string, 'duration_ms' => int].
 *
 * Re-validates the URL immediately before connecting (STEP 29 — DNS can legitimately have changed
 * since the endpoint was created/last delivered to; trusting only the creation-time check would
 * reopen exactly the DNS-rebinding SSRF window that check exists to close) and disables redirect
 * following entirely (CURLOPT_FOLLOWLOCATION is never set true) — a 3xx response is just a response,
 * never a reason for curl to itself connect somewhere new that was never independently validated.
 */
function webhook_attempt_delivery(array $delivery): array {
    $revalidation = webhook_url_validate($delivery['url']);
    if (!$revalidation['ok']) {
        return ['outcome' => 'permanent_failure', 'http_status' => null, 'error_code' => 'ssrf_blocked_' . $revalidation['reason'], 'response_excerpt' => null, 'duration_ms' => 0];
    }

    $secret = webhook_decrypt_secret($delivery['secret_ciphertext'], $delivery['secret_nonce'], $delivery['secret_tag']);
    if ($secret === null) {
        // A misconfigured/rotated-out-from-under-us WEBHOOK_MASTER_KEY — an operational failure, not
        // the endpoint's fault, but still cannot be delivered; classified retryable so it recovers
        // automatically once the key is fixed, rather than dead-lettering endpoints permanently over
        // what is very likely a transient deploy-time misconfiguration.
        return ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'secret_decryption_failed', 'response_excerpt' => null, 'duration_ms' => 0];
    }

    $timestamp = (string)time();
    $rawBody = $delivery['payload_json'];
    $signature = webhook_signature_compute($secret, $timestamp, $rawBody);

    $maxResponseBytes = webhook_config_max_response_bytes();
    $captured = '';
    $ch = curl_init($delivery['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rawBody,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-ELLSMS-Event-ID: ' . $delivery['event_uuid'],
            'X-ELLSMS-Timestamp: ' . $timestamp,
            'X-ELLSMS-Signature: ' . $signature,
            'User-Agent: ELLSMS-Webhooks/1.0',
        ],
        CURLOPT_TIMEOUT        => webhook_config_timeout_seconds(),
        CURLOPT_CONNECTTIMEOUT => min(5, webhook_config_timeout_seconds()),
        CURLOPT_FOLLOWLOCATION => false, // never — see docblock
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => false, // streamed via WRITEFUNCTION instead, so we can hard-cap bytes read
        CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$captured, $maxResponseBytes): int {
            $room = $maxResponseBytes - strlen($captured);
            if ($room > 0) {
                $captured .= substr($chunk, 0, $room);
            }
            return strlen($chunk); // always report the FULL chunk consumed to curl, even the truncated part — returning less aborts the transfer
        },
    ]);

    $startedAt = microtime(true);
    curl_exec($ch);
    $durationMs = (int)((microtime(true) - $startedAt) * 1000);
    $errno = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Belt-and-suspenders on top of the WRITEFUNCTION's own per-chunk cap above (STEP 35: never
    // store the full response) -- a hard substr() here guarantees the bound regardless of how curl
    // happened to chunk the transfer.
    $captured = substr($captured, 0, $maxResponseBytes);
    $excerpt = mb_strimwidth($captured, 0, 1024, '…', 'UTF-8');

    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'timeout', 'response_excerpt' => null, 'duration_ms' => $durationMs];
    }
    if ($errno !== 0) {
        return ['outcome' => 'retry', 'http_status' => null, 'error_code' => 'connection_failed', 'response_excerpt' => null, 'duration_ms' => $durationMs];
    }
    if ($httpStatus >= 200 && $httpStatus < 300) {
        return ['outcome' => 'delivered', 'http_status' => $httpStatus, 'error_code' => null, 'response_excerpt' => $excerpt, 'duration_ms' => $durationMs];
    }

    $retryable = webhook_http_status_is_retryable($httpStatus);
    return [
        'outcome'          => $retryable ? 'retry' : 'permanent_failure',
        'http_status'      => $httpStatus,
        'error_code'       => 'http_' . $httpStatus,
        'response_excerpt' => $excerpt,
        'duration_ms'      => $durationMs,
    ];
}
