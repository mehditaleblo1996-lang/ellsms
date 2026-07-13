<?php
/**
 * ELLSMS — Smart SMS Panel
 * Bootstrap: environment, database, session, helpers.
 */

declare(strict_types=1);

define('ELLSMS_VERSION', '1.0.0');
define('APP_ROOT', dirname(__DIR__));

/* ---------- Environment ---------- */
function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/* ---------- Database ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = env('DB_HOST', 'db');
        $name = env('DB_NAME', 'ellsms');
        $user = env('DB_USER', 'ellsms');
        $pass = env('DB_PASS', 'ellsms_secret');
        $dsn  = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo  = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/* ---------- Settings (key/value, cached per request) ---------- */
function setting(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT skey, svalue FROM settings') as $row) {
            $cache[$row['skey']] = $row['svalue'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, $value]);
}

/* ---------- Session & Auth ---------- */
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_name('ELLSMS_SESSION');
    session_start();
}

function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
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
        exit('403 — Admins only.');
    }
    return $u;
}

function is_admin(): bool {
    $u = current_user();
    return $u && $u['role'] === 'admin';
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
            exit('Invalid request token. Go back and try again.');
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
    if ($n === '' ) return null;
    if (str_starts_with($n, '+'))  $n = substr($n, 1);
    if (str_starts_with($n, '00')) $n = substr($n, 2);
    if (str_starts_with($n, '09') && strlen($n) === 11) $n = '98' . substr($n, 1);
    if (str_starts_with($n, '9') && strlen($n) === 10)  $n = '98' . $n;
    return preg_match('/^\d{10,15}$/', $n) ? $n : null;
}

/** Parse a textarea / CSV of numbers into a unique normalized list. */
function parse_destinations(string $raw): array {
    $parts = preg_split('/[\s,;،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $n = normalize_msisdn($p);
        if ($n) $out[$n] = true;
    }
    return array_keys($out);
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
    $st = db()->prepare('INSERT INTO audit_log (user_id, action, details, ip) VALUES (?,?,?,?)');
    $st->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'cli']);
}
