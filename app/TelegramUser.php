<?php
/**
 * Telegram user-account service — the panel side (docs/telegram-user-service.md).
 *
 * A customer connects their OWN Telegram account(s) (phone + login code + optional 2FA password) and
 * the tg-worker container (tg-worker/, Python + Telethon) uses them to:
 *   - check which numbers of a list have Telegram (lookup jobs),
 *   - send OTP / notification / promotional messages (campaigns; one API message is a campaign of one),
 *   - follow each sent message until it is read.
 *
 * Split of responsibilities — the two sides only meet in the ellsms_tg_* tables
 * (db/migrations/2026_11_01_telegram_user_service.sql):
 *   panel  (this file): who may use it and how much (admin limits), money (reserve on create, commit
 *                       per successful unit, release the rest), webhooks, API, pages;
 *   worker (Python):    every call to Telegram — login, logout, contact import, send, read receipts,
 *                       per-account daily counters, flood handling.
 *
 * Money mirrors the Bale channel: the worst case (price × units) is reserved when a campaign or a
 * lookup is created, each unit that succeeded is committed by tg_settle_pass() (run by cron/worker.php),
 * and whatever is left is released once the campaign/lookup is finished or cancelled. A unit that
 * failed costs nothing. Platform admins send for free (same rule as Bale).
 *
 * Secrets: the login 2FA password travels panel -> worker encrypted with tg_crypto_key() (AES-256-GCM,
 * format "v1:" . base64(nonce12 | ciphertext | tag16) — the worker uses the identical format); the
 * Telethon session is written by the worker with the same key and is never decrypted by the panel.
 */

declare(strict_types=1);

const TG_KINDS = ['otp' => 'کد تایید (OTP)', 'notify' => 'اطلاع‌رسانی', 'promo' => 'تبلیغاتی'];
const TG_MAX_RECIPIENTS = 20000;
const TG_MAX_TEXT = 4096;
const TG_MAX_CAPTION = 1024;
const TG_MEDIA_MAX_BYTES = 20 * 1024 * 1024;
const TG_MEDIA_PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp'];
const TG_MEDIA_DOCUMENT_EXT = ['pdf', 'zip', 'rar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'mp3', 'mp4', 'ogg', 'gif'];
const TG_LOGIN_TTL_SECONDS = 600;
const TG_WORKER_ONLINE_SECONDS = 90;

const TG_ERROR_LABELS = [
    'not_on_telegram'    => 'تلگرام ندارد یا با شماره پیدا نمی‌شود',
    'privacy_restricted' => 'تنظیمات حریم خصوصی گیرنده اجازه نمی‌دهد',
    'blocked'            => 'گیرنده این اکانت را مسدود کرده است',
    'deactivated'        => 'اکانت گیرنده حذف شده است',
    'account_limited'    => 'اکانت فرستنده موقتاً از طرف تلگرام محدود شده است',
    'no_account'         => 'اکانت فعالی برای ارسال در دسترس نبود',
    'daily_limit'        => 'سقف روزانه‌ی اکانت‌ها پر شده بود',
    'flood_wait'         => 'تلگرام درخواست توقف موقت داد',
    'media_missing'      => 'فایل پیوست پیدا نشد',
    'interrupted'        => 'ارسال نیمه‌کاره ماند (وضعیت نامعلوم)',
    'invalid_phone'      => 'شماره نامعتبر است',
    'error'              => 'خطای ناشناخته',
];

/* ==========================================================================
   Configuration
   ========================================================================== */

function tg_setting(string $key, string $default): string {
    return (string)setting_fresh($key, $default);
}

function tg_setting_int(string $key, int $default): int {
    $v = setting_fresh($key, null);
    return ($v !== null && is_numeric($v)) ? max(0, (int)$v) : $default;
}

/** Defaults an admin can change on /telegram-admin.php; a customer's own row overrides each one. */
function tg_defaults(): array {
    return [
        'max_accounts'         => tg_setting_int('tg_default_max_accounts', 1),
        'daily_send_limit'     => tg_setting_int('tg_default_daily_send', 50),
        'daily_check_limit'    => tg_setting_int('tg_default_daily_check', 100),
        'min_interval_seconds' => tg_setting_int('tg_default_interval', 15),
        'price_text'           => tg_setting_int('tg_price_text', 10),
        'price_media'          => tg_setting_int('tg_price_media', 20),
        'price_check'          => tg_setting_int('tg_price_check', 10),
    ];
}

/**
 * Why the service cannot be used right now, in words an admin can act on — or null when it can.
 * Shown instead of a bare «not active» so the cause (migration missing, switch off) is obvious.
 */
function tg_unavailable_reason(): ?string {
    if (!tg_schema_ready()) {
        return 'جدول‌های سرویس تلگرام هنوز ساخته نشده‌اند (مایگریشن 2026_11_01_telegram_user_service.sql اعمال نشده است).';
    }
    try {
        if (tg_setting('tg_enabled', '0') !== '1') {
            return 'سرویس تلگرام خاموش است: مدیر سامانه باید در «مدیریت › سرویس تلگرام» تیک «سرویس فعال باشد» را بزند و ذخیره کند.';
        }
    } catch (Throwable) {
        return 'تنظیمات سرویس تلگرام خوانده نشد.';
    }
    return null;
}

/** The service is switched on by a platform admin (and its schema exists). */
function tg_service_enabled(): bool {
    try {
        return tg_setting('tg_enabled', '0') === '1' && tg_schema_ready();
    } catch (Throwable) {
        return false;
    }
}

function tg_schema_ready(): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_tg_accounts LIMIT 1');
            $ready = true;
        } catch (PDOException) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * 32-byte key shared with the worker. TELEGRAM_SESSION_KEY (64 hex chars) when set; otherwise derived
 * from SMS_GATEWAY_MASTER_KEY with a domain-separating prefix, so a deployment that already has that
 * key needs nothing new. Null when neither is configured (the service then refuses to start a login).
 */
function tg_crypto_key(): ?string {
    $own = trim((string)env('TELEGRAM_SESSION_KEY', ''));
    if ($own !== '') {
        return (strlen($own) === 64 && ctype_xdigit($own)) ? (string)hex2bin($own) : hash('sha256', 'ellsms-telegram-session-v1|' . $own, true);
    }
    $master = trim((string)env('SMS_GATEWAY_MASTER_KEY', ''));
    return $master !== '' ? hash('sha256', 'ellsms-telegram-session-v1|' . $master, true) : null;
}

function tg_encrypt(string $plain): string {
    $key = tg_crypto_key();
    if ($key === null) {
        throw new RuntimeException('Telegram service key is not configured (TELEGRAM_SESSION_KEY or SMS_GATEWAY_MASTER_KEY).');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return 'v1:' . base64_encode($nonce . $cipher . $tag);
}

function tg_decrypt(string $sealed): ?string {
    $key = tg_crypto_key();
    if ($key === null || !str_starts_with($sealed, 'v1:')) return null;
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false || strlen($raw) < 28) return null;
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, -16);
    $cipher = substr($raw, 12, -16);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plain === false ? null : $plain;
}

/** The worker's heartbeat: online when it reported within TG_WORKER_ONLINE_SECONDS. */
function tg_worker_status(): array {
    $out = ['online' => false, 'last_seen_at' => null, 'api_configured' => false, 'crypto_ready' => false, 'version' => null, 'connected_accounts' => 0, 'note' => null];
    try {
        $row = db()->query('SELECT * FROM ellsms_tg_worker_status WHERE id = 1')->fetch();
    } catch (PDOException) {
        return $out;
    }
    if (!$row) return $out;
    $seen = strtotime((string)$row['last_seen_at']) ?: 0;
    return [
        'online'             => time() - $seen <= TG_WORKER_ONLINE_SECONDS,
        'last_seen_at'       => (string)$row['last_seen_at'],
        'api_configured'     => (int)$row['api_configured'] === 1,
        'crypto_ready'       => (int)$row['crypto_ready'] === 1,
        'version'            => $row['version'],
        'connected_accounts' => (int)$row['connected_accounts'],
        'note'               => $row['note'],
    ];
}

/** A customer's own settings row, or null. */
function tg_user_settings_row(int $userId): ?array {
    if (!tg_schema_ready()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_tg_user_settings WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/**
 * Effective limits and prices for $user: the admin's per-customer values over the defaults.
 * 'enabled' is the per-customer switch; platform admins are always enabled and pay nothing.
 */
function tg_user_config(array $user): array {
    $userId = (int)($user['id'] ?? 0);
    $isAdmin = ($user['role'] ?? null) === 'admin';
    $config = tg_defaults();
    $row = tg_user_settings_row($userId);
    foreach (array_keys($config) as $key) {
        if ($row !== null && $row[$key] !== null) {
            $config[$key] = (int)$row[$key];
        }
    }
    $config['enabled'] = $isAdmin || ($row !== null && (int)$row['enabled'] === 1);
    if ($isAdmin) {
        $config['price_text'] = 0;
        $config['price_media'] = 0;
        $config['price_check'] = 0;
        $config['max_accounts'] = max($config['max_accounts'], 10);
    }
    $config['min_interval_seconds'] = max(3, $config['min_interval_seconds']);
    return $config;
}

/** Whether $user may open the Telegram pages and API at all. */
function tg_user_allowed(array $user): bool {
    return tg_service_enabled() && tg_user_config($user)['enabled'];
}

/** Shown in the sidebar: admins always (to set it up), customers once the service is on for them. */
function tg_menu_visible(array $user): bool {
    if (($user['role'] ?? null) === 'admin') return tg_schema_ready();
    try {
        return tg_user_allowed($user);
    } catch (Throwable) {
        return false;
    }
}

/** A notice for the top of the Telegram pages while the service cannot be used (admins get the link). */
function tg_unavailable_banner(array $user): string {
    $reason = tg_unavailable_reason();
    if ($reason === null) return '';
    $link = ($user['role'] ?? null) === 'admin' ? ' <a href="/admin/telegram">رفتن به تنظیمات سرویس تلگرام</a>' : '';
    return '<div class="flash flash-error"><span class="flash-text">' . e($reason) . $link . '</span></div>';
}

/** Ends a page request for a customer the service is not open to. */
function tg_require_user(array $user): void {
    if (!tg_schema_ready()) {
        http_response_code(503);
        exit('سرویس تلگرام هنوز نصب نشده است (مایگریشن پایگاه‌داده اعمال نشده).');
    }
    if (($user['role'] ?? null) === 'admin') return;
    if (!tg_user_allowed($user)) {
        http_response_code(403);
        exit('سرویس ارسال با تلگرام برای حساب شما فعال نیست. برای فعال‌سازی با پشتیبانی تماس بگیرید.');
    }
}

/* ==========================================================================
   Small helpers
   ========================================================================== */

function tg_now(): string {
    return date('Y-m-d H:i:s');
}

/** International digits (98912…) or null. Accepts any country code; Iranian local forms are rewritten. */
function tg_normalize_phone(string $raw): ?string {
    return normalize_msisdn(from_persian_digits($raw));
}

/** Unique normalized numbers from free text (one per line, or separated by comma / space). */
function tg_parse_phones(string $raw): array {
    return parse_destinations(from_persian_digits($raw));
}

function tg_mask_phone(string $phone): string {
    $len = strlen($phone);
    return $len <= 6 ? $phone : substr($phone, 0, 5) . str_repeat('•', max(0, $len - 8)) . substr($phone, -3);
}

function tg_error_label(?string $code): string {
    return TG_ERROR_LABELS[(string)$code] ?? (string)$code;
}

function tg_status_label(string $status): string {
    return match ($status) {
        'queued'      => 'در صف',
        'sending'     => 'در حال ارسال',
        'sent'        => 'ارسال شد',
        'read'        => 'خوانده شد',
        'failed'      => 'ناموفق',
        'cancelled'   => 'لغو شد',
        'checking'    => 'در حال بررسی',
        'found'       => 'تلگرام دارد',
        'not_found'   => 'پیدا نشد',
        'running'     => 'در حال اجرا',
        'paused'      => 'متوقف',
        'completed'   => 'تمام شد',
        'active'      => 'فعال',
        'limited'     => 'محدود موقت',
        'disconnected'=> 'قطع',
        'error'       => 'خطا',
        default       => $status,
    };
}

function tg_status_badge(string $status): string {
    $class = match ($status) {
        'sent' => 'badge-sent',
        'read', 'found', 'completed' => 'badge-delivered',
        'active' => 'badge-active',
        'failed', 'error' => 'badge-failed',
        'disconnected', 'not_found' => 'badge-off',
        'cancelled' => 'badge-cancelled',
        'sending', 'checking', 'running' => 'badge-processing',
        default => 'badge-pending',
    };
    return '<span class="badge ' . $class . '">' . e(tg_status_label($status)) . '</span>';
}

/** The owner scope used by list pages: a customer sees only their own rows; an admin may pass null for all. */
function tg_owner_clause(array $user, string $column = 'user_id'): array {
    if (($user['role'] ?? null) === 'admin') return ['1=1', []];
    return ["{$column} = ?", [(int)$user['id']]];
}

/* ==========================================================================
   Accounts and login
   ========================================================================== */

/** @return list<array> the user's accounts (never the session), newest first. */
function tg_accounts_for_user(int $userId, bool $activeOnly = false): array {
    $sql = 'SELECT id, user_id, phone, tg_user_id, tg_username, display_name, status, limited_until, last_error,
                   counter_date, sent_today, checked_today, last_action_at, logout_requested, created_at, disconnected_at
            FROM ellsms_tg_accounts WHERE user_id = ?';
    if ($activeOnly) $sql .= " AND status IN ('active','limited') AND logout_requested = 0";
    $st = db()->prepare($sql . ' ORDER BY id DESC');
    $st->execute([$userId]);
    $rows = $st->fetchAll();
    $today = date('Y-m-d');
    foreach ($rows as &$row) {
        if ((string)$row['counter_date'] !== $today) {
            $row['sent_today'] = 0;
            $row['checked_today'] = 0;
        }
    }
    return $rows;
}

function tg_account_find(int $userId, int $accountId): ?array {
    foreach (tg_accounts_for_user($userId) as $account) {
        if ((int)$account['id'] === $accountId) return $account;
    }
    return null;
}

/** Accounts that count against max_accounts: anything not disconnected. */
function tg_connected_account_count(int $userId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM ellsms_tg_accounts WHERE user_id = ? AND status <> 'disconnected' AND logout_requested = 0");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/**
 * Starts connecting $phone: the worker asks Telegram to send a login code to that account's Telegram
 * app. @return array{ok:bool, id?:int, error?:string}
 */
function tg_login_start(array $user, string $rawPhone): array {
    if (($reason = tg_unavailable_reason()) !== null) return ['ok' => false, 'error' => $reason];
    if (tg_crypto_key() === null) return ['ok' => false, 'error' => 'کلید رمزنگاری سرویس تلگرام تنظیم نشده است؛ به مدیر سامانه اطلاع دهید.'];
    if (!impersonation_action_allowed('send.direct')) {
        return ['ok' => false, 'error' => 'در حالت پشتیبانی نمی‌توان اکانت تلگرام متصل کرد.'];
    }
    $phone = tg_normalize_phone($rawPhone);
    if ($phone === null) return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.'];
    $userId = (int)$user['id'];
    $config = tg_user_config($user);

    $existing = db()->prepare("SELECT id, status, logout_requested FROM ellsms_tg_accounts WHERE user_id = ? AND phone = ?");
    $existing->execute([$userId, $phone]);
    $existingRow = $existing->fetch();
    $reconnect = $existingRow && $existingRow['status'] !== 'disconnected' && (int)$existingRow['logout_requested'] === 0;
    if (!$reconnect && tg_connected_account_count($userId) >= $config['max_accounts']) {
        return ['ok' => false, 'error' => 'به سقف تعداد اکانت مجاز (' . to_persian_digits((string)$config['max_accounts']) . ') رسیده‌اید. برای اکانت بیشتر با پشتیبانی تماس بگیرید.'];
    }
    if (!rate_limit_hit(rate_limit_bucket('tg_login', 'user', (string)$userId), 6, 3600)) {
        return ['ok' => false, 'error' => 'درخواست ورود زیادی در یک ساعت ثبت شده است؛ کمی بعد دوباره تلاش کنید.'];
    }
    // Only one live attempt per phone: a new one supersedes the old.
    db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'expired', code = NULL, password_enc = NULL, temp_session_enc = NULL, updated_at = ?
                   WHERE user_id = ? AND phone = ? AND state NOT IN ('done','failed','expired')")
        ->execute([tg_now(), $userId, $phone]);
    db()->prepare('INSERT INTO ellsms_tg_login_requests (user_id, phone, state, created_at, updated_at, expires_at) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $phone, 'requested', tg_now(), tg_now(), date('Y-m-d H:i:s', time() + TG_LOGIN_TTL_SECONDS)]);
    $id = (int)db()->lastInsertId();
    audit($userId, 'telegram.login.start', 'request=' . $id . ' phone=' . tg_mask_phone($phone));
    return ['ok' => true, 'id' => $id];
}

function tg_login_find(int $userId, int $requestId): ?array {
    $st = db()->prepare('SELECT id, user_id, phone, state, password_hint, error_code, error, account_id, created_at, updated_at, expires_at
                         FROM ellsms_tg_login_requests WHERE id = ? AND user_id = ?');
    $st->execute([$requestId, $userId]);
    $row = $st->fetch();
    if ($row && !in_array($row['state'], ['done', 'failed', 'expired'], true) && strtotime((string)$row['expires_at']) < time()) {
        db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'expired', code = NULL, password_enc = NULL, temp_session_enc = NULL, updated_at = ? WHERE id = ?")
            ->execute([tg_now(), $requestId]);
        $row['state'] = 'expired';
    }
    return $row ?: null;
}

function tg_login_submit_code(array $user, int $requestId, string $rawCode): array {
    $code = preg_replace('/\D/', '', from_persian_digits($rawCode)) ?? '';
    if (strlen($code) < 4 || strlen($code) > 8) return ['ok' => false, 'error' => 'کد ورود باید ۴ تا ۸ رقم باشد.'];
    $st = db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'code_submitted', code = ?, error = NULL, error_code = NULL, updated_at = ?
                         WHERE id = ? AND user_id = ? AND state = 'code_sent' AND expires_at > ?");
    $st->execute([$code, tg_now(), $requestId, (int)$user['id'], tg_now()]);
    return $st->rowCount() === 1 ? ['ok' => true] : ['ok' => false, 'error' => 'این مرحله‌ی ورود دیگر معتبر نیست؛ از ابتدا شروع کنید.'];
}

function tg_login_submit_password(array $user, int $requestId, string $password): array {
    if ($password === '' || strlen($password) > 256) return ['ok' => false, 'error' => 'رمز دومرحله‌ای را وارد کنید.'];
    $st = db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'password_submitted', password_enc = ?, error = NULL, error_code = NULL, updated_at = ?
                         WHERE id = ? AND user_id = ? AND state = 'password_needed' AND expires_at > ?");
    $st->execute([tg_encrypt($password), tg_now(), $requestId, (int)$user['id'], tg_now()]);
    return $st->rowCount() === 1 ? ['ok' => true] : ['ok' => false, 'error' => 'این مرحله‌ی ورود دیگر معتبر نیست؛ از ابتدا شروع کنید.'];
}

function tg_login_cancel(array $user, int $requestId): void {
    db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'expired', code = NULL, password_enc = NULL, temp_session_enc = NULL, updated_at = ?
                   WHERE id = ? AND user_id = ? AND state NOT IN ('done','failed','expired')")
        ->execute([tg_now(), $requestId, (int)$user['id']]);
}

/**
 * Asks the worker to log the account out of Telegram (ending the session on Telegram's side too) and
 * wipe the stored session. Queued messages assigned only to this account go back to any other account.
 */
function tg_account_disconnect(int $userId, int $accountId, int $actorId): bool {
    $st = db()->prepare("UPDATE ellsms_tg_accounts SET logout_requested = 1, updated_at = ? WHERE id = ? AND user_id = ? AND status <> 'disconnected'");
    $st->execute([tg_now(), $accountId, $userId]);
    if ($st->rowCount() !== 1) return false;
    audit($actorId, 'telegram.account.disconnect', 'account=' . $accountId . ' owner=' . $userId);
    return true;
}

/** Removes a disconnected account's row entirely (its history stays on the messages). */
function tg_account_forget(int $userId, int $accountId, int $actorId): bool {
    $st = db()->prepare("DELETE FROM ellsms_tg_accounts WHERE id = ? AND user_id = ? AND status = 'disconnected' AND session_enc IS NULL");
    $st->execute([$accountId, $userId]);
    if ($st->rowCount() === 1) {
        audit($actorId, 'telegram.account.forget', 'account=' . $accountId . ' owner=' . $userId);
        return true;
    }
    return false;
}

/* ==========================================================================
   Media
   ========================================================================== */

function tg_media_root(): string {
    return dirname(__DIR__) . '/storage/telegram-media';
}

/**
 * Stores one uploaded file for a campaign. @return array{ok:bool, path?:string, name?:string, kind?:string, error?:string}
 * path is relative to tg_media_root() (the worker mounts that directory read-only).
 */
function tg_media_store(int $userId, array $upload): array {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => true];
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
        return ['ok' => false, 'error' => 'بارگذاری فایل ناموفق بود.'];
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size <= 0 || $size > TG_MEDIA_MAX_BYTES) return ['ok' => false, 'error' => 'حجم فایل باید حداکثر ۲۰ مگابایت باشد.'];
    $name = basename((string)($upload['name'] ?? 'file'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, TG_MEDIA_PHOTO_EXT, true)) {
        if (@getimagesize((string)$upload['tmp_name']) === false) return ['ok' => false, 'error' => 'فایل تصویر معتبر نیست.'];
        $kind = 'photo';
    } elseif (in_array($ext, TG_MEDIA_DOCUMENT_EXT, true)) {
        $kind = 'document';
    } else {
        return ['ok' => false, 'error' => 'نوع فایل مجاز نیست. تصویر (jpg, png, webp) یا فایل (pdf, zip, docx, xlsx, mp4, …) بفرستید.'];
    }
    $dir = tg_media_root() . '/u' . $userId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'پوشه‌ی ذخیره‌ی فایل ساخته نشد.'];
    }
    $relative = 'u' . $userId . '/' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string)$upload['tmp_name'], tg_media_root() . '/' . $relative)) {
        return ['ok' => false, 'error' => 'ذخیره‌ی فایل ناموفق بود.'];
    }
    @chmod(tg_media_root() . '/' . $relative, 0644);
    $cleanName = mb_substr(preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $name) ?? 'file', 0, 150);
    return ['ok' => true, 'path' => $relative, 'name' => $cleanName, 'kind' => $kind];
}

/* ==========================================================================
   Campaigns (sending)
   ========================================================================== */

/**
 * Whether a send of $count recipients of $kind needs the "your account may be blocked" acknowledgement:
 * always for promotional sends, and for any send larger than the admin's threshold.
 */
function tg_send_needs_ack(string $kind, int $count): bool {
    return $kind === 'promo' || $count > tg_setting_int('tg_promo_warn_threshold', 30);
}

/**
 * Creates a campaign and reserves its worst-case cost.
 *
 * $opts: kind, title, account_id (0/null = any of the user's active accounts), content, media
 * (array{path,name,kind}|null), recipients (normalized list), acknowledged (bool), source ('panel'|'api'),
 * api_key_id, client_message_id.
 *
 * @return array{ok:bool, id?:int, message_ids?:list<int>, cost?:int, error?:string, code?:string}
 */
function tg_campaign_create(array $user, array $opts): array {
    $fail = static fn(string $code, string $error): array => ['ok' => false, 'code' => $code, 'error' => $error];
    if (($reason = tg_unavailable_reason()) !== null) return $fail('not_enabled', $reason);
    $config = tg_user_config($user);
    if (!$config['enabled']) return $fail('not_enabled', 'سرویس تلگرام برای حساب شما فعال نیست.');
    if (!impersonation_action_allowed('send.direct')) {
        impersonation_record_block('send.direct');
        return $fail('impersonation_blocked', impersonation_block_message('send.direct'));
    }
    $userId = (int)$user['id'];
    $kind = array_key_exists((string)($opts['kind'] ?? ''), TG_KINDS) ? (string)$opts['kind'] : 'notify';
    $content = trim(str_replace("\r\n", "\n", (string)($opts['content'] ?? '')));
    $media = is_array($opts['media'] ?? null) && !empty($opts['media']['path']) ? $opts['media'] : null;
    $recipients = array_values(array_unique(array_filter(array_map('strval', (array)($opts['recipients'] ?? [])))));
    $count = count($recipients);

    if ($count === 0) return $fail('no_recipients', 'هیچ شماره‌ی معتبری وارد نشده است.');
    if ($count > TG_MAX_RECIPIENTS) return $fail('too_many', 'حداکثر ' . to_persian_digits(number_format(TG_MAX_RECIPIENTS)) . ' گیرنده در هر ارسال مجاز است.');
    if ($content === '' && $media === null) return $fail('empty', 'متن پیام یا فایل پیوست لازم است.');
    $maxLen = $media !== null ? TG_MAX_CAPTION : TG_MAX_TEXT;
    if (mb_strlen($content) > $maxLen) return $fail('too_long', 'متن پیام حداکثر ' . to_persian_digits((string)$maxLen) . ' نویسه می‌تواند باشد' . ($media !== null ? ' (توضیح زیر فایل).' : '.'));
    if ($content !== '' && ($ruleId = content_policy_violation($content)) !== null) {
        content_policy_log_refusal($ruleId, 'telegram', $userId);
        return $fail('content_prohibited', CONTENT_POLICY_ERROR);
    }
    if (tg_send_needs_ack($kind, $count) && empty($opts['acknowledged'])) {
        return $fail('ack_required', 'برای این ارسال باید هشدار احتمال مسدود شدن اکانت را تأیید کنید.');
    }

    $accountId = (int)($opts['account_id'] ?? 0);
    $active = tg_accounts_for_user($userId, true);
    if ($active === []) return $fail('no_account', 'هیچ اکانت تلگرام فعالی متصل نیست. ابتدا از «اکانت‌های تلگرام» یک اکانت وصل کنید.');
    if ($accountId > 0 && !in_array($accountId, array_map(static fn(array $a): int => (int)$a['id'], $active), true)) {
        return $fail('invalid_account', 'اکانت انتخاب‌شده فعال نیست.');
    }

    $price = $media !== null ? $config['price_media'] : $config['price_text'];
    $worstCase = $price * $count;
    $organizationId = (int)($user['organization_id'] ?? 0) ?: null;
    $now = tg_now();

    try {
        $created = db_transaction(function (PDO $db) use ($opts, $userId, $organizationId, $kind, $accountId, $content, $media, $price, $count, $recipients, $worstCase, $now): array {
            $db->prepare('INSERT INTO ellsms_tg_campaigns
                            (user_id, organization_id, title, kind, source, account_id, content, media_path, media_name, media_kind,
                             price_each, total, status, promo_acknowledged, api_key_id, client_message_id, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([
                   $userId, $organizationId, mb_substr(trim((string)($opts['title'] ?? '')), 0, 190) ?: null, $kind,
                   ($opts['source'] ?? 'panel') === 'api' ? 'api' : 'panel', $accountId > 0 ? $accountId : null,
                   $content !== '' ? $content : null, $media['path'] ?? null, $media['name'] ?? null, $media['kind'] ?? null,
                   $price, $count, 'queued', !empty($opts['acknowledged']) ? 1 : 0,
                   isset($opts['api_key_id']) ? (int)$opts['api_key_id'] : null,
                   isset($opts['client_message_id']) ? mb_substr((string)$opts['client_message_id'], 0, 200) : null, $now,
               ]);
            $campaignId = (int)$db->lastInsertId();
            if ($worstCase > 0) {
                $reservation = wallet_reserve($userId, $worstCase, 'telegram_campaign', (string)$campaignId, 'reserve:telegram_campaign:' . $campaignId);
                if (!$reservation['ok']) {
                    throw new WalletInsufficientBalanceException('insufficient');
                }
            }
            $ids = [];
            foreach (array_chunk($recipients, 500) as $chunk) {
                $rows = [];
                $params = [];
                foreach ($chunk as $phone) {
                    $rows[] = '(?,?,?,?,?,?,?)';
                    array_push($params, $campaignId, $userId, $organizationId, $phone, 'queued', $now, $now);
                }
                $db->prepare('INSERT INTO ellsms_tg_messages (campaign_id, user_id, organization_id, phone, status, created_at, updated_at) VALUES ' . implode(',', $rows))
                   ->execute($params);
            }
            if ($count <= 100) {
                $idSt = $db->prepare('SELECT id FROM ellsms_tg_messages WHERE campaign_id = ? ORDER BY id');
                $idSt->execute([$campaignId]);
                $ids = array_map('intval', $idSt->fetchAll(PDO::FETCH_COLUMN));
            }
            return ['id' => $campaignId, 'message_ids' => $ids];
        });
    } catch (WalletInsufficientBalanceException) {
        return $fail('insufficient_credit', 'اعتبار کافی نیست: این ارسال تا ' . to_persian_digits(number_format($worstCase)) . ' ریال هزینه دارد.');
    }

    Metrics::increment('telegram.campaign.created', 1, ['kind' => $kind]);
    Logger::info('telegram.campaign.created', ['campaign_id' => $created['id'], 'user_id' => $userId, 'recipients' => $count, 'kind' => $kind]);
    return ['ok' => true, 'id' => $created['id'], 'message_ids' => $created['message_ids'], 'cost' => $worstCase];
}

function tg_campaign_find(array $user, int $campaignId): ?array {
    [$where, $params] = tg_owner_clause($user);
    $st = db()->prepare("SELECT * FROM ellsms_tg_campaigns WHERE id = ? AND {$where}");
    $st->execute(array_merge([$campaignId], $params));
    return $st->fetch() ?: null;
}

/** Per-status counts of a campaign's messages. */
function tg_campaign_counts(int $campaignId): array {
    $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'read' => 0, 'failed' => 0, 'cancelled' => 0, 'cost' => 0];
    $st = db()->prepare('SELECT status, COUNT(*) c, SUM(cost) cost FROM ellsms_tg_messages WHERE campaign_id = ? GROUP BY status');
    $st->execute([$campaignId]);
    foreach ($st->fetchAll() as $row) {
        $out[$row['status']] = (int)$row['c'];
        $out['cost'] += (int)$row['cost'];
    }
    return $out;
}

/** Cancels the rest of a campaign: queued messages are dropped (nothing charged for them). */
function tg_campaign_cancel(array $user, int $campaignId): bool {
    $campaign = tg_campaign_find($user, $campaignId);
    if (!$campaign || !in_array($campaign['status'], ['queued', 'running', 'paused'], true)) return false;
    db_transaction(function (PDO $db) use ($campaignId): void {
        $db->prepare("UPDATE ellsms_tg_campaigns SET status = 'cancelled', finished_at = ? WHERE id = ? AND status IN ('queued','running','paused')")
           ->execute([tg_now(), $campaignId]);
        $db->prepare("UPDATE ellsms_tg_messages SET status = 'cancelled', updated_at = ? WHERE campaign_id = ? AND status = 'queued'")
           ->execute([tg_now(), $campaignId]);
    });
    audit((int)$user['id'], 'telegram.campaign.cancel', 'campaign=' . $campaignId);
    return true;
}

function tg_campaign_set_paused(array $user, int $campaignId, bool $paused): bool {
    $campaign = tg_campaign_find($user, $campaignId);
    if (!$campaign) return false;
    $st = $paused
        ? db()->prepare("UPDATE ellsms_tg_campaigns SET status = 'paused' WHERE id = ? AND status IN ('queued','running')")
        : db()->prepare("UPDATE ellsms_tg_campaigns SET status = 'queued' WHERE id = ? AND status = 'paused'");
    $st->execute([$campaignId]);
    return $st->rowCount() === 1;
}

/** One message as the API and webhooks show it. */
function tg_message_public(array $row): array {
    return [
        'id'          => (int)$row['id'],
        'campaign_id' => (int)$row['campaign_id'],
        'to'          => (string)$row['phone'],
        'status'      => (string)$row['status'],
        'error_code'  => $row['error_code'] !== null ? (string)$row['error_code'] : null,
        'error'       => $row['error_code'] !== null ? tg_error_label((string)$row['error_code']) : null,
        'account_id'  => $row['account_id'] !== null ? (int)$row['account_id'] : null,
        'cost'        => (int)$row['cost'],
        'sent_at'     => $row['sent_at'] !== null ? date('c', strtotime((string)$row['sent_at'])) : null,
        'read_at'     => $row['read_at'] !== null ? date('c', strtotime((string)$row['read_at'])) : null,
        'created_at'  => date('c', strtotime((string)$row['created_at'])),
    ];
}

/* ==========================================================================
   Lookups ("who has Telegram")
   ========================================================================== */

/** @return array{ok:bool, id?:int, cost?:int, error?:string, code?:string} */
function tg_lookup_create(array $user, array $phones, string $title = '', int $accountId = 0): array {
    $fail = static fn(string $code, string $error): array => ['ok' => false, 'code' => $code, 'error' => $error];
    if (($reason = tg_unavailable_reason()) !== null) return $fail('not_enabled', $reason);
    $config = tg_user_config($user);
    if (!$config['enabled']) return $fail('not_enabled', 'سرویس تلگرام برای حساب شما فعال نیست.');
    if (!impersonation_action_allowed('send.direct')) {
        return $fail('impersonation_blocked', impersonation_block_message('send.direct'));
    }
    $userId = (int)$user['id'];
    $phones = array_values(array_unique(array_filter(array_map('strval', $phones))));
    $count = count($phones);
    if ($count === 0) return $fail('no_numbers', 'هیچ شماره‌ی معتبری وارد نشده است.');
    if ($count > TG_MAX_RECIPIENTS) return $fail('too_many', 'حداکثر ' . to_persian_digits(number_format(TG_MAX_RECIPIENTS)) . ' شماره در هر بررسی مجاز است.');
    $active = tg_accounts_for_user($userId, true);
    if ($active === []) return $fail('no_account', 'هیچ اکانت تلگرام فعالی متصل نیست.');
    if ($accountId > 0 && !in_array($accountId, array_map(static fn(array $a): int => (int)$a['id'], $active), true)) {
        return $fail('invalid_account', 'اکانت انتخاب‌شده فعال نیست.');
    }
    $price = $config['price_check'];
    $worstCase = $price * $count;
    $organizationId = (int)($user['organization_id'] ?? 0) ?: null;
    $now = tg_now();
    try {
        $id = db_transaction(function (PDO $db) use ($userId, $organizationId, $title, $accountId, $price, $count, $phones, $worstCase, $now): int {
            $db->prepare('INSERT INTO ellsms_tg_lookup_jobs (user_id, organization_id, title, account_id, price_each, total, status, created_at) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$userId, $organizationId, mb_substr(trim($title), 0, 190) ?: null, $accountId > 0 ? $accountId : null, $price, $count, 'queued', $now]);
            $jobId = (int)$db->lastInsertId();
            if ($worstCase > 0) {
                $reservation = wallet_reserve($userId, $worstCase, 'telegram_lookup', (string)$jobId, 'reserve:telegram_lookup:' . $jobId);
                if (!$reservation['ok']) throw new WalletInsufficientBalanceException('insufficient');
            }
            foreach (array_chunk($phones, 500) as $chunk) {
                $rows = [];
                $params = [];
                foreach ($chunk as $phone) {
                    $rows[] = '(?,?,?,?)';
                    array_push($params, $jobId, $userId, $phone, 'queued');
                }
                $db->prepare('INSERT INTO ellsms_tg_lookup_items (job_id, user_id, phone, status) VALUES ' . implode(',', $rows))->execute($params);
            }
            return $jobId;
        });
    } catch (WalletInsufficientBalanceException) {
        return $fail('insufficient_credit', 'اعتبار کافی نیست: این بررسی تا ' . to_persian_digits(number_format($worstCase)) . ' ریال هزینه دارد.');
    }
    Logger::info('telegram.lookup.created', ['job_id' => $id, 'user_id' => $userId, 'numbers' => $count]);
    return ['ok' => true, 'id' => $id, 'cost' => $worstCase];
}

function tg_lookup_find(array $user, int $jobId): ?array {
    [$where, $params] = tg_owner_clause($user);
    $st = db()->prepare("SELECT * FROM ellsms_tg_lookup_jobs WHERE id = ? AND {$where}");
    $st->execute(array_merge([$jobId], $params));
    return $st->fetch() ?: null;
}

function tg_lookup_counts(int $jobId): array {
    $out = ['queued' => 0, 'checking' => 0, 'found' => 0, 'not_found' => 0, 'failed' => 0, 'cancelled' => 0];
    $st = db()->prepare('SELECT status, COUNT(*) c FROM ellsms_tg_lookup_items WHERE job_id = ? GROUP BY status');
    $st->execute([$jobId]);
    foreach ($st->fetchAll() as $row) $out[$row['status']] = (int)$row['c'];
    return $out;
}

function tg_lookup_cancel(array $user, int $jobId): bool {
    $job = tg_lookup_find($user, $jobId);
    if (!$job || !in_array($job['status'], ['queued', 'running'], true)) return false;
    db_transaction(function (PDO $db) use ($jobId): void {
        $db->prepare("UPDATE ellsms_tg_lookup_jobs SET status = 'cancelled', finished_at = ? WHERE id = ? AND status IN ('queued','running')")->execute([tg_now(), $jobId]);
        $db->prepare("UPDATE ellsms_tg_lookup_items SET status = 'cancelled' WHERE job_id = ? AND status = 'queued'")->execute([$jobId]);
    });
    audit((int)$user['id'], 'telegram.lookup.cancel', 'job=' . $jobId);
    return true;
}

/* ==========================================================================
   Settlement + webhooks (cron/worker.php calls tg_settle_pass() every loop)
   ========================================================================== */

/**
 * Commits the price of every newly successful unit, finishes campaigns/lookups with nothing left to do,
 * releases their unused reservation, emits webhooks for message state changes, and fails messages a
 * crashed worker left in 'sending'. Idempotent and safe to run from several processes.
 *
 * @return array{charged:int, finished:int, events:int}
 */
function tg_settle_pass(int $limit = 1000): array {
    $out = ['charged' => 0, 'finished' => 0, 'events' => 0];
    if (!tg_schema_ready()) return $out;
    $now = tg_now();

    // 1. A message stuck in 'sending' for 10 minutes: the worker died mid-call. Its outcome is unknown,
    //    so it is failed and not charged — never retried (that could deliver it twice).
    db()->prepare("UPDATE ellsms_tg_messages SET status = 'failed', error_code = 'interrupted', updated_at = ?
                   WHERE status = 'sending' AND claimed_at < ?")
        ->execute([$now, date('Y-m-d H:i:s', time() - 600)]);
    db()->prepare("UPDATE ellsms_tg_lookup_items SET status = 'queued', account_id = NULL WHERE status = 'checking' AND checked_at < ?")
        ->execute([date('Y-m-d H:i:s', time() - 600)]);

    // 1b. Messages that can no longer go anywhere: their campaign is pinned to an account that was
    //     disconnected, or they are OTPs nobody could send within 5 minutes (a late code is useless —
    //     failing it lets the caller fall back to SMS).
    db()->prepare("UPDATE ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id JOIN ellsms_tg_accounts a ON a.id = c.account_id
                   SET m.status = 'failed', m.error_code = 'no_account', m.updated_at = ?
                   WHERE m.status = 'queued' AND (a.status IN ('disconnected','error') OR a.logout_requested = 1)")
        ->execute([$now]);
    db()->prepare("UPDATE ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id
                   SET m.status = 'failed', m.error_code = 'no_account', m.updated_at = ?
                   WHERE m.status = 'queued' AND c.kind = 'otp' AND m.created_at < ?")
        ->execute([$now, date('Y-m-d H:i:s', time() - 300)]);
    db()->prepare("UPDATE ellsms_tg_lookup_items i JOIN ellsms_tg_lookup_jobs j ON j.id = i.job_id JOIN ellsms_tg_accounts a ON a.id = j.account_id
                   SET i.status = 'failed', i.error = 'اکانت انتخاب‌شده قطع شد'
                   WHERE i.status = 'queued' AND (a.status IN ('disconnected','error') OR a.logout_requested = 1)")
        ->execute();

    // 2. Charge successful messages, grouped by campaign (one reservation each).
    $st = db()->prepare("SELECT m.id, m.campaign_id, c.price_each FROM ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id
                         WHERE m.charged = 0 AND m.status IN ('sent','read') ORDER BY m.id LIMIT " . (int)$limit);
    $st->execute();
    $byCampaign = [];
    foreach ($st->fetchAll() as $row) $byCampaign[(int)$row['campaign_id']][] = $row;
    foreach ($byCampaign as $campaignId => $rows) {
        $lines = [];
        foreach ($rows as $row) {
            if ((int)$row['price_each'] > 0) $lines[] = ['key' => 'commit:telegram_message:' . $row['id'], 'amount' => (int)$row['price_each']];
        }
        if ($lines !== []) wallet_commit_reservation_batch('telegram_campaign', (string)$campaignId, $lines);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id SET m.charged = 1, m.cost = c.price_each WHERE m.id IN ({$in})")
            ->execute($ids);
        $out['charged'] += count($ids);
    }
    // Failed / cancelled messages are simply marked as handled (cost 0).
    db()->prepare("UPDATE ellsms_tg_messages SET charged = 1 WHERE charged = 0 AND status IN ('failed','cancelled')")->execute();

    // 3. Same for lookup items.
    $st = db()->prepare("SELECT i.id, i.job_id, j.price_each FROM ellsms_tg_lookup_items i JOIN ellsms_tg_lookup_jobs j ON j.id = i.job_id
                         WHERE i.charged = 0 AND i.status IN ('found','not_found') ORDER BY i.id LIMIT " . (int)$limit);
    $st->execute();
    $byJob = [];
    foreach ($st->fetchAll() as $row) $byJob[(int)$row['job_id']][] = $row;
    foreach ($byJob as $jobId => $rows) {
        $lines = [];
        foreach ($rows as $row) {
            if ((int)$row['price_each'] > 0) $lines[] = ['key' => 'commit:telegram_lookup_item:' . $row['id'], 'amount' => (int)$row['price_each']];
        }
        if ($lines !== []) wallet_commit_reservation_batch('telegram_lookup', (string)$jobId, $lines);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE ellsms_tg_lookup_items SET charged = 1 WHERE id IN ({$in})")->execute($ids);
        $out['charged'] += count($ids);
    }
    db()->prepare("UPDATE ellsms_tg_lookup_items SET charged = 1 WHERE charged = 0 AND status IN ('failed','cancelled')")->execute();

    // 4. Finish campaigns / lookups with nothing left in flight, then release what was not spent.
    $done = db()->query("SELECT c.id FROM ellsms_tg_campaigns c WHERE c.status IN ('queued','running')
                         AND NOT EXISTS (SELECT 1 FROM ellsms_tg_messages m WHERE m.campaign_id = c.id AND m.status IN ('queued','sending'))
                         LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($done as $id) {
        db()->prepare("UPDATE ellsms_tg_campaigns SET status = 'completed', finished_at = ? WHERE id = ? AND status IN ('queued','running')")->execute([$now, $id]);
    }
    $settle = db()->query("SELECT c.id FROM ellsms_tg_campaigns c WHERE c.settled_at IS NULL AND c.status IN ('completed','cancelled')
                           AND NOT EXISTS (SELECT 1 FROM ellsms_tg_messages m WHERE m.campaign_id = c.id AND (m.status = 'sending' OR m.charged = 0))
                           LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($settle as $id) {
        wallet_release_reservation('telegram_campaign', (string)$id);
        db()->prepare('UPDATE ellsms_tg_campaigns SET settled_at = ? WHERE id = ?')->execute([$now, $id]);
        $out['finished']++;
    }
    $done = db()->query("SELECT j.id FROM ellsms_tg_lookup_jobs j WHERE j.status IN ('queued','running')
                         AND NOT EXISTS (SELECT 1 FROM ellsms_tg_lookup_items i WHERE i.job_id = j.id AND i.status IN ('queued','checking'))
                         LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($done as $id) {
        db()->prepare("UPDATE ellsms_tg_lookup_jobs SET status = 'completed', finished_at = ? WHERE id = ? AND status IN ('queued','running')")->execute([$now, $id]);
    }
    $settle = db()->query("SELECT j.id FROM ellsms_tg_lookup_jobs j WHERE j.settled_at IS NULL AND j.status IN ('completed','cancelled')
                           AND NOT EXISTS (SELECT 1 FROM ellsms_tg_lookup_items i WHERE i.job_id = j.id AND (i.status = 'checking' OR i.charged = 0))
                           LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($settle as $id) {
        wallet_release_reservation('telegram_lookup', (string)$id);
        db()->prepare('UPDATE ellsms_tg_lookup_jobs SET settled_at = ? WHERE id = ?')->execute([$now, $id]);
        $out['finished']++;
    }

    // 5. Webhooks: one event per state a message reaches (sent, read, failed), at most once each.
    $out['events'] = tg_emit_webhooks($limit);

    // 6. Abandoned login attempts.
    db()->prepare("UPDATE ellsms_tg_login_requests SET state = 'expired', code = NULL, password_enc = NULL, temp_session_enc = NULL, updated_at = ?
                   WHERE state NOT IN ('done','failed','expired') AND expires_at < ?")
        ->execute([$now, $now]);

    if ($out['charged'] > 0) Metrics::increment('telegram.settled', $out['charged']);
    return $out;
}

function tg_emit_webhooks(int $limit): int {
    $st = db()->prepare("SELECT m.*, c.kind, c.source FROM ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id
                         WHERE m.status IN ('sent','read','failed') AND (m.notified_status IS NULL OR m.notified_status <> m.status)
                         ORDER BY m.id LIMIT " . (int)$limit);
    $st->execute();
    $emitted = 0;
    $mark = db()->prepare('UPDATE ellsms_tg_messages SET notified_status = ? WHERE id = ? AND status = ?');
    foreach ($st->fetchAll() as $row) {
        $organizationId = (int)($row['organization_id'] ?? 0);
        $eventType = match ($row['status']) {
            'sent' => WebhookEvents::TELEGRAM_MESSAGE_SENT,
            'read' => WebhookEvents::TELEGRAM_MESSAGE_READ,
            default => WebhookEvents::TELEGRAM_MESSAGE_FAILED,
        };
        // A message read before the pass saw it 'sent' still reports 'sent' first: integrators can rely on the order.
        $states = $row['status'] === 'read' && $row['notified_status'] === null ? [WebhookEvents::TELEGRAM_MESSAGE_SENT, $eventType] : [$eventType];
        if ($organizationId > 0) {
            foreach ($states as $type) {
                if (!webhook_org_subscribes($organizationId, $type)) continue;
                $data = tg_message_public($row) + ['kind' => (string)$row['kind'], 'source' => (string)$row['source']];
                if ($type === WebhookEvents::TELEGRAM_MESSAGE_SENT) $data['status'] = 'sent';
                webhook_event_emit($organizationId, $type, 'telegram_message', (string)$row['id'], $data);
                $emitted++;
            }
        }
        $mark->execute([$row['status'], $row['id'], $row['status']]);
    }
    return $emitted;
}
