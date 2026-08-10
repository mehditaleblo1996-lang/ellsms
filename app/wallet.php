<?php
/**
 * ELLSMS — Wallet ledger (Phase 3).
 *
 * Replaces "read user_.currentcredit -> decide -> UPDATE currentcredit later" with an immutable
 * ledger (ellsms_wallet_transactions) plus a reservation model (ellsms_wallet_reservations),
 * backed by a per-user balance cache (ellsms_wallet_accounts) that every mutation locks with
 * SELECT ... FOR UPDATE inside one db_transaction() call — this is what makes concurrent debits
 * against the same account serialize correctly instead of racing on a stale PHP-level read.
 *
 * user_.currentcredit remains fully functional and IS still updated — every function below keeps
 * it synchronized (in the same transaction) to available_balance, since the backend platform reads
 * that column independently and must keep seeing an accurate, real-time spendable balance. The
 * wallet ledger is the new source of truth; currentcredit is a compatibility projection of it, not
 * a second independent value that could drift on its own. See docs/wallet-architecture.md.
 *
 * Idempotency (STEP 7): every mutating function locks the relevant row (FOR UPDATE) FIRST, THEN
 * checks whether this exact idempotency_key/reference has already been recorded, and only mutates
 * anything if it hasn't. This is deliberately NOT "insert first, catch a duplicate-key exception" —
 * that pattern only rolls back cleanly when the function owns its own top-level transaction, and
 * silently corrupts the balance when called from WITHIN an already-open outer transaction (which
 * genuinely happens here: bulk_queue_job() and payment_claim_and_credit() both call these functions
 * from inside their own db_transaction() closures) — MySQL does not roll back an entire transaction
 * just because one statement inside it hit a UNIQUE constraint; only an explicit ROLLBACK does, and
 * a nested db_transaction() call correctly defers ownership of that to whichever caller opened the
 * transaction first. Locking before checking closes the race for genuinely concurrent top-level
 * callers (the second blocks until the first commits, then sees its row and returns the replay
 * result) while also being trivially correct for a same-transaction retry.
 *
 * No class/namespace here, matching every other app/*.php file in this codebase (plain functions,
 * required once from app/bootstrap.php) — introducing OOP here alone would be an inconsistent,
 * unnecessary abstraction for a single-file service.
 */

declare(strict_types=1);

/**
 * Thrown by callers (not by the wallet functions themselves) inside a
 * db_transaction() closure that also creates other rows (e.g. a bulk job
 * + its items) when a wallet_reserve()/wallet_debit() call fails —
 * forces the whole transaction to roll back instead of committing a job
 * row that looks funded but isn't. See bulk_queue_job() for the only
 * current use.
 */
class WalletInsufficientBalanceException extends RuntimeException {
}

/**
 * Phase 13 companion to WalletInsufficientBalanceException, thrown for exactly the same reason and
 * used exactly the same way: raised INSIDE a db_transaction() closure that has already created
 * other rows (a bulk job + its items), to force the whole transaction to roll back rather than
 * commit a job that the organization's plan has no remaining allowance for. Declared here, next to
 * its wallet counterpart, so the two failure modes bulk_queue_job() must unwind identically live
 * side by side rather than in two unrelated files.
 */
class QuotaExceededException extends RuntimeException {
}

/** Must be called with an existing PDO instance inside an open transaction. Never backfills from currentcredit — that's the explicit, separate STEP 4 backfill script (cron/wallet-backfill.php). */
function wallet_ensure_account(PDO $db, int $userId): void {
    $db->prepare('INSERT IGNORE INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')
       ->execute([$userId]);
}

/**
 * Locks and returns the account's available_balance, creating the row first only if it doesn't
 * exist yet. Deliberately tries the lock BEFORE calling wallet_ensure_account() — for the common
 * case (an account that already exists, which is every call after the one-time backfill),
 * this never touches INSERT IGNORE at all. Two concurrent transactions both unconditionally
 * running INSERT IGNORE against the same existing row each take a lock during the duplicate-key
 * check, then both try to upgrade to this function's own FOR UPDATE lock on the next statement —
 * a real deadlock (MySQL error 1213), found by tests/Integration/WalletConcurrencyTest.php.
 * Skipping the INSERT entirely when the row is already there removes that lock-upgrade race for
 * every already-provisioned account; only brand-new account creation (rare, effectively one-time
 * per user) still takes the INSERT IGNORE path.
 */
function wallet_lock_account(PDO $db, int $userId): int {
    $acct = $db->prepare('SELECT available_balance FROM ellsms_wallet_accounts WHERE user_id = ? FOR UPDATE');
    $acct->execute([$userId]);
    $row = $acct->fetch();
    if ($row === false) {
        wallet_ensure_account($db, $userId);
        $acct->execute([$userId]);
        $row = $acct->fetch();
    }
    return (int)$row['available_balance'];
}

/** Read-only. available = currentcredit's value; total = what the user actually "owns" including reserved-but-unspent credit. */
function wallet_balance(int $userId): array {
    $st = db()->prepare('SELECT available_balance, reserved_balance FROM ellsms_wallet_accounts WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    $available = (int)($row['available_balance'] ?? 0);
    $reserved  = (int)($row['reserved_balance'] ?? 0);
    return ['available' => $available, 'reserved' => $reserved, 'total' => $available + $reserved];
}

/**
 * Keeps the backend-visible column in lockstep with available_balance, in the same transaction as
 * the caller's mutation. The read (ellsms_wallet_accounts, ELLSMS-owned) stays here; the actual
 * write to user_.currentcredit (backend-owned) is delegated to
 * backend_sync_legacy_credit_projection() (app/Backend/credit_projection.php, Phase 8) — this
 * remains the ONLY function in this codebase that calls it, preserving "only WalletService invokes
 * it" (Invariant H) exactly as before, just with the write itself physically relocated to the
 * backend-adapter directory.
 */
function wallet_sync_legacy_currentcredit(PDO $db, int $userId): void {
    $st = $db->prepare('SELECT available_balance FROM ellsms_wallet_accounts WHERE user_id = ?');
    $st->execute([$userId]);
    $avail = (int)($st->fetch()['available_balance'] ?? 0);
    backend_sync_legacy_credit_projection($db, $userId, $avail);
}

/** Shared "already applied — return what actually happened" lookup for every idempotent-replay branch below. */
function wallet_ledger_entry_by_key(PDO $db, string $idempotencyKey): ?array {
    $st = $db->prepare('SELECT balance_after FROM ellsms_wallet_transactions WHERE idempotency_key = ?');
    $st->execute([$idempotencyKey]);
    $row = $st->fetch();
    return $row ? ['ok' => true, 'replayed' => true, 'balance' => (int)$row['balance_after']] : null;
}

/**
 * Unconditional credit (purchases, refunds, admin credit, migration opening balance). Always
 * succeeds unless the idempotency key was already used, in which case it's a no-op replay.
 */
function wallet_credit(int $userId, int $amount, string $type, string $refType, string $refId, string $idempotencyKey, ?int $actorId = null, array $metadata = []): array {
    if ($amount <= 0) {
        return ['ok' => true, 'replayed' => false, 'balance' => wallet_balance($userId)['available']];
    }
    return db_transaction(function (PDO $db) use ($userId, $amount, $type, $refType, $refId, $idempotencyKey, $actorId, $metadata): array {
        $before = wallet_lock_account($db, $userId);

        if ($replay = wallet_ledger_entry_by_key($db, $idempotencyKey)) {
            return $replay;
        }

        $after = $before + $amount;
        $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, metadata, actor_user_id)
                      VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$userId, $type, $amount, $before, $after, $refType, $refId, $idempotencyKey, json_encode($metadata), $actorId]);

        $db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ? WHERE user_id = ?')->execute([$after, $userId]);
        wallet_sync_legacy_currentcredit($db, $userId);
        Logger::info('wallet.credit', ['user_id' => $userId, 'type' => $type, 'amount' => $amount, 'reference_type' => $refType, 'reference_id' => $refId, 'balance' => $after]);
        return ['ok' => true, 'replayed' => false, 'balance' => $after];
    });
}

/**
 * Conditional debit (direct SMS spend outside a reservation, manual admin debit's inner
 * mechanics). Fails cleanly with ok=false if available_balance is insufficient — never goes
 * negative, satisfying Invariant A because the balance read and the write happen inside one
 * transaction holding a row lock (FOR UPDATE), so a concurrent debit against the same account
 * genuinely serializes instead of racing on a stale PHP-level read.
 */
function wallet_debit(int $userId, int $amount, string $type, string $refType, string $refId, string $idempotencyKey, ?int $actorId = null, array $metadata = []): array {
    if ($amount <= 0) {
        return ['ok' => true, 'replayed' => false, 'balance' => wallet_balance($userId)['available']];
    }
    return db_transaction(function (PDO $db) use ($userId, $amount, $type, $refType, $refId, $idempotencyKey, $actorId, $metadata): array {
        $before = wallet_lock_account($db, $userId);

        if ($replay = wallet_ledger_entry_by_key($db, $idempotencyKey)) {
            return $replay;
        }

        if ($before < $amount) {
            Logger::warning('wallet.debit.insufficient_balance', ['user_id' => $userId, 'type' => $type, 'amount' => $amount, 'balance' => $before, 'reference_type' => $refType, 'reference_id' => $refId]);
            return ['ok' => false, 'replayed' => false, 'balance' => $before, 'reason' => 'insufficient_balance'];
        }
        $after = $before - $amount;

        $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, metadata, actor_user_id)
                      VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$userId, $type, -$amount, $before, $after, $refType, $refId, $idempotencyKey, json_encode($metadata), $actorId]);

        $db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ? WHERE user_id = ?')->execute([$after, $userId]);
        wallet_sync_legacy_currentcredit($db, $userId);
        Logger::info('wallet.debit', ['user_id' => $userId, 'type' => $type, 'amount' => $amount, 'reference_type' => $refType, 'reference_id' => $refId, 'balance' => $after]);
        return ['ok' => true, 'replayed' => false, 'balance' => $after];
    });
}

/**
 * Reserve credit for accepted-but-not-yet-executed work (a bulk job's worst-case total cost, one
 * schedule occurrence's expected cost). Moves available -> reserved; total wallet value is
 * unchanged until a commit/release. At most one reservation can ever exist per
 * (reference_type, reference_id) — a retried "accept this job" is a no-op replay of the same
 * reservation, not a second one (Invariant D).
 */
function wallet_reserve(int $userId, int $amount, string $refType, string $refId, string $idempotencyKey, ?string $expiresAt = null): array {
    if ($amount <= 0) {
        return ['ok' => true, 'reservation_id' => null, 'replayed' => false, 'skipped' => true];
    }
    return db_transaction(function (PDO $db) use ($userId, $amount, $refType, $refId, $idempotencyKey, $expiresAt): array {
        $available = wallet_lock_account($db, $userId);

        // Checked AFTER acquiring the account lock above: for two
        // genuinely concurrent top-level callers, the second blocks here
        // until the first commits, then sees the row below and replays
        // instead of reserving twice. `status` tells the caller whether
        // this exact operation was already fully handled (committed /
        // released — e.g. a worker retry after a crash) vs. still active.
        $existing = $db->prepare('SELECT id, status FROM ellsms_wallet_reservations WHERE reference_type = ? AND reference_id = ?');
        $existing->execute([$refType, $refId]);
        if ($row = $existing->fetch()) {
            return ['ok' => true, 'reservation_id' => (int)$row['id'], 'replayed' => true, 'status' => $row['status']];
        }

        if ($available < $amount) {
            Logger::warning('wallet.reservation.insufficient_balance', ['user_id' => $userId, 'amount' => $amount, 'balance' => $available, 'reference_type' => $refType, 'reference_id' => $refId]);
            return ['ok' => false, 'reservation_id' => null, 'replayed' => false, 'reason' => 'insufficient_balance'];
        }

        $db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = available_balance - ?, reserved_balance = reserved_balance + ? WHERE user_id = ?')
           ->execute([$amount, $amount, $userId]);
        $db->prepare('INSERT INTO ellsms_wallet_reservations (user_id, amount, remaining_amount, reference_type, reference_id, idempotency_key, expires_at)
                      VALUES (?,?,?,?,?,?,?)')
           ->execute([$userId, $amount, $amount, $refType, $refId, $idempotencyKey, $expiresAt]);
        // Captured immediately, before any other statement runs on this
        // connection — PDO::lastInsertId() (mysqlnd) gets reset to 0 by
        // the very next prepare()/execute() on the SAME connection, even
        // a plain SELECT, so this must not be read after
        // wallet_sync_legacy_currentcredit()'s own queries below.
        $reservationId = (int)$db->lastInsertId();

        wallet_sync_legacy_currentcredit($db, $userId);
        Logger::info('wallet.reservation.created', ['user_id' => $userId, 'amount' => $amount, 'reference_type' => $refType, 'reference_id' => $refId]);
        return ['ok' => true, 'reservation_id' => $reservationId, 'replayed' => false, 'status' => 'active'];
    });
}

/**
 * Spend part (or all) of an active reservation. Moves reserved -> gone (a real ledger debit);
 * available_balance (and therefore currentcredit) does NOT change here — that credit already left
 * "available" the moment it was reserved. Can be called multiple times against the same
 * reservation for its individual line items (a bulk job's rows) as long as each call's
 * idempotency_key is unique per item and the cumulative committed amount never exceeds what was
 * reserved. The reservation becomes 'committed' once remaining_amount reaches zero.
 */
function wallet_commit_reservation(string $refType, string $refId, int $amount, string $idempotencyKey, ?int $actorId = null, array $metadata = []): array {
    if ($amount <= 0) {
        return ['ok' => true, 'replayed' => false];
    }
    return db_transaction(function (PDO $db) use ($refType, $refId, $amount, $idempotencyKey, $actorId, $metadata): array {
        $rst = $db->prepare('SELECT * FROM ellsms_wallet_reservations WHERE reference_type = ? AND reference_id = ? FOR UPDATE');
        $rst->execute([$refType, $refId]);
        $res = $rst->fetch();
        if (!$res) {
            return ['ok' => false, 'replayed' => false, 'reason' => 'no_active_reservation'];
        }

        if ($replay = wallet_ledger_entry_by_key($db, $idempotencyKey)) {
            return ['ok' => true, 'replayed' => true, 'remaining' => (int)$res['remaining_amount'], 'status' => $res['status']];
        }

        if ($res['status'] !== 'active') {
            return ['ok' => false, 'replayed' => false, 'reason' => 'no_active_reservation'];
        }
        if ($amount > (int)$res['remaining_amount']) {
            return ['ok' => false, 'replayed' => false, 'reason' => 'amount_exceeds_remaining'];
        }

        $userId = (int)$res['user_id'];
        $acct = $db->prepare('SELECT available_balance FROM ellsms_wallet_accounts WHERE user_id = ? FOR UPDATE');
        $acct->execute([$userId]);
        // Committing a reservation moves money out of "reserved," not
        // "available" — the ledger's balance_before/after track
        // available_balance (what currentcredit shows) for consistency
        // with every other ledger entry, so both are the same value here;
        // the actual spend is visible in reserved_balance and in this
        // row's own `amount`.
        $availableBalance = (int)$acct->fetch()['available_balance'];

        $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, metadata, actor_user_id)
                      VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$userId, 'sms_debit', -$amount, $availableBalance, $availableBalance, $refType, $refId, $idempotencyKey, json_encode($metadata), $actorId]);

        $db->prepare('UPDATE ellsms_wallet_accounts SET reserved_balance = reserved_balance - ? WHERE user_id = ?')
           ->execute([$amount, $userId]);

        $newRemaining = (int)$res['remaining_amount'] - $amount;
        $newStatus = $newRemaining <= 0 ? 'committed' : 'active';
        $db->prepare('UPDATE ellsms_wallet_reservations SET remaining_amount = ?, status = ? WHERE id = ?')
           ->execute([$newRemaining, $newStatus, $res['id']]);

        Logger::info('wallet.reservation.committed', ['user_id' => $userId, 'amount' => $amount, 'reference_type' => $refType, 'reference_id' => $refId, 'status' => $newStatus]);
        return ['ok' => true, 'replayed' => false, 'remaining' => $newRemaining, 'status' => $newStatus];
    });
}

/**
 * Release whatever remains of an active reservation back to available_balance — called when a
 * job/schedule finishes (give back the unused remainder) or is cancelled/fails outright (give back
 * everything). Idempotent: releasing an already-committed/released/expired/non-existent
 * reservation is a safe no-op (Invariant E — a reservation ends as exactly one of
 * committed/released, never both, and this function never turns a committed one back into
 * released).
 */
function wallet_release_reservation(string $refType, string $refId, ?string $idempotencyKey = null): array {
    return db_transaction(function (PDO $db) use ($refType, $refId, $idempotencyKey): array {
        $rst = $db->prepare('SELECT * FROM ellsms_wallet_reservations WHERE reference_type = ? AND reference_id = ? FOR UPDATE');
        $rst->execute([$refType, $refId]);
        $res = $rst->fetch();
        if (!$res) {
            return ['ok' => true, 'released_amount' => 0, 'reason' => 'no_reservation'];
        }
        if ($res['status'] !== 'active') {
            return ['ok' => true, 'released_amount' => 0, 'reason' => 'already_finalized'];
        }

        $remaining = (int)$res['remaining_amount'];
        $userId    = (int)$res['user_id'];

        $db->prepare("UPDATE ellsms_wallet_reservations SET remaining_amount = 0, status = 'released' WHERE id = ?")
           ->execute([$res['id']]);

        if ($remaining <= 0) {
            return ['ok' => true, 'released_amount' => 0, 'reason' => 'released'];
        }

        $ik = $idempotencyKey ?? ('release:' . $refType . ':' . $refId);
        if (wallet_ledger_entry_by_key($db, $ik)) {
            // Extremely unlikely (the reservation row's own status check
            // above already guards this) but kept for defense in depth:
            // never apply the same release ledger entry twice.
            return ['ok' => true, 'released_amount' => 0, 'reason' => 'already_finalized'];
        }

        $acct = $db->prepare('SELECT available_balance FROM ellsms_wallet_accounts WHERE user_id = ? FOR UPDATE');
        $acct->execute([$userId]);
        $before = (int)$acct->fetch()['available_balance'];
        $after  = $before + $remaining;

        $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key)
                      VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$userId, 'reservation_release', $remaining, $before, $after, $refType, $refId, $ik]);

        $db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ?, reserved_balance = reserved_balance - ? WHERE user_id = ?')
           ->execute([$after, $remaining, $userId]);
        wallet_sync_legacy_currentcredit($db, $userId);

        Logger::info('wallet.reservation.released', ['user_id' => $userId, 'released_amount' => $remaining, 'reference_type' => $refType, 'reference_id' => $refId]);
        return ['ok' => true, 'released_amount' => $remaining, 'reason' => 'released'];
    });
}

/**
 * Admin manual credit/debit adjustment (STEP 17). A non-empty $reason is required — this is the
 * one place callers must supply a human explanation, since there is no send/payment to derive one
 * from. A debit that exceeds available balance clamps at zero rather than being rejected, matching
 * the pre-Phase-3 `GREATEST(0, currentcredit + amount)` behavior exactly (no supported
 * administrative-debt model exists, and rejecting outright here would be a breaking behavior
 * change for a legitimate "zero out this account" admin action).
 */
function wallet_manual_adjustment(int $targetUserId, int $amount, int $actorUserId, string $reason, string $idempotencyKey): array {
    if (trim($reason) === '') {
        return ['ok' => false, 'reason' => 'reason_required'];
    }
    $meta = ['reason' => $reason];
    if ($amount >= 0) {
        return wallet_credit($targetUserId, $amount, 'manual_credit', 'admin_adjustment', (string)$targetUserId, $idempotencyKey, $actorUserId, $meta);
    }

    $requested = -$amount;
    return db_transaction(function (PDO $db) use ($targetUserId, $requested, $actorUserId, $idempotencyKey, $meta): array {
        $before = wallet_lock_account($db, $targetUserId);

        if ($replay = wallet_ledger_entry_by_key($db, $idempotencyKey)) {
            return $replay;
        }

        $applied = min($requested, $before); // clamp at zero, legacy-compatible
        $after = $before - $applied;

        $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, metadata, actor_user_id)
                      VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$targetUserId, 'manual_debit', -$applied, $before, $after, 'admin_adjustment', (string)$targetUserId, $idempotencyKey,
                      json_encode($meta + ['requested' => $requested, 'clamped' => $applied < $requested]), $actorUserId]);

        $db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ? WHERE user_id = ?')->execute([$after, $targetUserId]);
        wallet_sync_legacy_currentcredit($db, $targetUserId);
        Logger::info('wallet.manual_debit', ['actor_user_id' => $actorUserId, 'target_user_id' => $targetUserId, 'requested' => $requested, 'applied' => $applied, 'balance' => $after]);
        return ['ok' => true, 'replayed' => false, 'balance' => $after];
    });
}

/**
 * One-time backfill for a single user (STEP 4) — seeds a wallet account
 * from their CURRENT user_.currentcredit value, with a
 * 'migration_opening_balance' ledger entry recording exactly where that
 * starting balance came from. Idempotent: a user who already has a
 * wallet account is skipped entirely, never re-seeded or re-credited —
 * safe to re-run cron/wallet-backfill.php as many times as needed (e.g.
 * for users created after the first run).
 */
function wallet_backfill_user(int $userId, int $currentCredit): array {
    return db_transaction(function (PDO $db) use ($userId, $currentCredit): array {
        $existing = $db->prepare('SELECT user_id FROM ellsms_wallet_accounts WHERE user_id = ? FOR UPDATE');
        $existing->execute([$userId]);
        if ($existing->fetch()) {
            return ['ok' => true, 'skipped' => true];
        }

        $db->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, ?, 0)')
           ->execute([$userId, $currentCredit]);

        $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key)
                      VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$userId, 'migration_opening_balance', $currentCredit, 0, $currentCredit, 'wallet_backfill', (string)$userId, 'wallet_backfill:' . $userId]);

        return ['ok' => true, 'skipped' => false];
    });
}

/**
 * Drift report (STEP 19) — compares user_.currentcredit against the wallet's own
 * available_balance for every user who has a wallet account. Read-only; never auto-corrects
 * anything. A non-empty result means something outside app/wallet.php wrote to currentcredit
 * directly, or a bug exists in one of the functions above.
 */
function wallet_drift_report(): array {
    $accounts = db()->query('SELECT user_id, available_balance FROM ellsms_wallet_accounts')->fetchAll();
    $users = backend_users_by_ids(array_column($accounts, 'user_id'));
    $out = [];
    foreach ($accounts as $a) {
        $userId = (int)$a['user_id'];
        if (!isset($users[$userId])) {
            continue;
        }
        $legacyCredit = (int)$users[$userId]['credit'];
        $available = (int)$a['available_balance'];
        if ($available === $legacyCredit) {
            continue;
        }
        $out[] = [
            'user_id'               => $userId,
            'wallet_available'      => $available,
            'legacy_currentcredit'  => $legacyCredit,
            'drift'                 => $legacyCredit - $available,
        ];
    }
    return $out;
}
