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

    $originator = normalize_originator((string)setting('default_originator', '')) ?? '';
    if ($originator === '') {
        return ['ok' => false, 'error' => 'خط ارسال پیش‌فرض برای پیامک‌های سامانه تنظیم نشده است.'];
    }

    // This id is only the backend audit/sender identity for system OTP messages.
    // It is configurable in Settings DB and defaults to the historical bootstrap admin id.
    $senderUserId = max(1, (int)setting('registration_sms_sender_user_id', '1'));
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $text = "کد تأیید ثبت‌نام ELLSMS: {$code}\nاین کد تا ۵ دقیقه معتبر است.";

    require_once __DIR__ . '/backend.php';
    [$ok, $http, $rows, $err] = backend_api_send($senderUserId, $originator, [(string)$row['mobile']], $text);
    $accepted = false;
    if ($ok && is_array($rows)) {
        foreach ($rows as $sentRow) {
            if (is_array($sentRow) && (($sentRow['status'] ?? '') === 'sent')) {
                $accepted = true;
                break;
            }
        }
    }
    if (!$accepted) {
        Logger::warning('registration.otp_send_failed', [
            'registration_id' => $registrationId,
            'http' => $http,
        ]);
        if (function_exists('audit_mongo_event')) {
            audit_mongo_event('registration.otp_send_failed', ['registration_id' => $registrationId], true);
        }
        return ['ok' => false, 'error' => 'ارسال کد تأیید ممکن نشد. کمی بعد دوباره تلاش کنید.'];
    }

    db()->prepare(
        'UPDATE ellsms_registration_requests
         SET otp_hash = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), otp_sent_at = NOW(),
             otp_send_count = otp_send_count + 1, otp_attempts = 0
         WHERE id = ? AND state = \'pending_mobile_verification\''
    )->execute([hash('sha256', $code), REGISTRATION_OTP_TTL_SECONDS, $registrationId]);

    Logger::info('registration.otp_sent', [
        'registration_id' => $registrationId,
        'resend' => $isResend,
    ]);
    if (function_exists('audit_mongo_event')) {
        audit_mongo_event('registration.otp_sent', [
            'registration_id' => $registrationId,
            'resend' => $isResend,
        ], true);
    }
    return ['ok' => true];
}

function registration_verify_otp(int $registrationId, string $code): array {
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 6) return ['ok' => false, 'error' => 'کد تأیید باید ۶ رقمی باشد.'];

    $row = registration_request_get($registrationId);
    if (!$row || $row['state'] !== 'pending_mobile_verification') {
        return ['ok' => false, 'error' => 'این درخواست دیگر منتظر تأیید موبایل نیست.'];
    }
    if ((int)$row['otp_attempts'] >= REGISTRATION_OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'تعداد تلاش‌های تأیید بیش از حد مجاز شده است. یک کد جدید درخواست کنید.'];
    }
    if (empty($row['otp_hash']) || empty($row['otp_expires_at']) || strtotime((string)$row['otp_expires_at']) < time()) {
        return ['ok' => false, 'error' => 'کد تأیید منقضی شده است. کد جدید درخواست کنید.'];
    }

    db()->prepare('UPDATE ellsms_registration_requests SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$registrationId]);
    if (!hash_equals((string)$row['otp_hash'], hash('sha256', $code))) {
        Logger::warning('registration.otp_failed', ['registration_id' => $registrationId]);
        if (function_exists('audit_mongo_event')) {
            audit_mongo_event('registration.otp_failed', ['registration_id' => $registrationId], true);
        }
        return ['ok' => false, 'error' => 'کد تأیید نادرست است.'];
    }

    $st = db()->prepare(
        "UPDATE ellsms_registration_requests
         SET state = 'pending_admin_approval', mobile_verified_at = NOW(), otp_hash = NULL, otp_expires_at = NULL
         WHERE id = ? AND state = 'pending_mobile_verification'"
    );
    $st->execute([$registrationId]);
    if ($st->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'وضعیت درخواست تغییر کرده است. صفحه را دوباره باز کنید.'];
    }

    Logger::info('registration.mobile_verified', ['registration_id' => $registrationId]);
    if (function_exists('audit_mongo_event')) {
        audit_mongo_event('registration.mobile_verified', ['registration_id' => $registrationId], true);
    }
    return ['ok' => true];
}
