<?php
/**
 * WebEngage SMS service provider (SSP) integration — docs/webengage.md.
 *
 * WebEngage calls POST /api/v1/webengage/sms (app/Api/Handlers/WebEngage.php) with an ELLSMS API key,
 * one message per request; the message goes out through dispatch_message() like any API send. This
 * file holds everything else: the WebEngage payload/status-code vocabulary, the per-organization
 * settings (DSN data center + the DSN token WebEngage issued), the messageId ledger that makes a
 * retried request replay its first answer, and the delivery status notifications (DSNs) sent back.
 *
 * DSN timing: a message the gateway transport sent waits for its real delivery report —
 * gateway_status_record() calls webengage_on_delivery_state() when the attempt row reaches a final
 * state. A message with no provider id to wait on (the legacy backend API sent it) gets `sms_sent` at
 * once, and one whose report never arrives gets `sms_sent` after WEBENGAGE_DSN_MAX_WAIT_HOURS, since
 * the provider did accept it. cron/webhook-worker.php delivers the DSNs, with retries, so a slow
 * WebEngage endpoint never holds up an SMS worker.
 */

declare(strict_types=1);

const WEBENGAGE_API_PATH = '/api/v1/webengage/sms';
const WEBENGAGE_SUPPORTED_VERSIONS = ['1.0', '2.0'];
const WEBENGAGE_MAX_BODY_CHARS = 2000;
const WEBENGAGE_MAX_MESSAGE_ID_LENGTH = 190;

/** WebEngage's tracking endpoint per data center (docs.webengage.com, "SMS Service Provider"). */
const WEBENGAGE_DSN_URLS = [
    'us'  => 'https://st.webengage.com/tracking/events',
    'in'  => 'https://st.in.webengage.com/tracking/events',
    'ksa' => 'https://st.ksa.webengage.com/tracking/events',
];
const WEBENGAGE_DSN_REGIONS = ['us', 'in', 'ksa', 'custom'];

/* WebEngage status codes this integration answers with. */
const WEBENGAGE_CODE_SUCCESS              = 0;
const WEBENGAGE_CODE_INSUFFICIENT_CREDIT  = 2000;
const WEBENGAGE_CODE_IP_NOT_WHITELISTED   = 2001;
const WEBENGAGE_CODE_EMPTY_BODY           = 2002;
const WEBENGAGE_CODE_INVALID_NUMBER       = 2003;
const WEBENGAGE_CODE_INVALID_SENDER       = 2004;
const WEBENGAGE_CODE_AUTHORIZATION_FAILED = 2005;
const WEBENGAGE_CODE_BODY_TOO_LONG        = 2007;
const WEBENGAGE_CODE_EXPIRED              = 2008;
const WEBENGAGE_CODE_NOT_DELIVERED        = 2009;
const WEBENGAGE_CODE_VERSION_UNSUPPORTED  = 2010;
const WEBENGAGE_CODE_AUTHENTICATION_FAILED = 2011;
const WEBENGAGE_CODE_THROTTLED            = 2015;
const WEBENGAGE_CODE_BLACKLISTED          = 3000;
const WEBENGAGE_CODE_OTHER                = 9988;

/** HTTP status WebEngage expects with each code; 9988 and anything unlisted answer 200. */
const WEBENGAGE_HTTP_STATUS = [
    WEBENGAGE_CODE_SUCCESS               => 200,
    WEBENGAGE_CODE_INSUFFICIENT_CREDIT   => 200,
    WEBENGAGE_CODE_IP_NOT_WHITELISTED    => 401,
    WEBENGAGE_CODE_EMPTY_BODY            => 400,
    WEBENGAGE_CODE_INVALID_NUMBER        => 400,
    WEBENGAGE_CODE_INVALID_SENDER        => 400,
    WEBENGAGE_CODE_AUTHORIZATION_FAILED  => 403,
    WEBENGAGE_CODE_BODY_TOO_LONG         => 413,
    WEBENGAGE_CODE_EXPIRED               => 200,
    WEBENGAGE_CODE_NOT_DELIVERED         => 200,
    WEBENGAGE_CODE_VERSION_UNSUPPORTED   => 400,
    WEBENGAGE_CODE_AUTHENTICATION_FAILED => 401,
    WEBENGAGE_CODE_THROTTLED             => 429,
    WEBENGAGE_CODE_BLACKLISTED           => 403,
    WEBENGAGE_CODE_OTHER                 => 200,
];

/* ==========================================================================
   Configuration
   ========================================================================== */

function webengage_dsn_max_attempts(): int {
    return max(1, (int)(env('WEBENGAGE_DSN_MAX_ATTEMPTS', '8') ?? '8'));
}

/** Hours an accepted message waits for its delivery report before `sms_sent` is reported anyway. */
function webengage_dsn_max_wait_hours(): int {
    return max(1, (int)(env('WEBENGAGE_DSN_MAX_WAIT_HOURS', '24') ?? '24'));
}

/** Whether the migration has run. Cached per process; every entry point checks it first. */
function webengage_available(): bool {
    static $available = null;
    if ($available === null) {
        try {
            $st = db()->query("SELECT COUNT(*) FROM information_schema.tables
                               WHERE table_schema = DATABASE() AND table_name IN ('ellsms_webengage_settings','ellsms_webengage_messages')");
            $available = (int)$st->fetchColumn() === 2;
        } catch (Throwable) {
            $available = false;
        }
    }
    return $available;
}

/* ==========================================================================
   Request / response vocabulary (pure — unit-tested)
   ========================================================================== */

function webengage_http_status_for_code(int $code): int {
    return WEBENGAGE_HTTP_STATUS[$code] ?? 200;
}

/** The synchronous rejection body: {"status":"sms_rejected","statusCode":N,"message":"..."}. */
function webengage_reject_body(int $code, string $message, array $extra = []): array {
    return ['status' => 'sms_rejected', 'statusCode' => $code, 'message' => $message] + $extra;
}

function webengage_accept_body(): array {
    return ['status' => 'sms_accepted'];
}

/**
 * Validates WebEngage's request body. Returns ['ok' => true, 'message_id', 'to_number' (exactly as
 * sent, echoed back in the DSN), 'destination' (normalized), 'from_number', 'content',
 * 'campaign_type', 'version'] or ['ok' => false, 'code', 'message', 'extra'].
 */
function webengage_parse_request(mixed $body): array {
    $fail = static fn(int $code, string $message, array $extra = []): array
        => ['ok' => false, 'code' => $code, 'message' => $message, 'extra' => $extra];
    if (!is_array($body)) {
        return $fail(WEBENGAGE_CODE_EMPTY_BODY, 'Request body must be a JSON object.');
    }
    $version = $body['version'] ?? null;
    if (!is_string($version) || !in_array($version, WEBENGAGE_SUPPORTED_VERSIONS, true)) {
        return $fail(WEBENGAGE_CODE_VERSION_UNSUPPORTED, 'Unsupported payload version.', ['supportedVersion' => WEBENGAGE_SUPPORTED_VERSIONS[count(WEBENGAGE_SUPPORTED_VERSIONS) - 1]]);
    }
    $sms = $body['smsData'] ?? null;
    $meta = $body['metadata'] ?? null;
    if (!is_array($sms) || !is_array($meta)) {
        return $fail(WEBENGAGE_CODE_EMPTY_BODY, 'smsData and metadata are required.');
    }
    $messageId = $meta['messageId'] ?? null;
    if (!is_string($messageId) || trim($messageId) === '' || strlen($messageId) > WEBENGAGE_MAX_MESSAGE_ID_LENGTH) {
        return $fail(WEBENGAGE_CODE_OTHER, 'metadata.messageId is required.');
    }
    $content = $sms['body'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        return $fail(WEBENGAGE_CODE_EMPTY_BODY, 'Message body is empty.');
    }
    if (mb_strlen($content) > WEBENGAGE_MAX_BODY_CHARS) {
        return $fail(WEBENGAGE_CODE_BODY_TOO_LONG, 'Message body exceeds ' . WEBENGAGE_MAX_BODY_CHARS . ' characters.');
    }
    $toNumber = $sms['toNumber'] ?? null;
    $destination = is_string($toNumber) && strlen($toNumber) <= 64 ? normalize_msisdn($toNumber) : null;
    if ($destination === null) {
        return $fail(WEBENGAGE_CODE_INVALID_NUMBER, 'Invalid mobile number.');
    }
    $fromNumber = $sms['fromNumber'] ?? '';
    if (!is_string($fromNumber)) {
        return $fail(WEBENGAGE_CODE_INVALID_SENDER, 'Invalid sender ID.');
    }
    $campaignType = $meta['campaignType'] ?? null;
    return [
        'ok'            => true,
        'version'       => $version,
        'message_id'    => $messageId,
        'to_number'     => $toNumber,
        'destination'   => $destination,
        'from_number'   => trim($fromNumber),
        'content'       => trim($content),
        'campaign_type' => is_string($campaignType) ? mb_substr($campaignType, 0, 32) : null,
    ];
}

/**
 * The WebEngage code for an error the shared API pipeline (public/api/index.php) raised before the
 * handler ran. Returns [httpStatus, code].
 */
function webengage_code_for_api_error(int $httpStatus, string $apiCode): array {
    $code = match ($apiCode) {
        ApiResponse::CODE_UNAUTHENTICATED       => WEBENGAGE_CODE_AUTHENTICATION_FAILED,
        ApiResponse::CODE_RATE_LIMITED          => WEBENGAGE_CODE_THROTTLED,
        ApiResponse::CODE_FORBIDDEN,
        ApiResponse::CODE_FEATURE_NOT_AVAILABLE,
        ApiResponse::CODE_SUBSCRIPTION_INACTIVE => WEBENGAGE_CODE_AUTHORIZATION_FAILED,
        ApiResponse::CODE_PAYLOAD_TOO_LARGE     => WEBENGAGE_CODE_BODY_TOO_LONG,
        default                                 => WEBENGAGE_CODE_OTHER,
    };
    return [$code === WEBENGAGE_CODE_OTHER ? $httpStatus : webengage_http_status_for_code($code), $code];
}

/** [sms status, status code] reported for a final gateway delivery state. */
function webengage_dsn_status_for_state(string $state): array {
    return match ($state) {
        'delivered' => ['sms_sent', WEBENGAGE_CODE_SUCCESS],
        'expired'   => ['sms_failed', WEBENGAGE_CODE_EXPIRED],
        'failed', 'rejected' => ['sms_failed', WEBENGAGE_CODE_NOT_DELIVERED],
        default     => ['sms_sent', WEBENGAGE_CODE_SUCCESS],
    };
}

/** The DSN body for one ellsms_webengage_messages row. */
function webengage_dsn_payload(array $row): array {
    $payload = [
        'version'    => '1.0',
        'messageId'  => (string)$row['we_message_id'],
        'toNumber'   => (string)$row['to_number'],
        'status'     => (string)$row['dsn_sms_status'],
        'statusCode' => (int)$row['dsn_status_code'],
        'smsCount'   => max(1, (int)$row['parts']),
    ];
    if ($payload['status'] === 'sms_failed' && !empty($row['delivery_state'])) {
        $payload['message'] = 'Delivery state: ' . $row['delivery_state'];
    }
    return $payload;
}

/** The URL DSNs go to, or null when the settings do not name a usable one. */
function webengage_dsn_url(array $settings): ?string {
    $region = (string)($settings['dsn_region'] ?? 'us');
    if ($region === 'custom') {
        $url = trim((string)($settings['dsn_url'] ?? ''));
        return $url !== '' ? $url : null;
    }
    return WEBENGAGE_DSN_URLS[$region] ?? null;
}

/* ==========================================================================
   Settings (one row per organization)
   ========================================================================== */

function webengage_settings(int $organizationId): ?array {
    if ($organizationId <= 0 || !webengage_available()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_webengage_settings WHERE organization_id = ?');
    $st->execute([$organizationId]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['enabled'] = (bool)$row['enabled'];
    $row['has_token'] = $row['token_ciphertext'] !== null && $row['token_ciphertext'] !== '';
    return $row;
}

/**
 * Saves an organization's settings. $input: enabled (bool), region, url (custom region only), token
 * (blank keeps the stored one). Returns ['ok' => bool, 'reason' => ?string].
 */
function webengage_settings_save(int $organizationId, int $actorUserId, array $input): array {
    if (!webengage_available()) {
        return ['ok' => false, 'reason' => 'not_installed'];
    }
    $region = (string)($input['region'] ?? 'us');
    if (!in_array($region, WEBENGAGE_DSN_REGIONS, true)) {
        return ['ok' => false, 'reason' => 'invalid_region'];
    }
    $url = null;
    if ($region === 'custom') {
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '' || strlen($url) > 500) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }
        $check = webhook_url_validate($url);
        if (!$check['ok']) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }
    }
    $enabled = !empty($input['enabled']);
    $token = trim((string)($input['token'] ?? ''));
    $current = webengage_settings($organizationId);
    if ($enabled && $token === '' && !($current['has_token'] ?? false)) {
        return ['ok' => false, 'reason' => 'token_required'];
    }

    $tokenColumns = [$current['token_ciphertext'] ?? null, $current['token_nonce'] ?? null, $current['token_tag'] ?? null];
    if ($token !== '') {
        if (strlen($token) > 900) {
            return ['ok' => false, 'reason' => 'invalid_token'];
        }
        try {
            $tokenColumns = webhook_encrypt_secret($token);
        } catch (WebhookMasterKeyException) {
            return ['ok' => false, 'reason' => 'master_key_missing'];
        }
    }

    db()->prepare(
        'INSERT INTO ellsms_webengage_settings
            (organization_id, enabled, dsn_region, dsn_url, token_ciphertext, token_nonce, token_tag, updated_by_user_id)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), dsn_region = VALUES(dsn_region), dsn_url = VALUES(dsn_url),
            token_ciphertext = VALUES(token_ciphertext), token_nonce = VALUES(token_nonce), token_tag = VALUES(token_tag),
            updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([$organizationId, $enabled ? 1 : 0, $region, $url, $tokenColumns[0], $tokenColumns[1], $tokenColumns[2], $actorUserId]);
    webengage_enabled_cache_reset();

    Logger::info('webengage.settings.saved', ['organization_id' => $organizationId, 'actor_user_id' => $actorUserId, 'enabled' => $enabled, 'region' => $region, 'token_changed' => $token !== '']);
    audit($actorUserId, 'webengage.settings.saved', "organization_id={$organizationId} enabled=" . ($enabled ? 1 : 0) . " region={$region}" . ($token !== '' ? ' token_changed=1' : ''));
    return ['ok' => true, 'reason' => null];
}

/** Whether any organization has WebEngage on — keeps the delivery-status hook query-free otherwise. Cached ~30 s. */
function webengage_any_enabled(): bool {
    $cache = &$GLOBALS['__webengage_any_enabled'];
    if (!is_array($cache) || microtime(true) - $cache['at'] > 30) {
        $enabled = false;
        if (webengage_available()) {
            $enabled = db()->query('SELECT 1 FROM ellsms_webengage_settings WHERE enabled = 1 LIMIT 1')->fetchColumn() !== false;
        }
        $cache = ['at' => microtime(true), 'value' => $enabled];
    }
    return $cache['value'];
}

function webengage_enabled_cache_reset(): void {
    $GLOBALS['__webengage_any_enabled'] = null;
}

/* ==========================================================================
   messageId ledger
   ========================================================================== */

/**
 * Claims WebEngage's messageId for this organization. Returns one of:
 *   ['action' => 'proceed', 'id' => int]             first time this messageId is seen
 *   ['action' => 'replay', 'http_status', 'body']    already answered — answer the same way again
 *   ['action' => 'in_progress']                      another request is sending it right now
 */
function webengage_message_claim(int $organizationId, ?int $apiKeyId, ?int $userId, array $request): array {
    try {
        db()->prepare(
            'INSERT INTO ellsms_webengage_messages (organization_id, api_key_id, user_id, we_message_id, to_number, destination, campaign_type)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$organizationId, $apiKeyId, $userId, $request['message_id'], $request['to_number'], $request['destination'], $request['campaign_type']]);
        return ['action' => 'proceed', 'id' => (int)db()->lastInsertId()];
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
    }
    $st = db()->prepare('SELECT status, http_status, response_json FROM ellsms_webengage_messages WHERE organization_id = ? AND we_message_id = ?');
    $st->execute([$organizationId, $request['message_id']]);
    $row = $st->fetch();
    if (!$row || $row['status'] === 'processing' || $row['response_json'] === null) {
        return ['action' => 'in_progress'];
    }
    return ['action' => 'replay', 'http_status' => (int)$row['http_status'], 'body' => json_decode((string)$row['response_json'], true) ?: []];
}

/** Records the synchronous answer for a claimed row; $fields are extra columns to write with it. */
function webengage_message_finish(int $rowId, int $httpStatus, array $body, array $fields = []): void {
    $columns = [
        'status'        => ($body['status'] ?? '') === 'sms_accepted' ? 'accepted' : 'rejected',
        'status_code'   => (int)($body['statusCode'] ?? WEBENGAGE_CODE_SUCCESS),
        'http_status'   => $httpStatus,
        'response_json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ] + $fields;
    $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($columns)));
    db()->prepare("UPDATE ellsms_webengage_messages SET {$set} WHERE id = ?")
        ->execute([...array_values($columns), $rowId]);
}

/**
 * Ties an accepted message to the ellsms_message_attempts row holding its provider id, so its
 * delivery report produces the DSN. When the report already arrived (a push DLR can beat this
 * request's own bookkeeping), the DSN is queued here instead — the `dsn_status = 'waiting'` guard
 * on both writers lets exactly one of them queue it.
 */
function webengage_link_attempt(int $rowId, int $gatewayId, string $providerMessageId): bool {
    $st = db()->prepare('SELECT id, delivery_status FROM ellsms_message_attempts WHERE provider_slot = ?');
    $st->execute([$gatewayId . ':' . $providerMessageId]);
    $attempt = $st->fetch();
    if (!$attempt) return false;
    db()->prepare("UPDATE ellsms_webengage_messages SET attempt_id = ?, dsn_status = 'waiting' WHERE id = ?")
        ->execute([(int)$attempt['id'], $rowId]);
    // Re-read after linking: a report written between the SELECT above and the link is seen here.
    $st = db()->prepare('SELECT delivery_status FROM ellsms_message_attempts WHERE id = ?');
    $st->execute([(int)$attempt['id']]);
    $state = (string)($st->fetchColumn() ?: '');
    if ($state !== '' && gateway_state_is_terminal($state)) {
        webengage_queue_dsn_for_attempt((int)$attempt['id'], $state);
    }
    return true;
}

function webengage_queue_dsn_for_attempt(int $attemptId, string $state): void {
    [$smsStatus, $code] = webengage_dsn_status_for_state($state);
    db()->prepare(
        "UPDATE ellsms_webengage_messages
         SET delivery_state = ?, dsn_status = 'pending', dsn_sms_status = ?, dsn_status_code = ?, dsn_next_at = NOW()
         WHERE attempt_id = ? AND dsn_status = 'waiting'"
    )->execute([$state, $smsStatus, $code, $attemptId]);
}

/** Called by gateway_status_record() right after an attempt or bulk row reached final state $state. */
function webengage_on_delivery_state(string $source, int $rowId, string $state): void {
    if ($source !== 'attempt') return; // WebEngage messages are single sends, never bulk items
    try {
        if (!webengage_any_enabled()) return;
        webengage_queue_dsn_for_attempt($rowId, $state);
    } catch (Throwable $t) {
        Logger::warning('webengage.dsn.queue_failed', ['row_id' => $rowId, 'state' => $state, 'exception' => $t]);
    }
}

/** Accepted messages whose report never came: report `sms_sent` after the wait window. Returns rows moved. */
function webengage_release_stale_waiting(int $limit = 500): int {
    $st = db()->prepare(
        "UPDATE ellsms_webengage_messages
         SET dsn_status = 'pending', dsn_sms_status = 'sms_sent', dsn_status_code = 0, dsn_next_at = NOW(),
             delivery_state = COALESCE(delivery_state, 'no_report')
         WHERE dsn_status = 'waiting' AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
         ORDER BY id LIMIT " . max(1, $limit)
    );
    $st->execute([webengage_dsn_max_wait_hours()]);
    return $st->rowCount();
}

/* ==========================================================================
   DSN delivery (cron/webhook-worker.php)
   ========================================================================== */

function webengage_dsn_claim(int $limit): array {
    $limit = max(1, $limit);
    $token = worker_id() . ':' . bin2hex(random_bytes(4));
    $lease = webhook_config_timeout_seconds() + 30;
    db_transaction(function (PDO $db) use ($limit, $token, $lease): void {
        $due = $db->prepare(
            "UPDATE ellsms_webengage_messages
             SET dsn_status = 'processing', dsn_claimed_by = ?, dsn_lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), dsn_attempts = dsn_attempts + 1
             WHERE dsn_status = 'pending' AND (dsn_next_at IS NULL OR dsn_next_at <= NOW())
             ORDER BY id LIMIT {$limit}"
        );
        $due->execute([$token, $lease]);
        $remaining = $limit - $due->rowCount();
        if ($remaining > 0) {
            $db->prepare(
                "UPDATE ellsms_webengage_messages
                 SET dsn_claimed_by = ?, dsn_lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), dsn_attempts = dsn_attempts + 1
                 WHERE dsn_status = 'processing' AND dsn_lease_expires_at < NOW()
                 ORDER BY id LIMIT {$remaining}"
            )->execute([$token, $lease]);
        }
    });
    $st = db()->prepare(
        'SELECT m.*, s.enabled AS settings_enabled, s.dsn_region, s.dsn_url, s.token_ciphertext, s.token_nonce, s.token_tag
         FROM ellsms_webengage_messages m
         LEFT JOIN ellsms_webengage_settings s ON s.organization_id = m.organization_id
         WHERE m.dsn_claimed_by = ? ORDER BY m.id'
    );
    $st->execute([$token]);
    return $st->fetchAll();
}

/** One DSN POST. Returns ['outcome' => 'sent'|'retry'|'failed', 'http_status' => ?int, 'error' => ?string]. */
function webengage_dsn_attempt(array $row): array {
    if (empty($row['settings_enabled'])) {
        return ['outcome' => 'failed', 'http_status' => null, 'error' => 'integration_disabled'];
    }
    $url = webengage_dsn_url($row);
    if ($url === null) {
        return ['outcome' => 'failed', 'http_status' => null, 'error' => 'no_dsn_url'];
    }
    if ($row['dsn_region'] === 'custom') {
        $check = webhook_url_validate($url);
        if (!$check['ok']) {
            return ['outcome' => 'failed', 'http_status' => null, 'error' => 'url_blocked_' . $check['reason']];
        }
    }
    $token = $row['token_ciphertext'] !== null
        ? webhook_decrypt_secret((string)$row['token_ciphertext'], (string)$row['token_nonce'], (string)$row['token_tag'])
        : null;
    if ($token === null) {
        // Most likely a WEBHOOK_MASTER_KEY problem: retry, so fixing the key recovers the queue.
        return ['outcome' => 'retry', 'http_status' => null, 'error' => 'token_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(webengage_dsn_payload($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'User-Agent: ELLSMS-WebEngage/1.0'],
        CURLOPT_TIMEOUT        => webhook_config_timeout_seconds(),
        CURLOPT_CONNECTTIMEOUT => min(5, webhook_config_timeout_seconds()),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_MAXFILESIZE    => 65536,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno !== 0) {
        return ['outcome' => 'retry', 'http_status' => null, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'connection_failed'];
    }
    if ($httpStatus >= 200 && $httpStatus < 300) {
        return ['outcome' => 'sent', 'http_status' => $httpStatus, 'error' => null];
    }
    return ['outcome' => webhook_http_status_is_retryable($httpStatus) ? 'retry' : 'failed', 'http_status' => $httpStatus, 'error' => 'http_' . $httpStatus];
}

function webengage_dsn_finish(array $row, array $result): void {
    $id = (int)$row['id'];
    if ($result['outcome'] === 'sent') {
        db()->prepare("UPDATE ellsms_webengage_messages SET dsn_status = 'sent', dsn_http_status = ?, dsn_error = NULL, dsn_sent_at = NOW(), dsn_claimed_by = NULL, dsn_lease_expires_at = NULL WHERE id = ?")
            ->execute([$result['http_status'], $id]);
        Metrics::increment('webengage.dsn', 1, ['outcome' => 'sent']);
        return;
    }
    $attempts = (int)$row['dsn_attempts'];
    if ($result['outcome'] === 'retry' && $attempts < webengage_dsn_max_attempts()) {
        db()->prepare("UPDATE ellsms_webengage_messages SET dsn_status = 'pending', dsn_http_status = ?, dsn_error = ?, dsn_next_at = DATE_ADD(NOW(), INTERVAL ? SECOND), dsn_claimed_by = NULL, dsn_lease_expires_at = NULL WHERE id = ?")
            ->execute([$result['http_status'], $result['error'], job_retry_backoff_seconds($attempts), $id]);
        Metrics::increment('webengage.dsn', 1, ['outcome' => 'retry']);
        Logger::warning('webengage.dsn.retry_scheduled', ['row_id' => $id, 'attempt' => $attempts, 'error' => $result['error']]);
        return;
    }
    db()->prepare("UPDATE ellsms_webengage_messages SET dsn_status = 'failed', dsn_http_status = ?, dsn_error = ?, dsn_claimed_by = NULL, dsn_lease_expires_at = NULL WHERE id = ?")
        ->execute([$result['http_status'], $result['error'], $id]);
    Metrics::increment('webengage.dsn', 1, ['outcome' => 'failed']);
    Logger::warning('webengage.dsn.failed', ['row_id' => $id, 'organization_id' => $row['organization_id'], 'attempt' => $attempts, 'error' => $result['error']]);
}

/** One worker pass: release stale waits, then deliver due DSNs. Returns DSNs attempted. */
function webengage_dsn_run_pass(int $batchSize): int {
    if (!webengage_available()) return 0;
    webengage_release_stale_waiting();
    $processed = 0;
    foreach (webengage_dsn_claim($batchSize) as $row) {
        try {
            webengage_dsn_finish($row, webengage_dsn_attempt($row));
            $processed++;
        } catch (Throwable $t) {
            Logger::error('webengage.dsn.process_failed', ['row_id' => $row['id'] ?? null, 'exception' => $t]);
        }
    }
    return $processed;
}

/* ==========================================================================
   Panel
   ========================================================================== */

function webengage_recent_messages(int $organizationId, int $limit = 20): array {
    if (!webengage_available()) return [];
    $st = db()->prepare(
        'SELECT id, we_message_id, destination, originator, status, status_code, delivery_state, dsn_status, dsn_sms_status, dsn_error, created_at
         FROM ellsms_webengage_messages WHERE organization_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
    );
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

function webengage_settings_reason_text(?string $reason): string {
    return match ($reason) {
        'not_installed'      => 'جدول‌های WebEngage هنوز ساخته نشده‌اند (make db-migrations-apply).',
        'invalid_region'     => 'دیتاسنتر انتخاب‌شده معتبر نیست.',
        'invalid_url'        => 'آدرس DSN معتبر نیست؛ باید یک آدرس HTTPS عمومی باشد.',
        'token_required'     => 'برای فعال‌سازی، توکن DSN که WebEngage داده است را وارد کنید.',
        'invalid_token'      => 'توکن واردشده بیش از حد طولانی است.',
        'master_key_missing' => 'WEBHOOK_MASTER_KEY روی سرور تنظیم نشده است؛ توکن بدون آن ذخیره نمی‌شود.',
        default              => 'ذخیره‌ی تنظیمات ناموفق بود.',
    };
}
