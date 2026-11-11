<?php
/**
 * ELLSMS — converts stored passwords from the backend's unsalted SHA-256 to Argon2id, for every
 * account at once and WITHOUT knowing any password (docs/password-security.md).
 *
 * For each account with panel access that has no Argon2id verifier yet, the SHA-256 digest in
 * user_.password is wrapped — Argon2id( hex(digest) ) — into ellsms_password_verifiers, and
 * user_.password is replaced by random bytes. The account's password stays exactly what it was.
 *
 * Works only with PASSWORD_HASH_MODE=argon2id: in legacy mode the SHA-256 column is what login
 * checks, so there is nothing to convert. ONE WAY — once an account is converted, the backend
 * platform can no longer check that account's password itself, and switching back to legacy mode
 * leaves it unable to sign in until its password is reset.
 *
 * NOT automatic. An operator runs it once after switching the mode (accounts it has not reached yet
 * are converted as they sign in anyway):
 *   make password-migrate-dry-run        # counts, changes nothing
 *   make password-migrate                # converts
 * or
 *   php cron/password-migrate.php [--apply] [--batch=500]
 *
 * Idempotent: an account that already has a verifier is skipped, so it is safe to re-run and to stop
 * halfway.
 */
require_once __DIR__ . '/../app/backend.php';

$apply = in_array('--apply', $argv ?? [], true);
$batch = 500;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) $batch = max(1, min(5000, (int)$m[1]));
}

if (password_hash_mode() !== 'argon2id') {
    fwrite(STDERR, "PASSWORD_HASH_MODE is not 'argon2id' — nothing to convert. Set it in .env first (docs/password-security.md).\n");
    exit(2);
}

$counts = ['converted' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0];
$afterId = 0;
$db = db();
Logger::info('auth.password_migrate.started', ['apply' => $apply]);

while (true) {
    $st = $db->prepare("SELECT user_id FROM ellsms_meta WHERE user_id > ? ORDER BY user_id LIMIT {$batch}");
    $st->execute([$afterId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) break;

    foreach ($ids as $userId) {
        $afterId = $userId;
        try {
            if (!$apply) {
                // The same decisions account_password_adopt_backend_hash() makes, without writing.
                if (password_verifier_row($userId) !== null) { $counts['already']++; continue; }
                $stored = backend_user_password_hash($userId);
                $counts[$stored !== null && strlen($stored) === 32 ? 'converted' : 'skipped']++;
                continue;
            }
            $outcome = account_password_adopt_backend_hash($userId);
            $counts[$outcome === 'legacy_mode' ? 'skipped' : $outcome]++;
        } catch (Throwable $e) {
            $counts['failed']++;
            Logger::error('auth.password_migrate.account_failed', ['user_id' => $userId, 'exception' => $e]);
        }
    }
}

Logger::info('auth.password_migrate.finished', ['apply' => $apply] + $counts);
printf(
    "%s: %d %s, %d already Argon2id, %d skipped (no usable stored password), %d failed.\n",
    $apply ? 'Done' : 'Dry run (nothing changed; add --apply)',
    $counts['converted'], $apply ? 'converted' : 'would be converted',
    $counts['already'], $counts['skipped'], $counts['failed']
);
exit($counts['failed'] > 0 ? 1 : 0);
