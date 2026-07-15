<?php
/**
 * ELLSMS — Smart SMS Panel
 * Bootstrap: environment, database, session, helpers.
 *
 * IMPORTANT: ELLSMS shares its MySQL database with the backend SMS
 * platform. It does NOT own or migrate the platform's own tables
 * (user_, outbound_message, inbound_message, domain, customer, role,
 * access) — it only reads/writes those, plus its own supplementary
 * tables prefixed `ellsms_` (see db/ellsms_extra.sql).
 */

declare(strict_types=1);

define('ELLSMS_VERSION', '3.0.0');
define('APP_ROOT', dirname(__DIR__));

/* ---------- Environment ---------- */
function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/* ---------- Database (shared backend platform DB) ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = env('BACKEND_DB_HOST', 'localhost');
        $port = env('BACKEND_DB_PORT', '3306');
        $name = env('BACKEND_DB_NAME', 'change_me');
        $user = env('BACKEND_DB_USER', 'change_me');
        $pass = env('BACKEND_DB_PASS', '');
        $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo  = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/* ---------- ELLSMS settings (ellsms_settings key/value, cached) ---------- */
function setting(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT skey, svalue FROM ellsms_settings') as $row) {
            $cache[$row['skey']] = $row['svalue'];
        }
    }
    $v = $cache[$key] ?? '';
    return ($v !== '' ? $v : null) ?? $default;
}

function set_setting(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, $value]);
}

/* ---------- Password hashing (matches the backend platform's scheme) ----------
 * The connected backend hashes passwords with plain SHA-256 over the
 * UTF-8 plaintext, stored as raw bytes in user_.password — a known
 * placeholder scheme on that side, not something ELLSMS chose. ELLSMS
 * matches it exactly so both systems can authenticate the same account. */
function backend_hash_password(string $plain): string {
    return hash('sha256', $plain, true); // raw 32-byte digest, binary-safe
}

function backend_verify_password(string $plain, string $stored): bool {
    return hash_equals(backend_hash_password($plain), $stored);
}

/* ---------- Session & Auth ---------- */
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_name('ELLSMS_SESSION');
    session_start();
}

/**
 * The logged-in identity: the backend platform's `user_` row merged
 * with its `ellsms_meta` row (panel_access / is_admin / originator).
 */
function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare(
                'SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.email, u.mobile,
                        u.active, u.deleted, u.currentcredit AS credit,
                        m.panel_access, m.is_admin, m.originator
                 FROM user_ u
                 JOIN ellsms_meta m ON m.user_id = u.id
                 WHERE u.id = ?'
            );
            $st->execute([$_SESSION['uid']]);
            $row = $st->fetch();
            if ($row && $row['active'] && !$row['deleted'] && $row['panel_access']) {
                $row['role']      = $row['is_admin'] ? 'admin' : 'user';
                $row['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
                $user = $row;
            }
        }
    }
    return $user;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('۴۰۳ — این بخش فقط برای مدیران است.');
    }
    return $u;
}

function is_admin(): bool {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/** True if at least one account has been granted ELLSMS admin. */
function ellsms_has_admin(): bool {
    return (int)db()->query('SELECT COUNT(*) c FROM ellsms_meta WHERE is_admin = 1')->fetch()['c'] > 0;
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
            http_response_code(400);
            exit('نشانه‌ی درخواست نامعتبر است. بازگردید و دوباره تلاش کنید.');
        }
    }
}

/* ---------- Flash messages ---------- */
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Output helpers ---------- */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never {
    header('Location: ' . $to);
    exit;
}

/* ---------- SMS helpers ---------- */

/** Normalize a phone number to international digits (98…) */
function normalize_msisdn(string $raw): ?string {
    $n = preg_replace('/[^\d+]/', '', trim($raw));
    if ($n === '') return null;
    if (str_starts_with($n, '+'))  $n = substr($n, 1);
    if (str_starts_with($n, '00')) $n = substr($n, 2);
    if (str_starts_with($n, '09') && strlen($n) === 11) $n = '98' . substr($n, 1);
    if (str_starts_with($n, '9') && strlen($n) === 10)  $n = '98' . $n;
    return preg_match('/^\d{10,15}$/', $n) ? $n : null;
}

/**
 * Normalize a sender line / originator to digits only. Unlike
 * normalize_msisdn(), this does NOT rewrite a leading 09 to 98 — a
 * sender line or short code isn't a mobile number, so that rewrite
 * would corrupt it. The backend API requires this as a plain integer.
 */
function normalize_originator(string $raw): ?string {
    $n = preg_replace('/\D/', '', trim($raw));
    return $n !== '' ? $n : null;
}

/** Parse a textarea / CSV of numbers into a unique normalized list. */
function parse_destinations(string $raw): array {
    $parts = preg_split('/[\s,;،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $n = normalize_msisdn($p);
        if ($n) $out[$n] = true;
    }
    // array_keys() would hand back PHP ints here, not strings: PHP
    // silently casts any array key that looks like a valid decimal
    // integer (e.g. "989197684063") to an int. strval() undoes that so
    // callers — and json_encode() for the API request — see real strings.
    return array_map('strval', array_keys($out));
}

/** Number of SMS parts for a message (GSM-7 vs Unicode). */
function sms_parts(string $content): int {
    $isUnicode = (bool)preg_match('/[^\x20-\x7E\r\n]/u', $content);
    $len = mb_strlen($content, 'UTF-8');
    if ($len === 0) return 0;
    if ($isUnicode) return $len <= 70  ? 1 : (int)ceil($len / 67);
    return              $len <= 160 ? 1 : (int)ceil($len / 153);
}

/* ---------- Audit log ---------- */
function audit(int $userId, string $action, string $details = ''): void {
    $st = db()->prepare('INSERT INTO ellsms_audit_log (user_id, action, details, ip) VALUES (?,?,?,?)');
    $st->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'cli']);
}

/* ==========================================================================
   Persian (Jalali) calendar helpers
   Pure PHP, no external library — a well-known algorithmic conversion
   (Kazimierz Borkowski's method), so there's no CDN or package dependency
   for something as central as every date on every page.
   ========================================================================== */

const JALALI_MONTHS = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
];

const JALALI_WEEKDAYS = [
    'شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه',
];

/** Gregorian y/m/d -> Jalali [jy, jm, jd]. */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
          + (int)(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * (int)($days / 12053);
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/** Jalali y/m/d -> Gregorian [gy, gm, gd]. */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4)
          + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * (int)($days / 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * (int)(--$days / 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 === 0) && ($gy % 100 !== 0)) || ($gy % 400 === 0)) ? 29 : 28,
              31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
    return [$gy, $gm, $gd];
}

/** Convert every ASCII digit in a string to its Persian digit form. */
function to_persian_digits(string $s): string {
    static $en = ['0','1','2','3','4','5','6','7','8','9'];
    static $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($en, $fa, $s);
}

/** Convert Persian/Arabic-Indic digits back to ASCII (for reading $_POST values). */
function from_persian_digits(string $s): string {
    static $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    static $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($fa, $en, $s);
}

/**
 * Format a MySQL DATETIME/DATE string as a Persian Jalali date (and
 * optionally time), with Persian digits. Returns '' for null/empty input.
 */
function jdate(?string $mysqlDateTime, bool $withTime = true): string {
    if (!$mysqlDateTime) return '';
    $ts = strtotime($mysqlDateTime);
    if ($ts === false) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $out = to_persian_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    if ($withTime) $out .= ' - ' . to_persian_digits(date('H:i', $ts));
    return $out;
}

/** Same as jdate() but spells the month name out — e.g. "۲۳ تیر ۱۴۰۵". */
function jdate_long(?string $mysqlDateTime): string {
    if (!$mysqlDateTime) return '';
    $ts = strtotime($mysqlDateTime);
    if ($ts === false) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return to_persian_digits((string)$jd) . ' ' . JALALI_MONTHS[$jm] . ' ' . to_persian_digits((string)$jy);
}

/**
 * Render three <select> boxes (year/month/day) for picking a Jalali date,
 * named "{$name}_y", "{$name}_m", "{$name}_d". $defaultYmd is a Gregorian
 * 'Y-m-d' string (or null for no default / today).
 */
function jalali_date_select(string $name, ?string $defaultYmd = null, int $yearsAhead = 2): string {
    $ts = $defaultYmd ? strtotime($defaultYmd) : time();
    [$dy, $dm, $dd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $todayTs = time();
    [$ty] = gregorian_to_jalali((int)date('Y', $todayTs), (int)date('n', $todayTs), (int)date('j', $todayTs));

    $h = '<span class="jdate">';
    $h .= '<select name="' . e($name) . '_y" class="jdate-y">';
    for ($y = $ty - 1; $y <= $ty + $yearsAhead; $y++) {
        $h .= '<option value="' . $y . '"' . ($y === $dy ? ' selected' : '') . '>' . to_persian_digits((string)$y) . '</option>';
    }
    $h .= '</select>';
    $h .= '<select name="' . e($name) . '_m" class="jdate-m">';
    foreach (JALALI_MONTHS as $num => $label) {
        $h .= '<option value="' . $num . '"' . ($num === $dm ? ' selected' : '') . '>' . $label . '</option>';
    }
    $h .= '</select>';
    $h .= '<select name="' . e($name) . '_d" class="jdate-d">';
    for ($d = 1; $d <= 31; $d++) {
        $h .= '<option value="' . $d . '"' . ($d === $dd ? ' selected' : '') . '>' . to_persian_digits((string)$d) . '</option>';
    }
    $h .= '</select></span>';
    return $h;
}

/** Read back a jalali_date_select() submission (GET or POST) as a Gregorian 'Y-m-d' string, or null. */
function jalali_request_to_gregorian(string $name): ?string {
    $y = (int)($_REQUEST["{$name}_y"] ?? 0);
    $m = (int)($_REQUEST["{$name}_m"] ?? 0);
    $d = (int)($_REQUEST["{$name}_d"] ?? 0);
    if (!$y || !$m || !$d) return null;
    [$gy, $gm, $gd] = jalali_to_gregorian($y, $m, min($d, 31));
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** Render hour/minute <select> boxes named "{$name}_h" / "{$name}_i". */
function time_select(string $name, ?string $defaultHi = null): string {
    [$dh, $di] = $defaultHi ? array_map('intval', explode(':', $defaultHi)) : [(int)date('H'), 0];
    $h = '<span class="jtime">';
    $h .= '<select name="' . e($name) . '_h" class="jtime-h">';
    for ($x = 0; $x < 24; $x++) $h .= '<option value="' . $x . '"' . ($x === $dh ? ' selected' : '') . '>' . to_persian_digits(str_pad((string)$x, 2, '0', STR_PAD_LEFT)) . '</option>';
    $h .= '</select><span class="jtime-sep">:</span><select name="' . e($name) . '_i" class="jtime-i">';
    for ($x = 0; $x < 60; $x += 5) $h .= '<option value="' . $x . '"' . ($x === $di ? ' selected' : '') . '>' . to_persian_digits(str_pad((string)$x, 2, '0', STR_PAD_LEFT)) . '</option>';
    $h .= '</select></span>';
    return $h;
}

/** Read back a time_select() submission as 'H:i', or null. */
function time_post(string $name): ?string {
    if (!isset($_POST["{$name}_h"], $_POST["{$name}_i"])) return null;
    return sprintf('%02d:%02d', (int)$_POST["{$name}_h"], (int)$_POST["{$name}_i"]);
}

/* ==========================================================================
   KYC document uploads
   Stored OUTSIDE the public web root (APP_ROOT/storage/kyc) so a photo of
   someone's ID card is never reachable by a guessed/shared URL — every
   read goes through public/kyc-photo.php, which checks the viewer is
   either that user or an admin before streaming the file.
   ========================================================================== */

define('KYC_STORAGE_DIR', APP_ROOT . '/storage/kyc');
define('KYC_MAX_BYTES', 8 * 1024 * 1024); // 8MB
const KYC_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

/**
 * Validate and store an uploaded KYC document from $_FILES[$field].
 * Returns the stored filename (to save in ellsms_user_kyc) on success,
 * null if no file was submitted, or throws a RuntimeException with a
 * Persian message on validation failure (caller shows it as a flash).
 */
function kyc_store_upload(string $field, int $userId): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری فایل با خطا مواجه شد.');
    }
    if ($f['size'] > KYC_MAX_BYTES) {
        throw new RuntimeException('حجم فایل نباید بیشتر از ۸ مگابایت باشد.');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    if ($mime === '') {
        // fileinfo unavailable for some reason — fall back to the
        // extension the browser reported. Weaker, but better than
        // rejecting every upload outright.
        $extGuess = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($extGuess === 'jpeg') $extGuess = 'jpg';
        $extToMime = array_flip(KYC_ALLOWED_MIME);
        $mime = $extToMime[$extGuess] ?? '';
    }
    if (!isset(KYC_ALLOWED_MIME[$mime])) {
        throw new RuntimeException('فرمت فایل باید JPG، PNG، WEBP یا PDF باشد.');
    }
    if (!is_dir(KYC_STORAGE_DIR)) {
        mkdir(KYC_STORAGE_DIR, 0750, true);
    }
    $ext = KYC_ALLOWED_MIME[$mime];
    $name = 'u' . $userId . '_' . $field . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], KYC_STORAGE_DIR . '/' . $name)) {
        throw new RuntimeException('ذخیره‌ی فایل ممکن نشد.');
    }
    return $name;
}

/* ==========================================================================
   SMS-based two-factor login
   (send_2fa_code() / verify_2fa_code() live in app/backend.php — they call
   dispatch_message(), which is defined there.)
   ========================================================================== */

const TWOFA_CODE_TTL_SECONDS = 300; // 5 minutes
const TWOFA_RESEND_COOLDOWN  = 60;  // seconds between resend requests
