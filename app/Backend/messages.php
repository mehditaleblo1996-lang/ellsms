<?php
/**
 * ELLSMS — inbound/outbound message repositories (Phase 8, Invariant C).
 *
 * The ONE place `inbound_message`/`outbound_message` (both backend-owned — see
 * app/backend.php's own top-of-file docblock: the backend platform's /delivery and /mo endpoints
 * write into these tables directly, ELLSMS only ever reads them) are queried from. Every function
 * here is a DB adapter (`BACKEND_MESSAGE_READ_MODE=db`, the only mode this install supports — no
 * backend read API for either table exists anywhere in this repository to call; see
 * docs/service-boundaries.md for why `api` is intentionally not offered as a real option yet rather
 * than silently faked).
 *
 * Business filtering (which WHERE clauses apply — date range, tenant scope, status, free text) stays
 * in the calling controller, exactly as before this phase; what moved here is the actual SQL
 * execution against these two tables, so Invariant A ("must not scatter direct access") is satisfied
 * without inventing a full query-builder DSL this codebase has no other precedent for.
 *
 * STEP 13's explicit warning is honored deliberately: there is no listAllInbound()-style function
 * here that an ordinary controller could call unscoped — every inbound function takes an explicit
 * $whereSql/$params the CALLER already built from allowed_originators()/organization scoping, and the
 * one truly unscoped reader (backend_scan_new_inbound_messages(), the auto-reply worker's cursor
 * scan) is named and documented distinctly, never exposed to any public/*.php controller.
 */

declare(strict_types=1);

/* ---------- Outbound (backend-owned) ---------- */

function backend_outbound_count(string $whereSql, array $params = []): int {
    $st = db()->prepare("SELECT COUNT(*) c FROM outbound_message m WHERE {$whereSql}");
    $st->execute($params);
    return (int)$st->fetch()['c'];
}

/** [date(Y-m-d) => count], only for dates present in the result — caller fills in zero-count days. */
function backend_outbound_daily_counts(string $whereSql, array $params = []): array {
    $st = db()->prepare("SELECT DATE(sent_at) d, COUNT(*) c FROM outbound_message m WHERE {$whereSql} GROUP BY DATE(sent_at)");
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[$row['d']] = (int)$row['c'];
    }
    return $out;
}

/** Aggregate summary row for reports.php (total/ok/bad/delivered counts) over an arbitrary filter. */
function backend_outbound_summary(string $whereSql, array $params): array {
    $st = db()->prepare(
        "SELECT COUNT(*) total, SUM(status IN ('sent','delivered')) ok, SUM(status IN ('send_failed','failed')) bad, SUM(status = 'delivered') dlv
         FROM outbound_message m WHERE {$whereSql}"
    );
    $st->execute($params);
    return $st->fetch();
}

/** Paged/limited rows, optionally joined to the sender's username (display-only). */
function backend_outbound_rows(string $whereSql, array $params, int $limit, int $offset = 0, bool $withUsername = true): array {
    $select = $withUsername ? 'm.*, u.username' : 'm.*';
    $join   = $withUsername ? 'JOIN user_ u ON u.id = m.sender_user_id' : '';
    $st = db()->prepare("SELECT {$select} FROM outbound_message m {$join} WHERE {$whereSql} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}");
    $st->execute($params);
    return $st->fetchAll();
}

/** Unbounded (caller-limited) rows for CSV export — reports.php's own explicit LIMIT stays caller-owned. */
function backend_outbound_export_rows(string $whereSql, array $params, int $limit): array {
    $st = db()->prepare(
        "SELECT m.id, u.username, m.originator, m.destination, m.content, m.status, m.reference_id, m.error_code, m.sent_at, m.delivered_at
         FROM outbound_message m JOIN user_ u ON u.id = m.sender_user_id
         WHERE {$whereSql} ORDER BY m.id DESC LIMIT {$limit}"
    );
    $st->execute($params);
    return $st;
}

/** users.php's per-account "sent_count" column — one user at a time (small admin table, not a hot path). */
function backend_outbound_sent_count_for_user(int $userId): int {
    $st = db()->prepare('SELECT COUNT(*) c FROM outbound_message WHERE sender_user_id = ?');
    $st->execute([$userId]);
    return (int)$st->fetch()['c'];
}

/** Batch form of the above — users.php's admin listing needs one count per row, not N+1 queries. */
function backend_outbound_sent_counts_for_users(array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT sender_user_id, COUNT(*) c FROM outbound_message WHERE sender_user_id IN ({$placeholders}) GROUP BY sender_user_id");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int)$row['sender_user_id']] = (int)$row['c'];
    }
    return $out;
}

/** analytics.php's full-scan read (platform-admin only, already row-capped by the caller). */
function backend_outbound_scan(string $whereSql, array $params, int $rowCap): array {
    $st = db()->prepare("SELECT originator, sender_user_id, destination, content, status FROM outbound_message WHERE {$whereSql} LIMIT " . ($rowCap + 1));
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------- Inbound (backend-owned) ---------- */

function backend_inbound_count(string $whereSql, array $params): int {
    $st = db()->prepare("SELECT COUNT(*) c FROM inbound_message WHERE {$whereSql}");
    $st->execute($params);
    return (int)$st->fetch()['c'];
}

function backend_inbound_today_count(): int {
    $st = db()->query("SELECT COUNT(*) c FROM inbound_message WHERE DATE(received_at) = CURDATE()");
    return (int)$st->fetch()['c'];
}

/** Paged rows for public/inbox.php's own table view — full row shape (`SELECT *`), tenant-scoped by the caller's $whereSql. */
function backend_inbound_rows(string $whereSql, array $params, int $limit, int $offset): array {
    $st = db()->prepare("SELECT * FROM inbound_message WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
    $st->execute($params);
    return $st->fetchAll();
}

/** CSV export shape — same tenant-scoped $whereSql, renamed columns matching inbox.php's existing CSV header. */
function backend_inbound_export_rows(string $whereSql, array $params, int $limit) {
    $st = db()->prepare(
        "SELECT id, originator AS sender, destination AS recipient, content, received_at
         FROM inbound_message WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit}"
    );
    $st->execute($params);
    return $st;
}

/**
 * SYSTEM-LEVEL cursor scan for the auto-reply worker (app/backend.php's autoreply_process_batch()) —
 * deliberately unscoped by tenant/originator (the worker matches EVERY new inbound message against
 * EVERY active rule, tenant/sender authorization for the resulting reply is enforced later, at the
 * point a reply is actually dispatched through the same messaging boundary every other send uses).
 * Never call this from ordinary tenant-facing code (STEP 13's explicit warning) — it is exactly the
 * unrestricted listAllInbound()-shaped function that must stay confined to this one system process.
 */
function backend_scan_new_inbound_messages(int $sinceId, int $limit = 100): array {
    $st = db()->prepare('SELECT * FROM inbound_message WHERE id > ? ORDER BY id ASC LIMIT ' . max(1, $limit));
    $st->execute([$sinceId]);
    return $st->fetchAll();
}

/**
 * SYSTEM-LEVEL, same non-tenant-facing exception as backend_scan_new_inbound_messages() above:
 * inbound_message rows whose ellsms_autoreply_log claim lease has expired (Phase 4's lease-based
 * reclaim, applied to the auto-reply worker specifically) — joined against an ELLSMS-owned table
 * (ellsms_autoreply_log) but the actual inbound_message columns still only get read here, in this
 * one repository file.
 */
function backend_scan_autoreply_retry_due_inbound(int $limit = 50): array {
    $st = db()->prepare(
        "SELECT im.* FROM inbound_message im
         JOIN ellsms_autoreply_log l ON l.inbound_message_id = im.id
         WHERE l.status = 'processing' AND l.lease_expires_at IS NOT NULL AND l.lease_expires_at < NOW()
         ORDER BY im.id ASC LIMIT " . max(1, $limit)
    );
    $st->execute();
    return $st->fetchAll();
}

/* ---------- ELLSMS-owned message attempt records (Phase 8, STEP 16/17) ---------- */

/**
 * Records a transport-level send failure (the backend API was unreachable / returned no usable
 * response) in ELLSMS's OWN ellsms_message_attempts table — this is what replaced the previous
 * behavior of writing a fabricated "send_failed" row directly into outbound_message (a backend-owned
 * table) as a fallback, which this phase removed per Invariant E/STEP 16 ("a backend API failure
 * must not cause ELLSMS to silently write into backend-owned tables as an alternative ownership
 * path"). Local audit/reconciliation only — never presented as if it were a backend-confirmed
 * outbound_message row.
 */
function backend_record_message_attempt_failure(
    ?int $organizationId,
    int $userId,
    string $referenceType,
    string $referenceId,
    ?string $idempotencyKey,
    ?string $backendRequestId,
    string $errorCode,
    string $errorMessage
): void {
    db()->prepare(
        'INSERT INTO ellsms_message_attempts
            (organization_id, user_id, reference_type, reference_id, idempotency_key, backend_request_id, status, error_code, error_message, attempted_at, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([$organizationId, $userId, $referenceType, $referenceId, $idempotencyKey, $backendRequestId, 'failed', $errorCode, mb_strimwidth($errorMessage, 0, 500, '…')]);
}

/**
 * Records ONE ACCEPTED destination of a gateway send, with the transport identity needed to ask the
 * provider about it later (docs/sms-gateway-connectors.md §Delivery status).
 *
 * The counterpart to the failure recorder above, in the same ELLSMS-owned table and for the same
 * reason: with a configured gateway, the provider answers ELLSMS directly, so nothing else in this
 * system holds the resulting provider message id. Without this row a direct send could never have its
 * delivery status tracked — which is what made generic status tracking structurally incomplete rather
 * than merely unimplemented.
 *
 * ONE ROW PER DESTINATION, because a provider message id identifies one message to one recipient.
 *
 * Recorded ONLY when the send actually went through a gateway AND the provider returned an id: a row
 * with no provider id can never be polled, so writing one would add volume and answer nothing. The
 * legacy transport therefore writes nothing here and its behaviour is completely unchanged.
 *
 * A duplicate (gateway, provider_message_id) is a NO-OP rather than an error: a retried worker pass
 * that re-reports the same provider id must not create a second delivery record for one message.
 */
function backend_record_gateway_send(
    ?int $organizationId,
    int $userId,
    string $referenceType,
    string $referenceId,
    string $destination,
    array $transport
): bool {
    $providerMessageId = (string)($transport['provider_message_id'] ?? '');
    $gatewayId = isset($transport['gateway_id']) ? (int)$transport['gateway_id'] : 0;
    if ($providerMessageId === '' || $gatewayId === 0) {
        return false;
    }

    $statement = db()->prepare(
        "INSERT INTO ellsms_message_attempts
            (organization_id, user_id, reference_type, reference_id, backend_request_id, status,
             error_code, gateway_id, gateway_config_version, route_id, operator_id, destination,
             provider_message_id, delivery_status, delivery_attempts, attempted_at, completed_at, provider_slot)
         VALUES (?,?,?,?,?, 'accepted', '', ?,?,?,?,?,?, 'sent', 0, NOW(), NOW(), ?)
         ON DUPLICATE KEY UPDATE id = id"
    );
    $statement->execute([
        $organizationId, $userId, $referenceType, $referenceId,
        $transport['request_id'] ?? null,
        $gatewayId,
        isset($transport['gateway_config_version']) ? (int)$transport['gateway_config_version'] : null,
        isset($transport['route_id']) ? (int)$transport['route_id'] : null,
        isset($transport['operator_id']) ? (int)$transport['operator_id'] : null,
        mb_strimwidth($destination, 0, 32, ''),
        $providerMessageId,
        // delivery_status starts at 'sent' — what the gateway ACCEPTING a message actually means.
        // Only the status connector may claim delivery, and only from the provider's own answer.
        $gatewayId . ':' . $providerMessageId,
    ]);
    return $statement->rowCount() > 0;
}
