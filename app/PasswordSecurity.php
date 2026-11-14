<?php
/**
 * ELLSMS — account passwords: the rules a new password must meet, how it is stored and checked, the
 * limit on guessing the current password, and signing other sessions out when it changes
 * (docs/password-security.md).
 *
 * EVERY place that sets or checks an account password goes through this file:
 *
 *   password_policy_error()        the one set of rules (length, letter, digit, not the username)
 *   account_password_verify()      "is this the account's password?"
 *   account_password_set()         store a new password (and sign the account's other sessions out)
 *   current_password_attempt_allowed()  the limit on guessing the current password
 *
 * STORAGE (PASSWORD_HASH_MODE)
 *   legacy    (default) user_.password holds the backend platform's unsalted SHA-256, exactly as
 *             before. Nothing about login changes.
 *   argon2id  the password is checked against an Argon2id verifier in ellsms_password_verifiers and
 *             user_.password no longer holds anything derived from it. The verifier is
 *             Argon2id( hex( SHA-256(password) ) ) — "hash wrapping" — so it can be built from the
 *             SHA-256 that is already stored, WITHOUT knowing the password: every existing account
 *             keeps its password and can be converted at once (cron/password-migrate.php), and an
 *             account not converted yet is converted the first time it signs in.
 *
 * The rules apply only when a password is SET. A password chosen before these rules existed keeps
 * working until its owner changes it.
 */

declare(strict_types=1);

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 128;
/** ellsms_password_verifiers.algo of a verifier account_password_verify() trusts. */
const PASSWORD_WRAPPED_ALGOS = ['argon2id-sha256', 'bcrypt-sha256'];

/* ==========================================================================
   The rules
   ========================================================================== */

/** rule key => the Persian line shown to the user. One list for the server check and the live check. */
function password_policy_rules(): array {
    return [
        'length'   => 'حداقل ' . to_persian_digits((string)PASSWORD_MIN_LENGTH) . ' نویسه (و حداکثر ' . to_persian_digits((string)PASSWORD_MAX_LENGTH) . ')',
        'letter'   => 'دست‌کم یک حرف (مثل a یا B)',
        'digit'    => 'دست‌کم یک عدد (۰ تا ۹)',
        'username' => 'با نام کاربری یکسان نباشد',
    ];
}

/** The keys of the rules $password breaks; [] when it is acceptable. */
function password_policy_problems(string $password, string $username = ''): array {
    $problems = [];
    $length = mb_strlen($password, 'UTF-8');
    if ($length < PASSWORD_MIN_LENGTH || $length > PASSWORD_MAX_LENGTH) $problems[] = 'length';
    if (preg_match('/\p{L}/u', $password) !== 1) $problems[] = 'letter';
    if (preg_match('/\p{N}/u', $password) !== 1) $problems[] = 'digit';
    $username = trim($username);
    if ($username !== '' && mb_strtolower($password, 'UTF-8') === mb_strtolower($username, 'UTF-8')) $problems[] = 'username';
    return $problems;
}

/** Null when $password is acceptable, else one Persian sentence naming every rule it breaks. */
function password_policy_error(string $password, string $username = ''): ?string {
    $problems = password_policy_problems($password, $username);
    if ($problems === []) return null;
    $rules = password_policy_rules();
    return 'رمز عبور پذیرفته نشد. رمز عبور باید این شرط‌ها را داشته باشد: '
        . implode('؛ ', array_map(static fn(string $key): string => $rules[$key], $problems)) . '.';
}

/**
 * Attributes for a "new password" input: the browser's own length check plus the hook
 * public/assets/js/password-policy.js uses to tick the rules off while the user types.
 * $usernameField is the name of the username input in the same form (a create-account form);
 * $username is a fixed username (changing the password of an existing account).
 */
function password_policy_input_attrs(string $username = '', string $usernameField = ''): string {
    $attrs = ' minlength="' . PASSWORD_MIN_LENGTH . '" maxlength="' . PASSWORD_MAX_LENGTH . '" data-pw-policy';
    if ($username !== '') $attrs .= ' data-pw-username="' . e($username) . '"';
    if ($usernameField !== '') $attrs .= ' data-pw-username-field="' . e($usernameField) . '"';
    return $attrs;
}

/** The list of rules shown under a "new password" input; each line turns green or red as the user types. */
function password_policy_hint_html(bool $withUsernameRule = true): string {
    // Spans, not a list: the hint also sits inside a <label>, where only phrasing content is valid.
    $html = '<span class="pw-rules" data-pw-rules><span class="pw-rules-title">رمز عبور باید این شرط‌ها را داشته باشد:</span>';
    foreach (password_policy_rules() as $key => $label) {
        if ($key === 'username' && !$withUsernameRule) continue;
        $html .= '<span class="pw-rule" data-rule="' . $key . '">' . e($label) . '</span>';
    }
    return $html . '</span>';
}

/* ==========================================================================
   Storage
   ========================================================================== */

/** 'legacy' or 'argon2id' — see the file header. Anything else in the environment means 'legacy'. */
function password_hash_mode(): string {
    return strtolower(trim((string)env('PASSWORD_HASH_MODE', 'legacy'))) === 'argon2id' ? 'argon2id' : 'legacy';
}

/** [password_hash() algorithm, the value stored in ellsms_password_verifiers.algo]. */
function password_modern_algo(): array {
    return defined('PASSWORD_ARGON2ID') ? [PASSWORD_ARGON2ID, 'argon2id-sha256'] : [PASSWORD_BCRYPT, 'bcrypt-sha256'];
}

/**
 * What the slow hash is computed over: the backend's SHA-256 digest, as 64 hex characters. Hex
 * because bcrypt (the fallback where Argon2id is not compiled in) stops at a NUL byte and at 72
 * bytes; 64 hex characters are safe for both.
 */
function password_wrap_input(string $rawSha256): string {
    return bin2hex($rawSha256);
}

/** The account's wrapped verifier row, or null (none yet, or the table is not there). */
function password_verifier_row(int $userId): ?array {
    try {
        $st = db()->prepare('SELECT verifier, algo FROM ellsms_password_verifiers WHERE user_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        Logger::error('auth.password_verifier_read_failed', ['user_id' => $userId, 'exception' => $e]);
        return null;
    }
    return $row && in_array((string)$row['algo'], PASSWORD_WRAPPED_ALGOS, true) ? $row : null;
}

/**
 * Stores the password whose backend SHA-256 digest is $rawSha256 — the one write path for a
 * password, whatever the mode.
 *
 * argon2id: the wrapped verifier is written FIRST and user_.password is replaced by 32 random bytes
 * only after that succeeded, in one transaction, so no failure can leave an account with neither.
 * legacy:   user_.password = the digest, as it always was; a wrapped verifier left from an earlier
 *           argon2id period is removed so it can never outlive the password it was made from.
 */
function account_password_store_legacy_hash(int $userId, string $rawSha256): void {
    if (password_hash_mode() !== 'argon2id') {
        backend_update_user_password($userId, $rawSha256);
        try {
            db()->prepare("DELETE FROM ellsms_password_verifiers WHERE user_id = ? AND algo IN ('argon2id-sha256','bcrypt-sha256')")->execute([$userId]);
        } catch (PDOException $e) {
            Logger::error('auth.password_verifier_cleanup_failed', ['user_id' => $userId, 'exception' => $e]);
        }
        return;
    }
    [$algo, $algoName] = password_modern_algo();
    $verifier = password_hash(password_wrap_input($rawSha256), $algo);
    db_transaction(static function (PDO $db) use ($userId, $verifier, $algoName): void {
        $db->prepare(
            'INSERT INTO ellsms_password_verifiers (user_id, verifier, algo) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE verifier = VALUES(verifier), algo = VALUES(algo)'
        )->execute([$userId, $verifier, $algoName]);
        // Not a digest of anything: nobody knows a password that hashes to it.
        backend_update_user_password($userId, random_bytes(32));
    });
}

/** Stores $plain as the account's password and signs the account's other sessions out. */
function account_password_set(int $userId, string $plain): void {
    account_password_store_legacy_hash($userId, backend_hash_password($plain));
    account_password_bump_version($userId);
}

/**
 * True when $plain is the account's password. $storedLegacy is user_.password when the caller has
 * already read it (login reads the whole row); otherwise it is read here.
 *
 * argon2id mode: an account with a wrapped verifier is checked against it and nothing else. One
 * without (not converted yet, or created by the backend a moment ago) is checked against the SHA-256
 * in user_.password and, when that matches, converted on the spot.
 */
function account_password_verify(int $userId, string $plain, ?string $storedLegacy = null): bool {
    if ($userId <= 0 || $plain === '') return false;
    $digest = backend_hash_password($plain);

    if (password_hash_mode() === 'argon2id') {
        $row = password_verifier_row($userId);
        if ($row !== null) {
            return password_verify(password_wrap_input($digest), (string)$row['verifier']);
        }
    }

    $storedLegacy ??= backend_user_password_hash($userId);
    if ($storedLegacy === null || $storedLegacy === '' || !hash_equals($storedLegacy, $digest)) {
        return false;
    }
    if (password_hash_mode() === 'argon2id') {
        try {
            account_password_store_legacy_hash($userId, $digest);
            Logger::info('auth.password_converted', ['user_id' => $userId, 'how' => 'sign_in']);
        } catch (Throwable $e) {
            // The password was right; a failed conversion must not refuse it. Tried again next time.
            Logger::error('auth.password_convert_failed', ['user_id' => $userId, 'exception' => $e]);
        }
    }
    return true;
}

/**
 * Converts one account's stored SHA-256 into a wrapped verifier without knowing the password
 * (argon2id mode only). Returns 'converted', 'already' (has a verifier), 'skipped' (nothing usable
 * stored) or 'legacy_mode'. Used right after the backend creates an account and by
 * cron/password-migrate.php.
 */
function account_password_adopt_backend_hash(int $userId): string {
    if (password_hash_mode() !== 'argon2id') return 'legacy_mode';
    if (password_verifier_row($userId) !== null) return 'already';
    $stored = backend_user_password_hash($userId);
    // The backend's digest is exactly 32 bytes; anything else is not something to wrap.
    if ($stored === null || strlen($stored) !== 32) return 'skipped';
    account_password_store_legacy_hash($userId, $stored);
    return 'converted';
}

/* ==========================================================================
   Signing other sessions out when the password changes
   ellsms_meta.password_version (db/migrations/2026_11_15_password_version.sql) counts the password
   changes of an account. A session remembers the number it signed in under; a session holding an
   older number is signed out on its next request (current_user(), app/bootstrap.php).
   ========================================================================== */

/** The account's password version; 0 when it never changed or the column is not there yet. */
function account_password_version(int $userId): int {
    static $columnMissing = false;
    if ($columnMissing || $userId <= 0) return 0;
    try {
        $st = db()->prepare('SELECT password_version FROM ellsms_meta WHERE user_id = ?');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (PDOException) {
        // Not migrated yet: behave as before the feature existed. One failed statement per process.
        $columnMissing = true;
        return 0;
    }
}

/** Called on every password change: every session signed in under the old number is now stale. */
function account_password_bump_version(int $userId): void {
    try {
        db()->prepare('UPDATE ellsms_meta SET password_version = password_version + 1 WHERE user_id = ?')->execute([$userId]);
    } catch (PDOException $e) {
        Logger::error('auth.password_version_bump_failed', ['user_id' => $userId, 'exception' => $e]);
    }
}

/** Remembers, in THIS session, the version it is signed in under (sign-in, and after changing one's own password). */
function session_bind_password_version(int $userId): void {
    $_SESSION['_pwv'] = account_password_version($userId);
}

/** False when the account's password changed after this session signed in. */
function session_password_version_current(int $userId): bool {
    return (int)($_SESSION['_pwv'] ?? 0) === account_password_version($userId);
}

/* ==========================================================================
   Guessing the current password
   ========================================================================== */

/**
 * Counts one attempt to prove the current password (changing the password, switching 2FA on or off)
 * and reports whether the account is still within the limit: RATE_LIMIT_PASSWORD_CHECK_MAX (5) per
 * RATE_LIMIT_PASSWORD_CHECK_WINDOW_SECONDS (900). Someone sitting at an open session cannot try the
 * current password without end.
 */
function current_password_attempt_allowed(int $userId): bool {
    $allowed = rate_limit_hit(
        rate_limit_bucket('password_check', 'user', (string)$userId),
        rate_limit_config('RATE_LIMIT_PASSWORD_CHECK_MAX', 5),
        rate_limit_config('RATE_LIMIT_PASSWORD_CHECK_WINDOW_SECONDS', 900)
    );
    if (!$allowed) {
        Logger::warning('auth.password_check.rate_limited', ['user_id' => $userId]);
    }
    return $allowed;
}

/** The message for a refused attempt. */
function current_password_rate_limit_message(): string {
    return 'رمز عبور فعلی چند بار پشت سر هم اشتباه وارد شده است. برای امنیت حساب، چند دقیقه‌ی دیگر دوباره تلاش کنید.';
}
