<?php
/**
 * ELLSMS — legacy credit projection adapter (Phase 8, Invariant G/H).
 *
 * Phase 3 already correctly isolated this behind exactly one function
 * (`wallet_sync_legacy_currentcredit()`, `app/wallet.php`) called only from WalletService's own
 * mutation functions — this file completes that isolation one level further: the actual
 * `UPDATE user_ SET currentcredit` write (the one direct backend-table write ELLSMS's financial
 * mechanics still needs) now physically lives here, in the same adapter directory as every other
 * backend-table access, instead of inline inside `app/wallet.php`. `app/wallet.php` is still the
 * ONLY caller — nothing here changes who may invoke it, only where the SQL lives.
 *
 * `BACKEND_LEGACY_CREDIT_SYNC_ENABLED` (default `1`) is the one real STEP 7 config toggle this
 * phase adds: set to `0` to skip this write entirely (logged, not silently dropped) — the intended
 * eventual end state once/if the backend platform independently derives credit from its own side
 * and no longer needs `user_.currentcredit` kept in lockstep (see docs/service-boundaries.md's DB
 * permission reduction plan). Wallet ledger correctness (`ellsms_wallet_accounts`/
 * `ellsms_wallet_transactions`) never depends on this flag either way — ELLSMS's own source of
 * truth is unaffected by whether the legacy projection is turned on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Support/Logger.php';

/** True unless an operator has explicitly disabled the legacy currentcredit projection. */
function backend_legacy_credit_sync_enabled(): bool {
    return (string)env('BACKEND_LEGACY_CREDIT_SYNC_ENABLED', '1') !== '0';
}

/**
 * Writes the given available balance into `user_.currentcredit` for $userId, inside the SAME
 * transaction as the caller's own wallet mutation (the caller must already be inside one — this
 * function does not open its own, matching `wallet_sync_legacy_currentcredit()`'s original
 * behavior exactly). A no-op (logged, not silently skipped) when the sync is disabled via
 * `BACKEND_LEGACY_CREDIT_SYNC_ENABLED=0`.
 */
function backend_sync_legacy_credit_projection(PDO $db, int $userId, int $availableBalance): void {
    if (!backend_legacy_credit_sync_enabled()) {
        Logger::info('backend.legacy_credit_sync.skipped', ['user_id' => $userId, 'reason' => 'disabled_by_config']);
        return;
    }
    $db->prepare('UPDATE user_ SET currentcredit = ? WHERE id = ?')->execute([$availableBalance, $userId]);
}
