<?php
/**
 * ELLSMS — delivery-based billing (ارسال بر اساس تحویل), docs/delivery-based-billing.md.
 *
 * An admin switches it on per user (Users → ارسال بر اساس تحویل). While it is on, a message that
 * user sends through the gateway transport and whose FINAL delivery report is failed / rejected /
 * expired is refunded: refund_percent of what that one message was charged goes back to the wallet
 * as an append-only `delivery_refund` ledger credit. A message with no final report is never
 * refunded — "we never heard back" is not proof it was not delivered.
 *
 * WHICH PERCENTAGE. The one in force when the message was SENT (ellsms_delivery_billing_periods),
 * not when its report arrives. Reports arrive minutes or hours later; turning the service off or
 * changing the percentage in between must not change what a message already in flight was sold as.
 *
 * WHERE IT FIRES. gateway_status_record() calls delivery_refund_on_terminal() at the one UPDATE that
 * moves a row into a final state, so polling, provider callbacks and SMPP receipts all refund through
 * the same path. delivery_refund_sweep() (scheduler job `delivery-refunds`) catches anything that
 * path missed: a crash between the status write and the refund, or a report that arrived before a
 * direct send's per-destination charge was written.
 *
 * EXACTLY ONCE. ellsms_delivery_refunds has UNIQUE(source, source_id) and is written in the same
 * transaction as the wallet credit, whose idempotency key is delivery_refund:{source}:{id}. A
 * concurrent hook and sweep therefore serialize on the unique key and the loser changes nothing.
 *
 * Legacy-transport sends (SMS_GATEWAY_TRANSPORT=0) are out of scope: their rows live in the
 * backend's outbound_message with no per-message link to what ELLSMS charged.
 */

declare(strict_types=1);

/** Final delivery states that count as "not delivered" for a refund. */
const DELIVERY_REFUND_STATES = ['failed', 'rejected', 'expired'];

/** Wallet ledger type of a delivery refund credit. */
const DELIVERY_REFUND_LEDGER_TYPE = 'delivery_refund';

/** Whether 2026_10_24_delivery_based_billing.sql has run. $set is for tests. */
function delivery_billing_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) {
        return $available = $set;
    }
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_delivery_refunds LIMIT 0');
            $available = true;
        } catch (PDOException) {
            $available = false;
        }
    }
    return $available;
}

/** How far back the sweep looks for unrefunded reports, in days. */
function delivery_refund_lookback_days(): int {
    return max(1, (int)(env('DELIVERY_REFUND_LOOKBACK_DAYS', '7') ?? '7'));
}

/** Most rows one sweep pass decides, per source. */
function delivery_refund_sweep_batch(): int {
    return max(1, (int)(env('DELIVERY_REFUND_SWEEP_BATCH', '500') ?? '500'));
}

/* ==========================================================================
   Policy (admin switch)
   ========================================================================== */

/** @return array{enabled:bool, refund_percent:int, enabled_since:?string} */
function delivery_billing_policy(int $userId): array {
    $policy = ['enabled' => false, 'refund_percent' => 100, 'enabled_since' => null];
    if (!delivery_billing_available()) {
        return $policy;
    }
    $st = db()->prepare('SELECT enabled, refund_percent FROM ellsms_delivery_billing_policies WHERE user_id = ?');
    $st->execute([$userId]);
    if ($row = $st->fetch()) {
        $policy['enabled'] = (bool)$row['enabled'];
        $policy['refund_percent'] = (int)$row['refund_percent'];
    }
    if ($policy['enabled']) {
        $open = db()->prepare('SELECT started_at FROM ellsms_delivery_billing_periods WHERE user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
        $open->execute([$userId]);
        $policy['enabled_since'] = ($since = $open->fetchColumn()) !== false ? (string)$since : null;
    }
    return $policy;
}

/** Parses the admin's percentage input (Persian digits allowed). Null when it is not 1..100. */
function delivery_billing_parse_percent(mixed $raw): ?int {
    $text = trim(from_persian_digits((string)$raw));
    if (!preg_match('/^\d{1,3}$/D', $text)) {
        return null;
    }
    $percent = (int)$text;
    return $percent >= 1 && $percent <= 100 ? $percent : null;
}

/**
 * Turns the service on or off for one user, or changes its percentage.
 *
 * Every change closes the open period and (when on) opens a new one, so each period carries exactly
 * one percentage. Saving the settings that are already in force is a no-op and keeps the period.
 *
 * @return array{ok:bool, reason:string}
 */
function delivery_billing_save(int $userId, bool $enabled, int $refundPercent, int $actorUserId): array {
    if (!delivery_billing_available()) {
        return ['ok' => false, 'reason' => 'not_migrated'];
    }
    if ($refundPercent < 1 || $refundPercent > 100) {
        return ['ok' => false, 'reason' => 'invalid_percent'];
    }

    $changed = db_transaction(function (PDO $db) use ($userId, $enabled, $refundPercent, $actorUserId): bool {
        $db->prepare('INSERT INTO ellsms_delivery_billing_policies (user_id, enabled, refund_percent) VALUES (?, 0, 100)
                      ON DUPLICATE KEY UPDATE user_id = user_id')->execute([$userId]);
        $lock = $db->prepare('SELECT enabled, refund_percent FROM ellsms_delivery_billing_policies WHERE user_id = ? FOR UPDATE');
        $lock->execute([$userId]);
        $current = $lock->fetch();

        if ((bool)$current['enabled'] === $enabled && (!$enabled || (int)$current['refund_percent'] === $refundPercent)) {
            // Re-saving the same state. The percentage of a disabled policy is still stored, so the
            // form shows it next time, but nothing is in force and no period moves.
            if (!$enabled && (int)$current['refund_percent'] !== $refundPercent) {
                $db->prepare('UPDATE ellsms_delivery_billing_policies SET refund_percent = ?, updated_by_user_id = ? WHERE user_id = ?')
                   ->execute([$refundPercent, $actorUserId, $userId]);
            }
            return false;
        }

        $db->prepare('UPDATE ellsms_delivery_billing_periods SET ended_at = NOW(), ended_by_user_id = ? WHERE user_id = ? AND ended_at IS NULL')
           ->execute([$actorUserId, $userId]);
        if ($enabled) {
            $db->prepare('INSERT INTO ellsms_delivery_billing_periods (user_id, refund_percent, started_at, started_by_user_id) VALUES (?, ?, NOW(), ?)')
               ->execute([$userId, $refundPercent, $actorUserId]);
        }
        $db->prepare('UPDATE ellsms_delivery_billing_policies SET enabled = ?, refund_percent = ?, updated_by_user_id = ? WHERE user_id = ?')
           ->execute([$enabled ? 1 : 0, $refundPercent, $actorUserId, $userId]);
        return true;
    });

    if ($changed) {
        audit($actorUserId, 'user.delivery_billing', "#{$userId} " . ($enabled ? "on {$refundPercent}%" : 'off'));
        Logger::info('delivery_billing.policy_saved', ['actor_id' => $actorUserId, 'target_id' => $userId, 'enabled' => $enabled, 'refund_percent' => $refundPercent]);
    }
    return ['ok' => true, 'reason' => $changed ? 'saved' : 'unchanged'];
}

/** Newest first. @return list<array> */
function delivery_billing_periods(int $userId, int $limit = 20): array {
    if (!delivery_billing_available()) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM ellsms_delivery_billing_periods WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $st->execute([$userId]);
    return $st->fetchAll();
}

function delivery_billing_error_message(string $reason): string {
    return match ($reason) {
        'not_migrated'    => 'جدول‌های ارسال بر اساس تحویل هنوز ساخته نشده‌اند؛ مهاجرت 2026_10_24_delivery_based_billing.sql را اجرا کنید.',
        'invalid_percent' => 'درصد بازگشت باید عددی بین ۱ تا ۱۰۰ باشد.',
        default           => 'ذخیره‌ی تنظیمات ارسال بر اساس تحویل ممکن نشد.',
    };
}

/* ==========================================================================
   Charges
   ========================================================================== */

/**
 * Records what each accepted destination of a direct / scheduled / API send actually cost, on the
 * attempt row the gateway transport wrote for it.
 *
 * Called right after the send's wallet commit, with the same accepted prices that commit summed, so
 * the per-destination amounts add up to the ledger row by construction. A destination with no
 * attempt row (legacy transport, a provider that returned no message id) or no accepted price is
 * skipped and so can never be refunded — never guessed. If the shares would add up to more than was
 * committed (sms_pricing_settlement()'s degenerate fallback, when the sent list did not match the
 * priced one), nothing is recorded: refunding more than was charged is worse than not refunding.
 *
 * @param array<string,int> $attemptIds destination => ellsms_message_attempts.id (dispatch meta)
 */
function delivery_billing_record_attempt_charges(array $priced, array $attemptIds, int $committed): void {
    if ($attemptIds === [] || !delivery_billing_available()) {
        return;
    }
    $perMobile = $priced['per_mobile'] ?? [];
    $cases = [];
    $params = [];
    $ids = [];
    $total = 0;
    foreach ($attemptIds as $destination => $attemptId) {
        $entry = $perMobile[(string)$destination] ?? $perMobile[(string)(normalize_msisdn((string)$destination) ?? '')] ?? null;
        if ($entry === null || (int)$entry['cost'] <= 0) {
            continue;
        }
        $cases[] = 'WHEN ? THEN ?';
        array_push($params, (int)$attemptId, (int)$entry['cost']);
        $ids[] = (int)$attemptId;
        $total += (int)$entry['cost'];
    }
    if ($ids === []) {
        return;
    }
    if ($total > $committed) {
        Logger::warning('delivery_billing.charge_exceeds_commit', ['attempts' => count($ids), 'shares' => $total, 'committed' => $committed]);
        return;
    }
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare('UPDATE ellsms_message_attempts SET charged_credits = CASE id ' . implode(' ', $cases) . " END
                       WHERE id IN ({$in}) AND charged_credits IS NULL")
            ->execute(array_merge($params, $ids));
    } catch (Throwable $t) {
        // An accounting annotation: failing to write it must not fail a send whose money already moved.
        Logger::warning('delivery_billing.charge_record_failed', ['attempts' => count($ids), 'exception' => $t]);
    }
}

/* ==========================================================================
   Refunds
   ========================================================================== */

/**
 * Loads what a refund decision needs about one row: owner, final state, when it was sent and what
 * it was charged. Null when the row does not exist.
 *
 * @return ?array{user_id:int, organization_id:?int, delivery_status:?string, sendable:bool, sent_at:string, charged:?int}
 */
function delivery_refund_row(PDO $db, string $source, int $rowId): ?array {
    if ($source === 'attempt') {
        $st = $db->prepare('SELECT user_id, organization_id, status, delivery_status, attempted_at, charged_credits
                            FROM ellsms_message_attempts WHERE id = ?');
        $st->execute([$rowId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        return [
            'user_id' => (int)$row['user_id'],
            'organization_id' => $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
            'delivery_status' => $row['delivery_status'] !== null ? (string)$row['delivery_status'] : null,
            'sendable' => $row['status'] === 'accepted',
            'sent_at' => (string)$row['attempted_at'],
            'charged' => $row['charged_credits'] !== null ? (int)$row['charged_credits'] : null,
        ];
    }

    $st = $db->prepare('SELECT bj.user_id, bj.organization_id, bi.status, bi.delivery_status, bi.created_at
                        FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id WHERE bi.id = ?');
    $st->execute([$rowId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    // A bulk item's own commit is one ledger row with its own idempotency key
    // (bulk_finalize_item() / bulk_flush_deferred_settlement()), so the ledger IS the charge. No row
    // means the item was never charged (an admin's job, an exempt send).
    $charge = $db->prepare("SELECT amount FROM ellsms_wallet_transactions WHERE idempotency_key = ? AND type = 'sms_debit'");
    $charge->execute(['commit:bulk_item:' . $rowId]);
    $amount = $charge->fetchColumn();
    return [
        'user_id' => (int)$row['user_id'],
        'organization_id' => $row['organization_id'] !== null ? (int)$row['organization_id'] : null,
        'delivery_status' => $row['delivery_status'] !== null ? (string)$row['delivery_status'] : null,
        'sendable' => $row['status'] === 'sent',
        'sent_at' => (string)$row['created_at'],
        'charged' => $amount !== false ? abs((int)$amount) : 0,
    ];
}

/** The delivery-billing period that covered $sentAt for this user, if any. */
function delivery_billing_period_at(PDO $db, int $userId, string $sentAt): ?array {
    $st = $db->prepare('SELECT id, refund_percent FROM ellsms_delivery_billing_periods
                        WHERE user_id = ? AND started_at <= ? AND (ended_at IS NULL OR ended_at > ?)
                        ORDER BY id DESC LIMIT 1');
    $st->execute([$userId, $sentAt, $sentAt]);
    return $st->fetch() ?: null;
}

/** refund_percent of $charged, rounded down to whole Rials. */
function delivery_refund_amount(int $charged, int $refundPercent): int {
    return intdiv(max(0, $charged) * max(0, min(100, $refundPercent)), 100);
}

/**
 * Decides and, when due, performs the refund for one row.
 *
 * Reasons: refunded, already_refunded, not_migrated, not_found, not_final (no failed/rejected/expired
 * report), not_enabled (no period covered the send), not_charged, charge_pending (a direct send's
 * per-destination charge is not written yet — the sweep retries it).
 *
 * @return array{ok:bool, reason:string, amount:int}
 */
function delivery_refund_apply(string $source, int $rowId): array {
    if (!delivery_billing_available()) {
        return ['ok' => false, 'reason' => 'not_migrated', 'amount' => 0];
    }
    $source = $source === 'attempt' ? 'attempt' : 'bulk_item';
    $db = db();

    $row = delivery_refund_row($db, $source, $rowId);
    if ($row === null) {
        return ['ok' => false, 'reason' => 'not_found', 'amount' => 0];
    }
    if (!$row['sendable'] || !in_array($row['delivery_status'], DELIVERY_REFUND_STATES, true)) {
        return ['ok' => false, 'reason' => 'not_final', 'amount' => 0];
    }
    $period = delivery_billing_period_at($db, $row['user_id'], $row['sent_at']);
    if ($period === null) {
        return ['ok' => false, 'reason' => 'not_enabled', 'amount' => 0];
    }
    if ($row['charged'] === null) {
        return ['ok' => false, 'reason' => 'charge_pending', 'amount' => 0];
    }
    if ($row['charged'] <= 0) {
        return ['ok' => false, 'reason' => 'not_charged', 'amount' => 0];
    }

    $percent = (int)$period['refund_percent'];
    $amount = delivery_refund_amount($row['charged'], $percent);

    return db_transaction(function (PDO $db) use ($source, $rowId, $row, $period, $percent, $amount): array {
        // The unique key is the claim: a concurrent hook and sweep both reach this INSERT, the second
        // waits on the first's key lock and then matches zero rows.
        $claim = $db->prepare(
            'INSERT INTO ellsms_delivery_refunds
                (user_id, organization_id, source, source_id, period_id, delivery_status, charged_amount, refund_percent, refund_amount)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $claim->execute([
            $row['user_id'], $row['organization_id'], $source, $rowId, (int)$period['id'],
            (string)$row['delivery_status'], $row['charged'], $percent, $amount,
        ]);
        if ($claim->rowCount() === 0) {
            return ['ok' => true, 'reason' => 'already_refunded', 'amount' => 0];
        }

        wallet_credit(
            $row['user_id'], $amount, DELIVERY_REFUND_LEDGER_TYPE, $source, (string)$rowId,
            "delivery_refund:{$source}:{$rowId}", null,
            ['delivery_status' => $row['delivery_status'], 'charged' => $row['charged'], 'refund_percent' => $percent]
        );
        Logger::info('delivery_billing.refunded', [
            'source' => $source, 'row_id' => $rowId, 'user_id' => $row['user_id'],
            'state' => $row['delivery_status'], 'charged' => $row['charged'], 'amount' => $amount,
        ]);
        Metrics::increment('delivery_refunds', 1, ['state' => (string)$row['delivery_status']]);
        return ['ok' => true, 'reason' => 'refunded', 'amount' => $amount];
    });
}

/**
 * Called by gateway_status_record() right after it moved a row into terminal state $state.
 * Never throws: a refund problem must not undo or fail the status write. The sweep retries it.
 */
function delivery_refund_on_terminal(string $source, int $rowId, string $state): void {
    if (!in_array($state, DELIVERY_REFUND_STATES, true)) {
        return;
    }
    try {
        delivery_refund_apply($source, $rowId);
    } catch (Throwable $t) {
        Logger::warning('delivery_billing.refund_failed', ['source' => $source, 'row_id' => $rowId, 'exception' => $t]);
    }
}

/**
 * Refunds every not-yet-decided failed/rejected/expired message sent under a delivery-billing
 * period within the lookback window. Driven from the periods table, so the cost is proportional to
 * the users who have the service, not to the whole message history.
 *
 * @return array{candidates:int, refunded:int, amount:int, skipped:int, errors:int}
 */
function delivery_refund_sweep(bool $dryRun = false): array {
    $stats = ['candidates' => 0, 'refunded' => 0, 'amount' => 0, 'skipped' => 0, 'errors' => 0];
    if (!delivery_billing_available()) {
        return $stats;
    }
    $db = db();
    // The database's clock, the same one every created_at/attempted_at below was written with.
    $since = (string)$db->query('SELECT DATE_SUB(NOW(), INTERVAL ' . delivery_refund_lookback_days() . ' DAY)')->fetchColumn();
    $limit = delivery_refund_sweep_batch();
    $states = "'" . implode("','", DELIVERY_REFUND_STATES) . "'";

    $bulk = $db->prepare(
        "SELECT bi.id
         FROM ellsms_delivery_billing_periods p
         JOIN ellsms_bulk_jobs bj ON bj.user_id = p.user_id
         JOIN ellsms_bulk_items bi ON bi.job_id = bj.id
         JOIN ellsms_wallet_transactions t ON t.idempotency_key = CONCAT('commit:bulk_item:', bi.id)
         LEFT JOIN ellsms_delivery_refunds r ON r.source = 'bulk_item' AND r.source_id = bi.id
         WHERE (p.ended_at IS NULL OR p.ended_at > ?)
           AND bi.status = 'sent' AND bi.delivery_status IN ({$states})
           AND bi.created_at >= ? AND bi.created_at >= p.started_at
           AND (p.ended_at IS NULL OR bi.created_at < p.ended_at)
           AND r.id IS NULL
         ORDER BY bi.id LIMIT {$limit}"
    );
    $bulk->execute([$since, $since]);
    $candidates = array_map(static fn($id): array => ['bulk_item', (int)$id], $bulk->fetchAll(PDO::FETCH_COLUMN));

    $attempts = $db->prepare(
        "SELECT ma.id
         FROM ellsms_delivery_billing_periods p
         JOIN ellsms_message_attempts ma ON ma.user_id = p.user_id
         LEFT JOIN ellsms_delivery_refunds r ON r.source = 'attempt' AND r.source_id = ma.id
         WHERE (p.ended_at IS NULL OR p.ended_at > ?)
           AND ma.status = 'accepted' AND ma.delivery_status IN ({$states}) AND ma.charged_credits > 0
           AND ma.attempted_at >= ? AND ma.attempted_at >= p.started_at
           AND (p.ended_at IS NULL OR ma.attempted_at < p.ended_at)
           AND r.id IS NULL
         ORDER BY ma.id LIMIT {$limit}"
    );
    $attempts->execute([$since, $since]);
    foreach ($attempts->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $candidates[] = ['attempt', (int)$id];
    }

    $stats['candidates'] = count($candidates);
    if ($dryRun) {
        return $stats;
    }
    foreach ($candidates as [$source, $id]) {
        try {
            $result = delivery_refund_apply($source, $id);
        } catch (Throwable $t) {
            $stats['errors']++;
            Logger::warning('delivery_billing.sweep_row_failed', ['source' => $source, 'row_id' => $id, 'exception' => $t]);
            continue;
        }
        if ($result['reason'] === 'refunded') {
            $stats['refunded']++;
            $stats['amount'] += $result['amount'];
        } else {
            $stats['skipped']++;
        }
    }
    Logger::info('delivery_billing.sweep_completed', $stats);
    return $stats;
}

/* ==========================================================================
   Reporting
   ========================================================================== */

/** @return array{count:int, amount:int} all refunds this user ever received */
function delivery_refund_totals(int $userId): array {
    if (!delivery_billing_available()) {
        return ['count' => 0, 'amount' => 0];
    }
    $st = db()->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(refund_amount), 0) AS a FROM ellsms_delivery_refunds WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    return ['count' => (int)$row['c'], 'amount' => (int)$row['a']];
}

/**
 * Newest refunds with the recipient each one was for. An aimed-bulk recipient belongs to the
 * platform's number bank and is masked for the customer, exactly as everywhere else.
 *
 * @return list<array{created_at:string, source:string, source_id:int, job_id:?int, destination:string, delivery_status:string, charged_amount:int, refund_percent:int, refund_amount:int}>
 */
function delivery_refunds_recent(int $userId, int $limit = 20): array {
    if (!delivery_billing_available()) {
        return [];
    }
    $st = db()->prepare(
        'SELECT r.created_at, r.source, r.source_id, r.delivery_status, r.charged_amount, r.refund_percent, r.refund_amount,
                bi.job_id, bi.mobile, ' . bulk_job_masked_sql('bj') . ' AS recipients_masked, ma.destination
         FROM ellsms_delivery_refunds r
         LEFT JOIN ellsms_bulk_items bi ON r.source = \'bulk_item\' AND bi.id = r.source_id
         LEFT JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
         LEFT JOIN ellsms_message_attempts ma ON r.source = \'attempt\' AND ma.id = r.source_id
         WHERE r.user_id = ?
         ORDER BY r.id DESC LIMIT ' . max(1, $limit)
    );
    $st->execute([$userId]);
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $destination = $row['source'] === 'bulk_item'
            ? bulk_recipient_display((string)($row['mobile'] ?? ''), !empty($row['recipients_masked']))
            : (string)($row['destination'] ?? '');
        $rows[] = [
            'created_at' => (string)$row['created_at'],
            'source' => (string)$row['source'],
            'source_id' => (int)$row['source_id'],
            'job_id' => $row['job_id'] !== null ? (int)$row['job_id'] : null,
            'destination' => $destination,
            'delivery_status' => (string)$row['delivery_status'],
            'charged_amount' => (int)$row['charged_amount'],
            'refund_percent' => (int)$row['refund_percent'],
            'refund_amount' => (int)$row['refund_amount'],
        ];
    }
    return $rows;
}

function delivery_refund_state_label(string $state): string {
    return match ($state) {
        'failed'   => 'تحویل نشده',
        'rejected' => 'رد شده توسط اپراتور',
        'expired'  => 'منقضی شده',
        default    => $state,
    };
}
