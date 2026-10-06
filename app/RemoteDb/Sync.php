<?php
/**
 * #45 — Customer database connector: the sync engine run by cron/remote-db-worker.php.
 *
 * One cycle per due connection, under a lease so exactly one worker owns a connection at a time:
 *
 *   1. INGEST    read the customer's pending rows, record each in ellsms_remote_db_rows (the unique
 *                key there is the "never send twice" guarantee), mark them in-progress on the
 *                customer's side, and queue them through bulk_queue_job() as the connection's user —
 *                so wallet, pricing, quota, content policy, opt-outs and the whole bulk pipeline
 *                apply exactly as for a file upload.
 *   2. RECOVER   rows taken but not queued (no credit, pricing missing, a crash between steps) are
 *                linked to the item a previous attempt already created, or queued again — never both.
 *   3. STATUS    every open row whose ELLSMS state moved since the last write is written back to the
 *                customer's row (status word, ELLSMS id, provider id, parts, error, timestamps). Writes
 *                only ever move forward; a row is closed when its delivery is final or too old.
 *   4. INBOUND   messages received on the user's own lines are inserted into the customer's inbound
 *                table, behind a cursor (and an external-id check when that column is mapped).
 *
 * Failure model: anything that goes wrong on the customer's database fails the CYCLE, is recorded
 * on the connection (last_error, events) and retried with backoff. ELLSMS state is only advanced
 * after the customer side committed, so a retry repeats a write rather than skipping one.
 */

declare(strict_types=1);

/* ==========================================================================
   Loading, events, lease
   ========================================================================== */

function remote_db_connection_load(int $id): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_remote_db_connections WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? remote_db_connection_decode($row) : null;
}

/** JSON columns decoded once, with defaults filled in for anything missing. */
function remote_db_connection_decode(array $row): array {
    $row['outbound'] = json_decode((string)($row['outbound_mapping_json'] ?? ''), true) ?: remote_db_default_outbound_mapping();
    $row['status_values'] = (json_decode((string)($row['status_values_json'] ?? ''), true) ?: []) + remote_db_default_status_values();
    $row['inbound'] = json_decode((string)($row['inbound_mapping_json'] ?? ''), true) ?: [];
    return $row;
}

function remote_db_event(int $connectionId, string $level, string $event, string $detail = ''): void {
    try {
        db()->prepare('INSERT INTO ellsms_remote_db_events (connection_id, level, event, detail) VALUES (?,?,?,?)')
            ->execute([$connectionId, $level, mb_substr($event, 0, 60), mb_strimwidth($detail, 0, 1000, '…')]);
    } catch (Throwable $t) {
        Logger::error('remote_db.event_write_failed', ['connection_id' => $connectionId, 'exception' => $t]);
    }
    $context = ['connection_id' => $connectionId, 'event' => $event, 'detail' => mb_strimwidth($detail, 0, 300, '…')];
    match ($level) {
        'error'   => Logger::error('remote_db.' . $event, $context),
        'warning' => Logger::warning('remote_db.' . $event, $context),
        default   => Logger::info('remote_db.' . $event, $context),
    };
}

/** Takes the connection for this worker for $seconds. False when another worker holds it. */
function remote_db_lease(int $connectionId, int $seconds = 300): bool {
    $st = db()->prepare(
        'UPDATE ellsms_remote_db_connections
            SET lease_owner = ?, lease_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
          WHERE id = ? AND (lease_until IS NULL OR lease_until < NOW() OR lease_owner = ?)'
    );
    $st->execute([worker_id(), $seconds, $connectionId, worker_id()]);
    return $st->rowCount() > 0;
}

function remote_db_release(int $connectionId): void {
    db()->prepare('UPDATE ellsms_remote_db_connections SET lease_owner = NULL, lease_until = NULL WHERE id = ? AND lease_owner = ?')
        ->execute([$connectionId, worker_id()]);
}

/* ==========================================================================
   Connections to the customer database (cached per process)
   ========================================================================== */

/** The open PDO for a connection, reused across cycles while its configuration is unchanged. */
function remote_db_pdo(array $conn): PDO {
    $key = (int)$conn['id'] . ':' . (int)$conn['config_version'];
    $cache = $GLOBALS['__remote_db_pdo'] ?? [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    // A config change (new version) replaces the old handle rather than keeping both.
    foreach (array_keys($cache) as $k) {
        if (str_starts_with((string)$k, (int)$conn['id'] . ':')) unset($cache[$k]);
    }
    $pdo = remote_db_connect($conn, remote_db_decrypt_password($conn));
    $cache[$key] = $pdo;
    $GLOBALS['__remote_db_pdo'] = $cache;
    return $pdo;
}

function remote_db_pdo_drop(int $connectionId): void {
    foreach (array_keys($GLOBALS['__remote_db_pdo'] ?? []) as $k) {
        if (str_starts_with((string)$k, $connectionId . ':')) unset($GLOBALS['__remote_db_pdo'][$k]);
    }
}

/**
 * Checks a configuration end to end without changing anything: connects, then asks for zero rows
 * of every mapped column. "SELECT cols FROM t WHERE 1=0" is the one probe all three engines answer
 * identically, and it proves the table AND each column exist with the given login's rights.
 *
 * @return array{ok: bool, message: string, checks: list<array{0: string, 1: bool, 2: string}>}
 */
function remote_db_test(array $conn, ?string $password = null): array {
    $checks = [];
    try {
        $pdo = remote_db_connect($conn, $password ?? remote_db_decrypt_password($conn));
        $checks[] = ['اتصال به دیتابیس', true, (string)($pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?? '')];
    } catch (Throwable $e) {
        $msg = $e instanceof RemoteDbException ? $e->getMessage() : remote_db_safe_error($e, $password);
        return ['ok' => false, 'message' => 'اتصال برقرار نشد: ' . $msg, 'checks' => [['اتصال به دیتابیس', false, $msg]]];
    }
    $driver = (string)$conn['driver'];
    $probe = static function (string $label, string $table, array $columns) use ($pdo, $driver, &$checks): bool {
        try {
            $cols = implode(', ', array_map(static fn(string $c): string => remote_db_quote_identifier($driver, $c), array_values(array_unique($columns))));
            $pdo->query('SELECT ' . $cols . ' FROM ' . remote_db_quote_identifier($driver, $table) . ' WHERE 1 = 0')->fetchAll();
            $checks[] = [$label, true, $table . ': ' . implode('، ', array_values(array_unique($columns)))];
            return true;
        } catch (Throwable $e) {
            $checks[] = [$label, false, remote_db_safe_error($e)];
            return false;
        }
    };
    $out = $conn['outbound'];
    $outColumns = array_values(array_diff_key($out, ['table' => 1, 'pending_mode' => 1, 'pending_value' => 1]));
    $ok = $probe('جدول خروجی و ستون‌ها', (string)$out['table'], $outColumns);
    if (!empty($conn['inbound_enabled']) && !empty($conn['inbound']['table'])) {
        $ok = $probe('جدول دریافتی و ستون‌ها', (string)$conn['inbound']['table'], array_values(array_diff_key($conn['inbound'], ['table' => 1]))) && $ok;
    }
    if ($ok) {
        try {
            $pending = remote_db_pending_count($pdo, $conn);
            $checks[] = ['ردیف‌های در انتظار ارسال', true, to_persian_digits((string)$pending)];
        } catch (Throwable $e) {
            $checks[] = ['ردیف‌های در انتظار ارسال', false, remote_db_safe_error($e)];
            $ok = false;
        }
    }
    return ['ok' => $ok, 'message' => $ok ? 'اتصال و ساختار جدول‌ها درست است.' : 'بعضی بررسی‌ها ناموفق بود.', 'checks' => $checks];
}

/** "status IS NULL" / "status = ?" / both, with its parameters. */
function remote_db_pending_condition(array $conn): array {
    $out = $conn['outbound'];
    $status = remote_db_quote_identifier((string)$conn['driver'], (string)$out['status']);
    return match ((string)($out['pending_mode'] ?? 'null')) {
        'value'         => ["{$status} = ?", [(string)$out['pending_value']]],
        'null_or_value' => ["({$status} IS NULL OR {$status} = ?)", [(string)$out['pending_value']]],
        default         => ["{$status} IS NULL", []],
    };
}

function remote_db_pending_count(PDO $pdo, array $conn): int {
    [$where, $params] = remote_db_pending_condition($conn);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . remote_db_quote_identifier((string)$conn['driver'], (string)$conn['outbound']['table']) . ' WHERE ' . $where);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/* ==========================================================================
   The account the rows are sent as
   ========================================================================== */

/**
 * Re-validated every cycle, like a schedule at execution time: a user whose account, organization
 * or plan stopped allowing sends must not keep sending through their database either.
 *
 * @return array{ok: bool, user?: array, reason?: string}
 */
function remote_db_resolve_user(array $conn): array {
    $owner = backend_find_user_by_id((int)$conn['user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        return ['ok' => false, 'reason' => 'حساب کاربر غیرفعال است یا دسترسی پنل ندارد.'];
    }
    $orgId = isset($conn['organization_id']) && $conn['organization_id'] !== null ? (int)$conn['organization_id'] : null;
    if ($orgId !== null && $orgId > 0) {
        $orgStatus = organization_status($orgId);
        if ($orgStatus !== null && in_array($orgStatus, ['disabled', 'suspended'], true)) {
            return ['ok' => false, 'reason' => 'سازمان کاربر معلق یا غیرفعال است.'];
        }
        if (!organization_subscription_serviceable($orgId)) {
            return ['ok' => false, 'reason' => 'اشتراک سازمان کاربر فعال نیست.'];
        }
        if (!organization_has_entitlement($orgId, Entitlements::BULK_SEND)) {
            return ['ok' => false, 'reason' => 'ارسال انبوه در پلن سازمان کاربر نیست.'];
        }
    }
    return ['ok' => true, 'user' => [
        'id' => (int)$owner['id'],
        'role' => $owner['is_admin'] ? 'admin' : 'user',
        'originator' => $owner['originator'] ?? '',
        'organization_id' => $orgId,
    ]];
}

/* ==========================================================================
   The cycle
   ========================================================================== */

/**
 * One pass over every enabled connection that is due. Returns aggregate counters for the worker log.
 */
function remote_db_run_due(int $maxConnections = 20): array {
    $totals = ['connections' => 0, 'taken' => 0, 'queued' => 0, 'refused' => 0, 'written' => 0, 'inbound' => 0, 'errors' => 0];
    try {
        $ids = db()->query(
            'SELECT id FROM ellsms_remote_db_connections
              WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW())
                AND (lease_until IS NULL OR lease_until < NOW())
              ORDER BY COALESCE(next_run_at, created_at) ASC LIMIT ' . max(1, $maxConnections)
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return $totals; // 2026_10_06_remote_db_connections.sql not applied yet
    }
    foreach ($ids as $id) {
        $id = (int)$id;
        if (!remote_db_lease($id)) continue;
        try {
            $conn = remote_db_connection_load($id);
            if ($conn === null || !(int)$conn['enabled']) continue;
            $stats = remote_db_run_cycle($conn);
            $totals['connections']++;
            foreach (['taken', 'queued', 'refused', 'written', 'inbound'] as $k) $totals[$k] += $stats[$k] ?? 0;
            if (!empty($stats['error'])) $totals['errors']++;
        } catch (Throwable $t) {
            $totals['errors']++;
            Logger::error('remote_db.cycle_crashed', ['connection_id' => $id, 'exception' => $t]);
        } finally {
            remote_db_release($id);
        }
    }
    return $totals;
}

/** One full cycle for one connection; records health and the next due time either way. */
function remote_db_run_cycle(array $conn): array {
    $id = (int)$conn['id'];
    $stats = ['taken' => 0, 'queued' => 0, 'refused' => 0, 'written' => 0, 'inbound' => 0, 'deferred' => 0, 'error' => null];
    $db = db();
    $db->prepare('UPDATE ellsms_remote_db_connections SET last_run_at = NOW() WHERE id = ?')->execute([$id]);

    try {
        $pdo = remote_db_pdo($conn);
        $userCheck = remote_db_resolve_user($conn);
        $sendable = $userCheck['ok'];
        if (!$sendable) {
            remote_db_event_once($id, 'account_blocked', $userCheck['reason']);
        }
        if ($sendable && (int)$conn['send_enabled']) {
            $ingest = remote_db_ingest($pdo, $conn, $userCheck['user']);
            $recover = remote_db_recover($pdo, $conn, $userCheck['user']);
            foreach (['taken', 'queued', 'refused', 'deferred'] as $k) $stats[$k] += ($ingest[$k] ?? 0) + ($recover[$k] ?? 0);
        }
        $syncDue = $conn['next_status_sync_at'] === null || strtotime((string)$conn['next_status_sync_at']) <= time() || $stats['refused'] > 0;
        if ((int)$conn['status_writeback'] && $syncDue) {
            $stats['written'] = remote_db_status_sync($pdo, $conn);
            $db->prepare('UPDATE ellsms_remote_db_connections SET next_status_sync_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?')
               ->execute([max(5, (int)$conn['status_sync_interval_s']), $id]);
        }
        if ((int)$conn['inbound_enabled'] && $userCheck['ok']) {
            $stats['inbound'] = remote_db_inbound_sync($pdo, $conn, $userCheck['user']);
        }
    } catch (Throwable $e) {
        $message = $e instanceof RemoteDbException ? $e->getMessage() : remote_db_safe_error($e);
        $stats['error'] = $message;
        if (!($e instanceof RemoteDbException) || remote_db_is_connection_error($e)) {
            remote_db_pdo_drop($id);
        }
        $failures = (int)$conn['consecutive_failures'] + 1;
        // 10s, 20s, 40s … capped at 5 minutes: a database that is down is retried, not hammered.
        $delay = min(300, max(10, (int)$conn['poll_interval_s']) * (2 ** min(5, $failures - 1)));
        $db->prepare(
            'UPDATE ellsms_remote_db_connections
                SET last_error = ?, last_error_at = NOW(), consecutive_failures = consecutive_failures + 1,
                    next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE id = ?'
        )->execute([mb_strimwidth($message, 0, 480, '…'), $delay, $id]);
        remote_db_event($id, 'error', 'cycle_failed', $message);
        Metrics::increment('remote_db.cycle_failed', 1);
        return $stats;
    }

    // A full batch means there is probably more waiting: come back right away instead of after the interval.
    $next = ($stats['taken'] >= max(1, (int)$conn['batch_size'])) ? 1 : max(2, (int)$conn['poll_interval_s']);
    if ($stats['deferred'] > 0 && $stats['queued'] === 0) {
        $next = max($next, 60); // nothing could be queued (no credit / pricing): don't spin on it
    }
    $db->prepare(
        'UPDATE ellsms_remote_db_connections
            SET last_success_at = NOW(), consecutive_failures = 0, next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                last_error = IF(? = 1, last_error, NULL)
          WHERE id = ?'
    )->execute([$next, $stats['deferred'] > 0 ? 1 : 0, $id]);
    Metrics::increment('remote_db.rows_taken', $stats['taken']);
    Metrics::increment('remote_db.rows_written', $stats['written']);
    return $stats;
}

/** An event that would otherwise repeat every cycle is logged at most once per 10 minutes. */
function remote_db_event_once(int $connectionId, string $event, string $detail, string $level = 'warning'): void {
    $st = db()->prepare('SELECT COUNT(*) FROM ellsms_remote_db_events WHERE connection_id = ? AND event = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $st->execute([$connectionId, $event]);
    if ((int)$st->fetchColumn() === 0) {
        remote_db_event($connectionId, $level, $event, $detail);
    }
    db()->prepare('UPDATE ellsms_remote_db_connections SET last_error = ?, last_error_at = NOW() WHERE id = ?')
        ->execute([mb_strimwidth($detail, 0, 480, '…'), $connectionId]);
}

/* ==========================================================================
   1. Ingest
   ========================================================================== */

function remote_db_ingest(PDO $pdo, array $conn, array $user): array {
    $stats = ['taken' => 0, 'queued' => 0, 'refused' => 0, 'deferred' => 0];
    $id = (int)$conn['id'];
    $driver = (string)$conn['driver'];
    $out = $conn['outbound'];
    $q = static fn(string $c): string => remote_db_quote_identifier($driver, $c);

    $columns = [$q($out['id']), $q($out['destination']), $q($out['content'])];
    if (!empty($out['originator'])) $columns[] = $q($out['originator']);
    [$where, $params] = remote_db_pending_condition($conn);
    if (!empty($out['due_at'])) {
        $where .= ' AND (' . $q($out['due_at']) . ' IS NULL OR ' . $q($out['due_at']) . ' <= ?)';
        $params[] = remote_db_datetime();
    }
    $order = (!empty($out['priority']) ? $q($out['priority']) . ' ASC, ' : '') . $q($out['id']) . ' ASC';
    $batch = max(1, min(1000, (int)$conn['batch_size']));
    $st = $pdo->prepare(remote_db_select_limited($driver, implode(', ', $columns), $q($out['table']), $where, $order, $batch));
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_NUM);
    if ($rows === []) {
        return $stats;
    }

    // Rows ELLSMS already took (customer put the status back, or a crash between our insert and their
    // update): never queued again. They are re-marked on the customer side so they stop matching the
    // pending filter, and their last state is written again by the next status sync.
    $remoteIds = array_map(static fn(array $r): string => (string)$r[0], $rows);
    $known = remote_db_known_rows($id, $remoteIds);
    $byOriginator = [];
    $newMarks = [];
    $db = db();
    $insert = $db->prepare(
        'INSERT INTO ellsms_remote_db_rows (connection_id, remote_row_id, state, destination, originator, parts, error_code, claimed_at, final)
         VALUES (?,?,?,?,?,?,?,NOW(),0)
         ON DUPLICATE KEY UPDATE id = id'
    );
    foreach ($rows as $r) {
        $remoteId = (string)$r[0];
        if ($remoteId === '' || mb_strlen($remoteId) > 100) continue;
        if (isset($known[$remoteId])) {
            $newMarks[] = $remoteId;
            if ($known[$remoteId]['state'] !== 'claimed') {
                $db->prepare("UPDATE ellsms_remote_db_rows SET written_signature = '', final = 0 WHERE id = ?")->execute([$known[$remoteId]['id']]);
            }
            continue;
        }
        $check = remote_db_check_row($conn, $user, (string)($r[1] ?? ''), (string)($r[2] ?? ''), isset($r[3]) ? (string)$r[3] : null);
        $insert->execute([
            $id, $remoteId, $check['ok'] ? 'claimed' : 'failed', $check['destination'], $check['originator'],
            $check['ok'] ? sms_parts($check['content']) : null, $check['ok'] ? null : $check['error'],
        ]);
        if ($insert->rowCount() !== 1) continue; // raced with another insert of the same row
        $rowId = (int)$db->lastInsertId();
        $stats['taken']++;
        $newMarks[] = $remoteId;
        if ($check['ok']) {
            $byOriginator[$check['originator']][] = ['row_id' => $rowId, 'mobile' => $check['destination'], 'content' => $check['content']];
        } else {
            $stats['refused']++; // inserted as 'failed'; the status sync writes the reason back
        }
    }

    // Mark the customer's rows as taken BEFORE queueing: if this fails, nothing has been sent yet and
    // the rows stay 'claimed' here, so the recovery step queues them later.
    remote_db_mark_in_progress($pdo, $conn, $newMarks);

    foreach ($byOriginator as $originator => $items) {
        $result = remote_db_queue($conn, $user, (string)$originator, $items);
        $stats['queued'] += $result['queued'];
        $stats['refused'] += $result['refused'];
        $stats['deferred'] += $result['deferred'];
    }
    return $stats;
}

/** [remote_row_id => ['id' => …, 'state' => …]] for the ids ELLSMS already holds. */
function remote_db_known_rows(int $connectionId, array $remoteIds): array {
    $known = [];
    foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
        $st = db()->prepare('SELECT id, remote_row_id, state FROM ellsms_remote_db_rows WHERE connection_id = ? AND remote_row_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute(array_merge([$connectionId], $chunk));
        foreach ($st->fetchAll() as $row) {
            $known[(string)$row['remote_row_id']] = ['id' => (int)$row['id'], 'state' => (string)$row['state']];
        }
    }
    return $known;
}

/**
 * Validates one customer row. Everything that would make bulk_queue_job() refuse the WHOLE group is
 * caught here per row, so one bad row never holds back its neighbours.
 *
 * @return array{ok: bool, destination: ?string, originator: ?string, content: string, error: ?string}
 */
function remote_db_check_row(array $conn, array $user, string $destinationRaw, string $content, ?string $originatorRaw): array {
    $destination = normalize_msisdn($destinationRaw);
    $content = trim(str_replace("\r\n", "\n", $content));
    $originator = normalize_originator((string)($originatorRaw ?? ''));
    if ($originator === null) {
        $originator = normalize_originator((string)$conn['default_originator']);
    }
    $fail = static fn(string $code): array => ['ok' => false, 'destination' => $destination, 'originator' => $originator, 'content' => $content, 'error' => $code];
    if ($destination === null) return $fail('invalid_destination');
    if ($content === '') return $fail('empty_content');
    if (mb_strlen($content) > 2000) return $fail('content_too_long');
    if ($originator === null) return $fail('originator_missing');
    if (!can_use_originator($user, $originator)) return $fail('originator_not_allowed');
    if (content_policy_rules() !== [] && content_policy_violation($content) !== null) return $fail('content_prohibited');
    return ['ok' => true, 'destination' => $destination, 'originator' => $originator, 'content' => $content, 'error' => null];
}

/** Sets the customer rows to the in-progress value, in chunks (SQL Server allows 2100 parameters). */
function remote_db_mark_in_progress(PDO $pdo, array $conn, array $remoteIds): void {
    if ($remoteIds === []) return;
    $driver = (string)$conn['driver'];
    $out = $conn['outbound'];
    $q = static fn(string $c): string => remote_db_quote_identifier($driver, $c);
    $set = $q($out['status']) . ' = ?';
    $setParams = [(string)$conn['status_values']['in_progress']];
    if (!empty($out['updated_at'])) {
        $set .= ', ' . $q($out['updated_at']) . ' = ?';
        $setParams[] = remote_db_datetime();
    }
    $pdo->beginTransaction();
    try {
        foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
            $st = $pdo->prepare('UPDATE ' . $q($out['table']) . ' SET ' . $set . ' WHERE ' . $q($out['id']) . ' IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $st->execute(array_merge($setParams, $chunk));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Queues one sender line's rows as one bulk job, each item carrying source_ref = its row id, then
 * links the rows to the items that transaction created. A refused group is retried later (no credit,
 * quota, pricing) — those rows stay 'claimed' with a backoff and are never failed for a reason the
 * customer can fix by topping up.
 *
 * @param list<array{row_id: int, mobile: string, content: string}> $items
 * @return array{queued: int, refused: int, deferred: int}
 */
function remote_db_queue(array $conn, array $user, string $originator, array $items): array {
    $stats = ['queued' => 0, 'refused' => 0, 'deferred' => 0];
    if ($items === []) return $stats;
    $db = db();
    $rowIds = array_map(static fn(array $i): int => (int)$i['row_id'], $items);
    $queueItems = array_map(static fn(array $i): array => ['mobile' => $i['mobile'], 'content' => $i['content'], 'source_ref' => (int)$i['row_id']], $items);
    $title = mb_substr('دیتابیس مشتری: ' . (string)$conn['name'], 0, 160);
    $class = in_array((string)$conn['message_class'], [MESSAGE_CLASS_ADVERTISING, MESSAGE_CLASS_BULK_CAMPAIGN], true) ? (string)$conn['message_class'] : MESSAGE_CLASS_BULK_CAMPAIGN;

    [$ok, $info, $jobId, $reason] = array_pad(bulk_queue_job($user, 'p2p', $title, $originator, null, $queueItems, null, null, null, $class), 4, null);
    if ($ok) {
        remote_db_link_items($rowIds);
        $stats['queued'] = count($rowIds);
        return $stats;
    }

    // One unpriced number refuses the whole job; find it rather than holding every row back.
    if ($reason === 'pricing_unavailable' && count($items) > 1) {
        foreach ($items as $item) {
            $one = remote_db_queue($conn, $user, $originator, [$item]);
            foreach ($stats as $k => $_) $stats[$k] += $one[$k];
        }
        return $stats;
    }
    if (in_array($reason, ['insufficient_credit', 'quota_exceeded', 'pricing_unavailable', 'internal_error'], true)) {
        $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
        $db->prepare(
            "UPDATE ellsms_remote_db_rows
                SET queue_attempts = queue_attempts + 1,
                    next_attempt_at = DATE_ADD(NOW(), INTERVAL LEAST(1800, 60 * POW(2, LEAST(queue_attempts, 5))) SECOND),
                    error_code = ?
              WHERE id IN ({$placeholders}) AND state = 'claimed'"
        )->execute(array_merge([(string)$reason], $rowIds));
        remote_db_event_once((int)$conn['id'], 'queue_deferred_' . $reason, (string)$info);
        $stats['deferred'] = count($rowIds);
        return $stats;
    }
    // Anything else is a permanent refusal for these rows (the reason is written back to the customer).
    remote_db_fail_rows($rowIds, (string)($reason ?: 'refused'));
    remote_db_event((int)$conn['id'], 'warning', 'queue_refused', (string)$info);
    $stats['refused'] = count($rowIds);
    return $stats;
}

/** Links claimed rows to the bulk items carrying their id; the one place a row becomes 'queued'. */
function remote_db_link_items(array $rowIds): int {
    if ($rowIds === []) return 0;
    $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
    $st = db()->prepare(
        "UPDATE ellsms_remote_db_rows r
           JOIN ellsms_bulk_items bi ON bi.source_ref = r.id
            SET r.state = 'queued', r.bulk_item_id = bi.id, r.job_id = bi.job_id, r.error_code = NULL, r.next_attempt_at = NULL
          WHERE r.id IN ({$placeholders}) AND r.state = 'claimed'"
    );
    $st->execute($rowIds);
    return $st->rowCount();
}

function remote_db_fail_rows(array $rowIds, string $errorCode): void {
    if ($rowIds === []) return;
    $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
    db()->prepare("UPDATE ellsms_remote_db_rows SET state = 'failed', error_code = ?, written_signature = '', final = 0 WHERE id IN ({$placeholders}) AND state = 'claimed'")
        ->execute(array_merge([mb_substr($errorCode, 0, 60)], $rowIds));
}

/* ==========================================================================
   2. Recover rows taken but not queued
   ========================================================================== */

function remote_db_recover(PDO $pdo, array $conn, array $user): array {
    $stats = ['taken' => 0, 'queued' => 0, 'refused' => 0, 'deferred' => 0];
    $id = (int)$conn['id'];
    $db = db();
    // Grace period so a row inserted moments ago by this same cycle is never "recovered" mid-flight.
    $st = $db->prepare(
        "SELECT id, remote_row_id, claimed_at FROM ellsms_remote_db_rows
          WHERE connection_id = ? AND state = 'claimed'
            AND claimed_at < DATE_SUB(NOW(), INTERVAL 30 SECOND)
            AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
          ORDER BY id LIMIT " . max(1, min(1000, (int)$conn['batch_size']))
    );
    $st->execute([$id]);
    $rows = $st->fetchAll();
    if ($rows === []) return $stats;

    // A previous attempt may have queued them and crashed before linking: link first, never resend.
    $linked = remote_db_link_items(array_map(static fn(array $r): int => (int)$r['id'], $rows));
    $stats['queued'] += $linked;
    if ($linked > 0) {
        $st = $db->prepare("SELECT id FROM ellsms_remote_db_rows WHERE id IN (" . implode(',', array_map(static fn(array $r): int => (int)$r['id'], $rows)) . ") AND state = 'claimed'");
        $st->execute();
        $still = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $rows = array_values(array_filter($rows, static fn(array $r): bool => isset($still[(int)$r['id']])));
    }

    $retryHours = max(1, (int)$conn['retry_hours']);
    $expired = [];
    $retry = [];
    foreach ($rows as $r) {
        if (strtotime((string)$r['claimed_at']) < time() - $retryHours * 3600) $expired[] = (int)$r['id'];
        else $retry[(string)$r['remote_row_id']] = (int)$r['id'];
    }
    if ($expired !== []) {
        remote_db_fail_rows($expired, 'queue_timeout');
        $stats['refused'] += count($expired);
        remote_db_event($id, 'warning', 'queue_timeout', count($expired) . ' ردیف در مهلت ' . $retryHours . ' ساعت به صف نرسید.');
    }
    if ($retry === []) return $stats;

    // The content lives only in the customer's table, so it is read again for exactly these rows.
    $driver = (string)$conn['driver'];
    $out = $conn['outbound'];
    $q = static fn(string $c): string => remote_db_quote_identifier($driver, $c);
    $columns = [$q($out['id']), $q($out['destination']), $q($out['content'])];
    if (!empty($out['originator'])) $columns[] = $q($out['originator']);
    $found = [];
    foreach (array_chunk(array_keys($retry), 500) as $chunk) {
        $sel = $pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM ' . $q($out['table']) . ' WHERE ' . $q($out['id']) . ' IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $sel->execute(array_map('strval', $chunk));
        foreach ($sel->fetchAll(PDO::FETCH_NUM) as $r) $found[(string)$r[0]] = $r;
    }
    $byOriginator = [];
    $missing = [];
    foreach ($retry as $remoteId => $rowId) {
        $r = $found[(string)$remoteId] ?? null;
        if ($r === null) { $missing[] = $rowId; continue; }
        $check = remote_db_check_row($conn, $user, (string)($r[1] ?? ''), (string)($r[2] ?? ''), isset($r[3]) ? (string)$r[3] : null);
        if (!$check['ok']) {
            remote_db_fail_rows([$rowId], (string)$check['error']);
            $stats['refused']++;
            continue;
        }
        $byOriginator[$check['originator']][] = ['row_id' => $rowId, 'mobile' => $check['destination'], 'content' => $check['content']];
    }
    if ($missing !== []) {
        // Deleted on the customer's side: nothing to send and nowhere to write a result.
        $db->prepare("UPDATE ellsms_remote_db_rows SET state = 'failed', error_code = 'row_missing', final = 1 WHERE id IN (" . implode(',', $missing) . ") AND state = 'claimed'")->execute();
    }
    foreach ($byOriginator as $originator => $items) {
        $result = remote_db_queue($conn, $user, (string)$originator, $items);
        foreach (['queued', 'refused', 'deferred'] as $k) $stats[$k] += $result[$k];
    }
    return $stats;
}

/* ==========================================================================
   3. Status write-back
   ========================================================================== */

/**
 * Writes every open row whose state moved since its last write. Walks the open rows with an id
 * cursor in pages of 500 within a time budget, so a connection with a large backlog of messages
 * awaiting delivery reports is still served without one cycle running for minutes.
 */
function remote_db_status_sync(PDO $pdo, array $conn, int $timeBudgetSeconds = 20): int {
    $id = (int)$conn['id'];
    $db = db();
    $written = 0;
    $cursor = 0;
    $started = time();
    $waitSeconds = max(1, (int)$conn['delivery_wait_hours']) * 3600;
    do {
        $st = $db->prepare(
            'SELECT r.id, r.remote_row_id, r.state, r.error_code, r.parts, r.written_signature, r.created_at,
                    bi.id AS item_id, bi.status AS item_status, bi.delivery_status, bi.provider_message_id,
                    bi.error AS item_error, bi.delivered_at
               FROM ellsms_remote_db_rows r
               LEFT JOIN ellsms_bulk_items bi ON bi.id = r.bulk_item_id
              WHERE r.connection_id = ? AND r.final = 0 AND r.id > ?
              ORDER BY r.id LIMIT 500'
        );
        $st->execute([$id, $cursor]);
        $rows = $st->fetchAll();
        if ($rows === []) break;
        $cursor = (int)end($rows)['id'];

        $writes = [];
        $closeOnly = [];
        foreach ($rows as $r) {
            if ($r['state'] === 'queued' && $r['item_id'] === null) {
                continue; // item archived/removed: nothing reliable to report
            }
            [$target, $final] = remote_db_target_state((string)$r['state'], $r['item_status'] !== null ? (string)$r['item_status'] : null, $r['delivery_status'] !== null ? (string)$r['delivery_status'] : null);
            if ($target === null) continue;
            $signature = $r['state'] . '|' . ($r['item_status'] ?? '') . '|' . ($r['delivery_status'] ?? '');
            $tooOld = strtotime((string)$r['created_at']) < time() - $waitSeconds;
            if ($signature === (string)$r['written_signature']) {
                if ($tooOld) $closeOnly[] = (int)$r['id']; // no delivery report will come any more
                continue;
            }
            $writes[] = ['row' => $r, 'target' => $target, 'final' => $final || $tooOld, 'signature' => $signature];
        }
        if ($closeOnly !== []) {
            $db->prepare('UPDATE ellsms_remote_db_rows SET final = 1 WHERE id IN (' . implode(',', $closeOnly) . ')')->execute();
        }
        if ($writes !== []) {
            remote_db_write_statuses($pdo, $conn, $writes);
            db_transaction(static function (PDO $db) use ($writes): void {
                $mark = $db->prepare('UPDATE ellsms_remote_db_rows SET written_signature = ?, written_at = NOW(), final = ?, state = IF(state = \'queued\' AND ? = 1, \'done\', state) WHERE id = ?');
                foreach ($writes as $w) {
                    $mark->execute([$w['signature'], $w['final'] ? 1 : 0, $w['final'] ? 1 : 0, (int)$w['row']['id']]);
                }
            });
            $written += count($writes);
        }
    } while (count($rows) === 500 && time() - $started < $timeBudgetSeconds);
    return $written;
}

/**
 * Applies a set of writes to the customer table in ONE transaction. Only mapped columns are touched;
 * each write sets the status word and whatever of reference / provider id / parts / error /
 * timestamps applies to that state.
 */
function remote_db_write_statuses(PDO $pdo, array $conn, array $writes): void {
    $driver = (string)$conn['driver'];
    $out = $conn['outbound'];
    $values = $conn['status_values'];
    $q = static fn(string $c): string => remote_db_quote_identifier($driver, $c);
    $statements = [];
    $now = remote_db_datetime();
    $pdo->beginTransaction();
    try {
        foreach ($writes as $w) {
            $r = $w['row'];
            $target = $w['target'];
            $set = [$q($out['status']) => (string)$values[$target]];
            if (!empty($out['reference']) && $r['item_id'] !== null) $set[$q($out['reference'])] = (string)$r['item_id'];
            if (!empty($out['provider_id']) && !empty($r['provider_message_id'])) $set[$q($out['provider_id'])] = mb_substr((string)$r['provider_message_id'], 0, 100);
            if (!empty($out['parts']) && $r['parts'] !== null) $set[$q($out['parts'])] = (string)(int)$r['parts'];
            if (!empty($out['error'])) {
                $error = null;
                if ($target === 'failed') {
                    $error = $r['state'] === 'failed' ? remote_db_error_text((string)$r['error_code']) : (string)($r['item_error'] ?? 'ارسال نشد');
                } elseif (in_array($target, ['not_delivered', 'rejected', 'expired'], true)) {
                    $error = REMOTE_DB_STATUS_KEYS[$target];
                }
                if ($error !== null) $set[$q($out['error'])] = mb_substr($error, 0, 250);
            }
            if (!empty($out['sent_at']) && $r['item_status'] === 'sent' && ($r['written_signature'] === '' || !str_contains((string)$r['written_signature'], '|sent|'))) {
                $set[$q($out['sent_at'])] = $now;
            }
            if (!empty($out['delivered_at']) && $target === 'delivered') {
                $set[$q($out['delivered_at'])] = $r['delivered_at'] ? remote_db_datetime((int)strtotime((string)$r['delivered_at'])) : $now;
            }
            if (!empty($out['updated_at'])) $set[$q($out['updated_at'])] = $now;

            $key = implode(',', array_keys($set));
            $statements[$key] ??= $pdo->prepare(
                'UPDATE ' . $q($out['table']) . ' SET ' . implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($set)))
                . ' WHERE ' . $q($out['id']) . ' = ?'
            );
            $statements[$key]->execute(array_merge(array_values($set), [(string)$r['remote_row_id']]));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ==========================================================================
   4. Inbound write-back
   ========================================================================== */

/**
 * Inserts messages received on the user's OWN lines (the same set their inbox shows — not lines only
 * shared with them for sending) into the customer's inbound table. Two cursors, one per store, both
 * advanced only after the customer's commit.
 */
function remote_db_inbound_sync(PDO $pdo, array $conn, array $user, int $limit = 200): int {
    $map = $conn['inbound'];
    if (empty($map['table'])) return 0;
    $lines = allowed_originators($user);
    if ($lines === [] || in_array('*', $lines, true)) return 0; // an admin account has no "own" lines
    $lines = array_values(array_unique(array_map('strval', $lines)));
    $db = db();
    $inserted = 0;
    $placeholders = implode(',', array_fill(0, count($lines), '?'));
    $sources = [
        ['cursor' => 'inbound_backend_cursor', 'sql' => "SELECT id, originator, destination, content, received_at FROM inbound_message WHERE id > ? AND destination IN ({$placeholders}) ORDER BY id LIMIT {$limit}"],
    ];
    if (inbound_ellsms_store_available()) {
        $sources[] = ['cursor' => 'inbound_ellsms_cursor', 'sql' => "SELECT id, originator, destination, content, received_at FROM ellsms_inbound_messages WHERE id > ? AND destination IN ({$placeholders}) ORDER BY id LIMIT {$limit}"];
    }
    foreach ($sources as $source) {
        $st = $db->prepare($source['sql']);
        $st->execute(array_merge([(int)$conn[$source['cursor']]], $lines));
        $messages = $st->fetchAll();
        if ($messages === []) continue;
        $inserted += remote_db_insert_inbound($pdo, $conn, $messages);
        $db->prepare("UPDATE ellsms_remote_db_connections SET {$source['cursor']} = GREATEST({$source['cursor']}, ?) WHERE id = ?")
           ->execute([(int)end($messages)['id'], (int)$conn['id']]);
    }
    return $inserted;
}

function remote_db_insert_inbound(PDO $pdo, array $conn, array $messages): int {
    $driver = (string)$conn['driver'];
    $map = $conn['inbound'];
    $q = static fn(string $c): string => remote_db_quote_identifier($driver, $c);
    $already = [];
    if (!empty($map['external_id'])) {
        $ids = array_map(static fn(array $m): string => (string)$m['id'], $messages);
        $st = $pdo->prepare('SELECT ' . $q($map['external_id']) . ' FROM ' . $q($map['table']) . ' WHERE ' . $q($map['external_id']) . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $already[(string)$v] = true;
    }
    $columns = [$q($map['originator']), $q($map['destination']), $q($map['content'])];
    if (!empty($map['received_at'])) $columns[] = $q($map['received_at']);
    if (!empty($map['external_id'])) $columns[] = $q($map['external_id']);
    $insert = $pdo->prepare('INSERT INTO ' . $q($map['table']) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    $count = 0;
    $pdo->beginTransaction();
    try {
        foreach ($messages as $m) {
            if (isset($already[(string)$m['id']])) continue;
            $values = [(string)$m['originator'], (string)$m['destination'], (string)($m['content'] ?? '')];
            if (!empty($map['received_at'])) $values[] = remote_db_datetime((int)strtotime((string)$m['received_at']));
            if (!empty($map['external_id'])) $values[] = (string)$m['id'];
            $insert->execute($values);
            $count++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $count;
}

/* ==========================================================================
   Panel helpers
   ========================================================================== */

/** Counters for the panel: rows by outcome since $since (default: today). */
function remote_db_stats(int $connectionId, ?string $since = null): array {
    $since ??= date('Y-m-d 00:00:00');
    $st = db()->prepare(
        "SELECT
            COUNT(*) AS taken,
            SUM(r.state = 'claimed') AS waiting,
            SUM(r.state = 'failed') AS refused,
            SUM(bi.status IN ('pending','processing')) AS in_queue,
            SUM(bi.status = 'sent') AS sent,
            SUM(bi.status IN ('failed','cancelled')) AS send_failed,
            SUM(bi.delivery_status = 'delivered') AS delivered,
            SUM(bi.delivery_status IN ('failed','rejected','expired')) AS not_delivered
           FROM ellsms_remote_db_rows r
           LEFT JOIN ellsms_bulk_items bi ON bi.id = r.bulk_item_id
          WHERE r.connection_id = ? AND r.created_at >= ?"
    );
    $st->execute([$connectionId, $since]);
    return array_map('intval', $st->fetch() ?: []);
}

/** Sets both inbound cursors to "now" so enabling inbound does not dump the whole history. */
function remote_db_inbound_cursors_to_now(int $connectionId): void {
    $db = db();
    $backendMax = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM inbound_message')->fetchColumn();
    $ellsmsMax = inbound_ellsms_store_available() ? (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM ellsms_inbound_messages')->fetchColumn() : 0;
    $db->prepare('UPDATE ellsms_remote_db_connections SET inbound_backend_cursor = ?, inbound_ellsms_cursor = ? WHERE id = ?')
       ->execute([$backendMax, $ellsmsMax, $connectionId]);
}

/** Old operational events are pruned (30 days); the rows table is kept as the send record. */
function remote_db_prune_events(int $days = 30): int {
    $st = db()->prepare('DELETE FROM ellsms_remote_db_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000');
    $st->execute([max(1, $days)]);
    return $st->rowCount();
}
