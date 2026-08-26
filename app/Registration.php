<?php
/** Public registration/onboarding primitives. */
declare(strict_types=1);

const REGISTRATION_OTP_TTL_SECONDS = 300;
const REGISTRATION_OTP_RESEND_SECONDS = 60;
const REGISTRATION_OTP_MAX_ATTEMPTS = 10;

function registration_enabled(): bool {
    return setting('registration_mode', 'approval') !== 'closed';
}

function registration_mode(): string {
    $mode = (string)setting('registration_mode', 'approval');
    return in_array($mode, ['closed', 'approval', 'auto_after_otp'], true) ? $mode : 'approval';
}

function registration_pending_states(): array {
    return ['pending_mobile_verification', 'pending_admin_approval'];
}

function registration_request_get(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare('SELECT * FROM ellsms_registration_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function registration_request_create(array $input): array {
    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $mobile = normalize_msisdn((string)($input['mobile'] ?? ''));
    $email = mb_strtolower(trim((string)($input['email'] ?? '')), 'UTF-8');
    $username = mb_strtolower(trim((string)($input['username'] ?? '')), 'UTF-8');
    $password = (string)($input['password'] ?? '');
    $passwordRepeat = (string)($input['password_repeat'] ?? '');
    $accountType = ($input['account_type'] ?? 'individual') === 'legal' ? 'legal' : 'individual';
    $companyName = trim((string)($input['company_name'] ?? ''));

    if (!registration_enabled()) return ['ok' => false, 'error' => 'ثبت‌نام در حال حاضر غیرفعال است.'];
    if ($firstName === '' || $lastName === '') return ['ok' => false, 'error' => 'نام و نام خانوادگی الزامی است.'];
    if ($mobile === null) return ['ok' => false, 'error' => 'شماره موبایل معتبر وارد کنید.'];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) return ['ok' => false, 'error' => 'ایمیل معتبر وارد کنید.'];
    if (preg_match('/^[a-zA-Z0-9_.-]{3,120}$/', $username) !== 1) return ['ok' => false, 'error' => 'نام کاربری باید حداقل ۳ نویسه و شامل حروف لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.'];
    if (strlen($password) < 8) return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۸ نویسه باشد.'];
    if ($password !== $passwordRepeat) return ['ok' => false, 'error' => 'تکرار رمز عبور با رمز عبور یکسان نیست.'];
    if ($accountType === 'legal' && $companyName === '') return ['ok' => false, 'error' => 'برای حساب حقوقی نام شرکت را وارد کنید.'];

    if (function_exists('backend_find_user_for_login') && backend_find_user_for_login($username)) {
        return ['ok' => false, 'error' => 'این نام کاربری قبلاً استفاده شده است.'];
    }

    $states = registration_pending_states();
    $in = implode(',', array_fill(0, count($states), '?'));
    $st = db()->prepare("SELECT id, mobile, username, email FROM ellsms_registration_requests WHERE state IN ($in) AND (mobile = ? OR username = ? OR (email <> '' AND email = ?)) ORDER BY id DESC LIMIT 1");
    $st->execute([...$states, $mobile, $username, $email]);
    if ($st->fetch()) {
        return ['ok' => false, 'error' => 'برای این موبایل، نام کاربری یا ایمیل یک درخواست فعال وجود دارد.'];
    }

    $verifier = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    if ($verifier === false) return ['ok' => false, 'error' => 'ثبت امن رمز عبور ممکن نشد. دوباره تلاش کنید.'];

    $ip = function_exists('client_ip') ? client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8');
    $st = db()->prepare(
        "INSERT INTO ellsms_registration_requests
          (first_name,last_name,mobile,email,username,password_verifier,account_type,company_name,state,signup_ip,signup_user_agent)
         VALUES (?,?,?,?,?,?,?,?,'pending_mobile_verification',?,?)"
    );
    $st->execute([$firstName, $lastName, $mobile, $email, $username, $verifier, $accountType, $companyName, $ip, $ua]);
    $id = (int)db()->lastInsertId();

    Logger::info('registration.started', [
        'registration_id' => $id,
        'account_type' => $accountType,
        'has_email' => $email !== '',
    ]);
    if (function_exists('audit_mongo_event')) {
        audit_mongo_event('registration.started', [
            'registration_id' => $id,
            'account_type' => $accountType,
        ], true);
    }

    return ['ok' => true, 'id' => $id];
}

/** System SMS transport for onboarding. The raw text is never logged here. */
function registration_system_sms(array $destinations, string $text): array {
    $normalized = [];
    foreach ($destinations as $destination) {
        $mobile = normalize_msisdn((string)$destination);
        if ($mobile !== null) $normalized[] = $mobile;
    }
    $normalized = array_values(array_unique($normalized));
    if ($normalized === []) return ['ok' => false, 'error' => 'هیچ شماره موبایل معتبری برای ارسال وجود ندارد.'];

    $originator = normalize_originator((string)setting('default_originator', '')) ?? '';
    if ($originator === '') return ['ok' => false, 'error' => 'خط ارسال پیش‌فرض برای پیامک‌های سامانه تنظیم نشده است.'];

    $senderUserId = max(1, (int)setting('registration_sms_sender_user_id', '1'));
    require_once __DIR__ . '/backend.php';
    [$ok, $http, $rows] = backend_api_send($senderUserId, $originator, $normalized, $text);
    if (!$ok || !is_array($rows)) return ['ok' => false, 'error' => 'ارسال پیامک سامانه ممکن نشد.', 'http' => $http];

    $sent = 0;
    foreach ($rows as $sentRow) {
        if (is_array($sentRow) && (($sentRow['status'] ?? '') === 'sent')) $sent++;
    }
    return ['ok' => $sent > 0, 'sent' => $sent, 'total' => count($normalized), 'http' => $http];
}

/** @return list<string> */
function registration_admin_mobiles(): array {
    $raw = (string)setting('registration_admin_mobiles', '');
    if ($raw === '') return [];
    $parts = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $mobile = normalize_msisdn((string)$part);
        if ($mobile !== null) $out[] = $mobile;
    }
    return array_values(array_unique($out));
}

/** Best-effort: verification succeeds even if the admin SMS provider is temporarily unavailable. */
function registration_notify_admins(int $registrationId): void {
    $row = registration_request_get($registrationId);
    if (!$row || $row['state'] !== 'pending_admin_approval' || !empty($row['admin_notified_at'])) return;

    $mobiles = registration_admin_mobiles();
    if ($mobiles === []) {
        Logger::warning('registration.admin_notification_skipped', ['registration_id' => $registrationId, 'reason' => 'no_admin_mobile']);
        return;
    }

    $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    $type = $row['account_type'] === 'legal' ? 'حقوقی' : 'حقیقی';
    $company = trim((string)$row['company_name']);
    $text = "ثبت‌نام جدید ELLSMS\nشناسه: {$registrationId}\nنام: {$name}\nموبایل: {$row['mobile']}\nنوع: {$type}";
    if ($company !== '') $text .= "\nشرکت: {$company}";
    $text .= "\nبرای بررسی وارد پنل مدیریت شوید.";

    $result = registration_system_sms($mobiles, $text);
    if (!empty($result['ok'])) {
        db()->prepare('UPDATE ellsms_registration_requests SET admin_notified_at = NOW() WHERE id = ? AND admin_notified_at IS NULL')->execute([$registrationId]);
        Logger::info('registration.admin_notified', ['registration_id' => $registrationId, 'recipient_count' => (int)($result['sent'] ?? 0)]);
        if (function_exists('audit_mongo_event')) audit_mongo_event('registration.admin_notified', ['registration_id' => $registrationId], true);
    } else {
        Logger::warning('registration.admin_notification_failed', ['registration_id' => $registrationId]);
        if (function_exists('audit_mongo_event')) audit_mongo_event('registration.admin_notification_failed', ['registration_id' => $registrationId], true);
    }
}

/**
 * Send a registration OTP without ever persisting/logging the raw code.
 * Uses the same backend messaging API as the existing login 2FA path.
 */
function registration_send_otp(int $registrationId, bool $isResend = false): array {
    $row = registration_request_get($registrationId);
    if (!$row || $row['state'] !== 'pending_mobile_verification') {
        return ['ok' => false, 'error' => 'این درخواست در مرحله تأیید موبایل نیست.'];
    }

    if (!empty($row['otp_sent_at'])) {
        $last = strtotime((string)$row['otp_sent_at']);
        $remaining = REGISTRATION_OTP_RESEND_SECONDS - max(0, time() - (int)$last);
        if ($remaining > 0) {
            return ['ok' => false, 'error' => 'برای ارسال مجدد ' . to_persian_digits((string)$remaining) . ' ثانیه صبر کنید.'];
        }
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $text = "کد تأیید ثبت‌نام ELLSMS: {$code}\nاین کد تا ۵ دقیقه معتبر است.";
    $send = registration_system_sms([(string)$row['mobile']], $text);
    if (empty($send['ok'])) {
        Logger::warning('registration.otp_send_failed', ['registration_id' => $registrationId]);
        if (function_exists('audit_mongo_event')) audit_mongo_event('registration.otp_send_failed', ['registration_id' => $registrationId], true);
        return ['ok' => false, 'error' => 'ارسال کد تأیید ممکن نشد. کمی بعد دوباره تلاش کنید.'];
    }

    db()->prepare(
        'UPDATE ellsms_registration_requests
         SET otp_hash = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), otp_sent_at = NOW(),
             otp_send_count = otp_send_count + 1, otp_attempts = 0
         WHERE id = ? AND state = \'pending_mobile_verification\''
    )->execute([hash('sha256', $code), REGISTRATION_OTP_TTL_SECONDS, $registrationId]);

    Logger::info('registration.otp_sent', ['registration_id' => $registrationId, 'resend' => $isResend]);
    if (function_exists('audit_mongo_event')) audit_mongo_event('registration.otp_sent', ['registration_id' => $registrationId, 'resend' => $isResend], true);
    return ['ok' => true];
}

function registration_verify_otp(int $registrationId, string $code): array {
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 6) return ['ok' => false, 'error' => 'کد تأیید باید ۶ رقمی باشد.'];

    $row = registration_request_get($registrationId);
    if (!$row || $row['state'] !== 'pending_mobile_verification') return ['ok' => false, 'error' => 'این درخواست دیگر منتظر تأیید موبایل نیست.'];
    if ((int)$row['otp_attempts'] >= REGISTRATION_OTP_MAX_ATTEMPTS) return ['ok' => false, 'error' => 'تعداد تلاش‌های تأیید بیش از حد مجاز شده است. یک کد جدید درخواست کنید.'];
    if (empty($row['otp_hash']) || empty($row['otp_expires_at']) || strtotime((string)$row['otp_expires_at']) < time()) return ['ok' => false, 'error' => 'کد تأیید منقضی شده است. کد جدید درخواست کنید.'];

    db()->prepare('UPDATE ellsms_registration_requests SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$registrationId]);
    if (!hash_equals((string)$row['otp_hash'], hash('sha256', $code))) {
        Logger::warning('registration.otp_failed', ['registration_id' => $registrationId]);
        if (function_exists('audit_mongo_event')) audit_mongo_event('registration.otp_failed', ['registration_id' => $registrationId], true);
        return ['ok' => false, 'error' => 'کد تأیید نادرست است.'];
    }

    $st = db()->prepare(
        "UPDATE ellsms_registration_requests
         SET state = 'pending_admin_approval', mobile_verified_at = NOW(), otp_hash = NULL, otp_expires_at = NULL
         WHERE id = ? AND state = 'pending_mobile_verification'"
    );
    $st->execute([$registrationId]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'وضعیت درخواست تغییر کرده است. صفحه را دوباره باز کنید.'];

    Logger::info('registration.mobile_verified', ['registration_id' => $registrationId]);
    if (function_exists('audit_mongo_event')) audit_mongo_event('registration.mobile_verified', ['registration_id' => $registrationId], true);

    // auto_after_otp is reserved for the activation phase; until then it deliberately follows
    // the safe approval path instead of silently creating an account without the Phase 4 invariants.
    if (registration_mode() !== 'closed') registration_notify_admins($registrationId);
    return ['ok' => true];
}

function registration_admin_decide(int $registrationId, int $adminUserId, string $decision, string $note = ''): array {
    if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'تصمیم نامعتبر است.'];
    $row = registration_request_get($registrationId);
    if (!$row || $row['state'] !== 'pending_admin_approval') return ['ok' => false, 'error' => 'این درخواست دیگر منتظر بررسی مدیر نیست.'];

    $note = mb_substr(trim($note), 0, 500, 'UTF-8');
    if ($decision === 'reject' && $note === '') return ['ok' => false, 'error' => 'برای رد درخواست دلیل را وارد کنید.'];

    if ($decision === 'approve') {
        $st = db()->prepare("UPDATE ellsms_registration_requests SET state='approved', approved_at=NOW(), approved_by=?, decision_note=? WHERE id=? AND state='pending_admin_approval'");
        $st->execute([$adminUserId, $note, $registrationId]);
        $event = 'registration.approved';
        $message = "درخواست ثبت‌نام شما در ELLSMS تأیید شد.\nحساب شما در مرحله فعال‌سازی است و پس از آماده‌شدن، پیامک بعدی ارسال می‌شود.";
    } else {
        $st = db()->prepare("UPDATE ellsms_registration_requests SET state='rejected', rejected_at=NOW(), rejected_by=?, rejection_reason=?, decision_note=? WHERE id=? AND state='pending_admin_approval'");
        $st->execute([$adminUserId, $note, $note, $registrationId]);
        $event = 'registration.rejected';
        $message = "درخواست ثبت‌نام شما در ELLSMS تأیید نشد.\nدلیل: {$note}";
    }
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'وضعیت درخواست همزمان تغییر کرده است.'];

    Logger::info($event, ['registration_id' => $registrationId, 'admin_user_id' => $adminUserId]);
    if (function_exists('audit_mongo_event')) audit_mongo_event($event, ['registration_id' => $registrationId, 'admin_user_id' => $adminUserId], true);

    $sms = registration_system_sms([(string)$row['mobile']], $message);
    if (empty($sms['ok'])) Logger::warning($event . '.sms_failed', ['registration_id' => $registrationId]);
    return ['ok' => true, 'sms_sent' => !empty($sms['ok'])];
}
