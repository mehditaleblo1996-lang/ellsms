<?php
/**
 * ELLSMS — wallet opening-balance backfill (Phase 3, STEP 4).
 *
 * Creates an ellsms_wallet_accounts row for every user_ account that
 * doesn't have one yet, seeded from their CURRENT user_.currentcredit —
 * never resets it, never double-credits it. Each new account gets one
 * 'migration_opening_balance' ledger entry recording exactly what it
 * started from (see docs/wallet-architecture.md).
 *
 * Idempotent: users who already have a wallet account (from a previous
 * run, or created after this ran once) are skipped entirely — safe to
 * re-run any time, e.g. after new accounts are granted ELLSMS access.
 *
 * NOT automatic. Nothing in docker/entrypoint.sh, docker-compose.yml, or
 * any request path calls this — an operator runs it explicitly:
 *   make wallet-backfill
 * or
 *   php cron/wallet-backfill.php [--dry-run]
 *
 * Only processes accounts already granted ELLSMS panel access
 * (ellsms_meta row present) — an account never granted access has no
 * reason to have a wallet, consistent with Phase 2's authorization model
 * (docs/security-review.md finding 2).
 */
require_once __DIR__ . '/../app/backend.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$db = db();
$candidateIds = $db->query(
    "SELECT m.user_id
     FROM ellsms_meta m
     LEFT JOIN ellsms_wallet_accounts w ON w.user_id = m.user_id
     WHERE w.user_id IS NULL"
)->fetchAll(PDO::FETCH_COLUMN);
$usersById = backend_users_by_ids($candidateIds);
$candidates = [];
foreach ($candidateIds as $userId) {
    if (isset($usersById[$userId])) {
        $candidates[] = ['id' => $userId, 'currentcredit' => $usersById[$userId]['credit']];
    }
}

Logger::info('wallet.backfill.started', ['candidate_count' => count($candidates), 'dry_run' => $dryRun]);

$created = 0;
$errors = 0;

foreach ($candidates as $row) {
    $userId = (int)$row['id'];
    $credit = (int)$row['currentcredit'];
    try {
        if ($dryRun) {
            Logger::info('wallet.backfill.would_create', ['user_id' => $userId, 'opening_balance' => $credit]);
            $created++;
            continue;
        }
        $result = wallet_backfill_user($userId, $credit);
        if (!$result['skipped']) {
            $created++;
        }
    } catch (Throwable $t) {
        Logger::error('wallet.backfill.user_failed', ['user_id' => $userId, 'exception' => $t]);
        $errors++;
    }
}

Logger::info('wallet.backfill.finished', ['candidate_count' => count($candidates), 'created' => $created, 'errors' => $errors, 'dry_run' => $dryRun]);

fwrite(STDOUT, sprintf(
    "Wallet backfill: %d candidate(s), %d account(s) %s, %d error(s)%s.\n",
    count($candidates), $created, $dryRun ? 'would be created' : 'created', $errors, $dryRun ? ' (dry run — nothing changed)' : ''
));
