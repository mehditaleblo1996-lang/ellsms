<?php
/** ELLSMS — backend-owned inbound/outbound message adapters + ELLSMS-owned transport attempts. */
declare(strict_types=1);

/* ---------- Outbound (backend-owned) ---------- */
function backend_outbound_count(string $whereSql, array $params = []): int {
    $st = db()->prepare("SELECT COUNT(*) c FROM outbound_message m WHERE {$whereSql}");
    $st->execute($params);
    return (int)$st->fetch()['c'];
}

function backend_outbound_daily_counts(string $whereSql, array $params = []): array {
    $st = db()->prepare("SELECT DATE(sent_at) d, COUNT(*) c FROM outbound_message m WHERE {$whereSql} GROUP BY DATE(sent_at)");
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[$row['d']] = (int)$row['c'];
    }
    return $out;
}

function backend_outbound_summary(string $whereSql, array $params): array {
    $st = db()->prepare(
        "SELECT COUNT(*) total, SUM(status IN ('sent','delivered')) ok, SUM(status IN ('send_failed','failed')) bad, SUM(status = 'delivered') dlv
         FROM outbound_message m WHERE {$whereSql}"
    );
    $st->execute($params);
    return $st->fetch();
}

function backend_outbound_rows(string $whereSql, array $params, int $limit, ?array $cursor = null, bool $withUsername = true): array {
    $select = $withUsername ? 'm.*, u.username' : 'm.*';
    $join   = $withUsername ? 'JOIN user_ u ON u.id = m.sender_user_id' : '';
    $extraWhere = '';
    $extraParams = [];
    $order = 'DESC';
    if (!empty($cursor['before_id'])) {
        $extraWhere = ' AND m.id < ?';
        $extraParams[] = (int)$cursor['before_id'];
    } elseif (!empty($cursor['after_id'])) {
        $extraWhere = ' AND m.id > ?';
        $extraParams[] = (int)$cursor['after_id'];
        $order = 'ASC';
    }
    $st = db()->prepare(
        "SELECT {$select} FROM outbound_message m {$join}
         WHERE {$whereSql}{$extraWhere}
         ORDER BY m.id {$order}
         LIMIT " . max(1, $limit)
    );
    $st->execute(array_merge($params, $extraParams));
    return $st->fetchAll();
}

function backend_outbound_export_rows(string $whereSql, array $params, int $limit) {
    $st = db()->prepare(
        "SELECT m.id, u.username, m.originator, m.destination, m.content, m.status, m.reference_id, m.error_code, m.sent_at, m.delivered_at
         FROM outbound_message m JOIN user_ u ON u.id = m.sender_user_id
         WHERE {$whereSql} ORDER BY m.id DESC LIMIT " . max(1, $limit)
    );
    $st->execute($params);
    return $st;
}

function backend_outbound_export_count(string $whereSql, array $params): int {
    $st = db()->prepare("SELECT COUNT(*) FROM outbound_message m WHERE {$whereSql}");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function backend_outbound_export_page(string $whereSql, array $params, int $limit, int $afterId = 0): array {
    static $optionalSelect = null;
    if ($optionalSelect === null) {
        $found = [];
        foreach (['reference_id', 'delivered_at'] as $col) {
            $chk = db()->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $chk->execute(['outbound_message', $col]);
            if ((int)$chk->fetchColumn() > 0) {
                $found[] = $col;
            }
        }
        $optionalSelect = $found === [] ? '' : ', m.' . implode(', m.', $found);
    }
    $cursorWhere = $afterId > 0 ? ' AND m.id < ?' : '';
    $bind = $params;
    if ($afterId > 0) {
        $bind[] = $afterId;
    }
    $st = db()->prepare(
        "SELECT m.id, u.username, m.originator, m.destination, m.content, m.status,
                m.error_code, m.sent_at{$optionalSelect}
         FROM outbound_message m
         JOIN user_ u ON u.id = m.sender_user_id
         WHERE {$whereSql}{$cursorWhere}
         ORDER BY m.id DESC
         LIMIT " . max(1, $limit)
    );
    $st->execute($bind);
    return $st->fetchAll();
}

function backend_outbound_sent_count_for_user(int $userId): int {
    $st = db()->prepare('SELECT COUNT(*) c FROM outbound_message WHERE sender_user_id = ?');
    $st->execute([$userId]);
    return (int)$st->fetch()['c'];
}

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

function backend_outbound_scan(string $whereSql, array $params, int $rowCap): array {
    $st = db()->prepare("SELECT originator, sender_user_id, destination, content, status FROM outbound_message WHERE {$whereSql} LIMIT " . ($rowCap + 1));
    $st->execute($params);
    return $st->fetchAll();
}

function backend_outbound_status_scan_cursor(string $whereSql, array $params): PDOStatement {
    $st = db()->prepare("SELECT id, destination, status FROM outbound_message m WHERE {$whereSql} ORDER BY m.id DESC");
    $st->execute($params);
    return $st;
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

function backend_inbound_rows(string $whereSql, array $params, int $limit, int $offset): array {
    $st = db()->prepare("SELECT * FROM inbound_message WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
    $st->execute($params);
    return $st->fetchAll();
}

function backend_inbound_export_rows(string $whereSql, array $params, int $limit) {
    $st = db()->prepare(
        "SELECT id, originator AS sender, destination AS recipient, content, received_at
         FROM inbound_message WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit}"
    );
    $st->execute($params);
    return $st;
}

function backend_scan_new_inbound_messages(int $sinceId, int $limit = 100): array {
    $st = db()->prepare('SELECT * FROM inbound_message WHERE id > ? ORDER BY id ASC LIMIT ' . max(1, $limit));
    $st->execute([$sinceId]);
    return $st->fetchAll();
}

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

/* ---------- ELLSMS-owned transport attempts ---------- */
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
 * Records a real provider identity for delivery polling.
 *
 * Negative integer values are provider error sentinels, not message ids. Treating values such as
 * `-N` as a reference made the status worker repeatedly ask the provider about an error code and
 * polluted delivery reporting. They are now rejected before persistence; positive huge ids remain
 * strings end-to-end and are preserved exactly.
 */
function backend_record_gateway_send(
    ?int $organizationId,
    int $userId,
    string $referenceType,
    string $referenceId,
    string $destination,
    array $transport
): bool {
    $providerMessageId = trim((string)($transport['provider_message_id'] ?? ''));
    $gatewayId = isset($transport['gateway_id']) ? (int)$transport['gateway_id'] : 0;
    if ($providerMessageId === '' || $gatewayId === 0) {
        return false;
    }
    if (preg_match('/^-\d+$/D', $providerMessageId)) {
        Logger::warning('gateway.provider_reference_rejected', [
            'gateway_id' => $gatewayId,
            'user_id' => $userId,
            'reason' => 'negative_provider_reference',
        ]);
        Metrics::increment('gateway.provider_reference_rejected', 1, ['reason' => 'negative_numeric']);
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
        $gatewayId . ':' . $providerMessageId,
    ]);
    return $statement->rowCount() > 0;
}
