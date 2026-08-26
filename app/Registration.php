<?php
/** Public registration/onboarding primitives. */
declare(strict_types=1);

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

    // Existing backend account usernames must never be shadowed by a pending registration.
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
