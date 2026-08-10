<?php
/**
 * ELLSMS — wallet drift detection (Phase 3, STEP 19).
 *
 * Compares every wallet account's available_balance against
 * user_.currentcredit and reports any mismatch — the two are supposed to
 * always be equal (every wallet mutation syncs currentcredit in the same
 * transaction, see wallet_sync_legacy_currentcredit() in app/wallet.php).
 * A non-empty report means something outside app/wallet.php wrote to
 * currentcredit directly, or there's a bug in one of its functions.
 *
 * Read-only. Never auto-corrects anything — per explicit instruction,
 * financial drift must be visible before being modified by a human
 * decision, not silently "fixed."
 *
 * Invoke via:
 *   make wallet-audit
 * or
 *   php cron/wallet-audit.php
 *
 * Exit code is 1 if any drift is found (so this is also usable as a CI/
 * monitoring check), 0 otherwise.
 */
require_once __DIR__ . '/../app/backend.php';

$drift = wallet_drift_report();

Logger::info('wallet.audit.finished', ['drift_count' => count($drift)]);

if (!$drift) {
    fwrite(STDOUT, "Wallet audit: no drift found — every wallet account matches user_.currentcredit.\n");
    exit(0);
}

fwrite(STDOUT, sprintf("Wallet audit: %d account(s) with drift found:\n", count($drift)));
foreach ($drift as $row) {
    fwrite(STDOUT, sprintf(
        "  user_id=%d wallet_available=%d legacy_currentcredit=%d drift=%+d\n",
        $row['user_id'], $row['wallet_available'], $row['legacy_currentcredit'], $row['drift']
    ));
}
fwrite(STDOUT, "Nothing has been changed — investigate before correcting (see docs/wallet-architecture.md).\n");
exit(1);
