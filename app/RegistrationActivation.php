<?php
/** Phase 4: turn an approved registration request into a real ELLSMS account. */
declare(strict_types=1);

require_once __DIR__ . '/Registration.php';
require_once __DIR__ . '/backend.php';
require_once __DIR__ . '/NotificationCenter.php';

function registration_activate_account(int $registrationId, int $adminUserId, array $input = []): array {
    if ($registrationId <= 0 || $adminUserId <= 0) return ['ok' => false, 'error' => 'شناسه درخواست یا مدیر نامعتبر است.'];

    $db = db();
    $lockName = 'ellsms_registration_activate:' . $registrationId;
    $lockSt = $db->prepare('SELECT GET_LOCK(?, 8) AS got');
    $lockSt->execute([$lockName]);
    if (!(bool)($lockSt->fetch()['got'] ?? false)) return ['ok' => false, 'error' => 'درخواست هم‌اکنون در حال پردازش است. چند ثانیه دیگر دوباره تلاش کنید.'];

    try {
        $row = registration_request_get($registrationId);
        if (!$row || !in_array($row['state'], ['pending_admin_approval', 'approved'], true)) return ['ok' => false, 'error' => 'این درخواست در وضعیت قابل فعال‌سازی نیست.'];

        $nationalId = trim(from_persian_digits((string)($input['national_id'] ?? $row['national_id'] ?? '')));
        $gender = (($input['gender'] ?? $row['gender'] ?? 'MALE') === 'FEMALE') ? 'FEMALE' : 'MALE';
        $domainId = max(0, (int)($input['domain_id'] ?? $row['domain_id'] ?? 0));
        $note = mb_substr(trim((string)($input['note'] ?? $row['decision_note'] ?? '')), 0, 500, 'UTF-8');

        if (strlen($nationalId) !== 10 || !ctype_digit($nationalId)) return ['ok' => false, 'error' => 'برای ساخت حساب، کد ملی ۱۰ رقمی معتبر لازم است.'];
        if ($domainId <= 0) return ['ok' => false, 'error' => 'Domain حساب را انتخاب کنید.'];
        if (trim((string)$row['email']) === '') return ['ok' => false, 'error' => 'ایمیل برای ساخت حساب در سامانه مرکزی الزامی است.'];

        $domainExists = false;
        foreach (backend_list_domains() as $domain) if ((int)$domain['id'] === $domainId) { $domainExists = true; break; }
        if (!$domainExists) return ['ok' => false, 'error' => 'Domain انتخاب‌شده وجود ندارد.'];

        $backendHash = $row['backend_password_hash'] ?? null;
        if (!is_string($backendHash) || strlen($backendHash) !== 32) return ['ok' => false, 'error' => 'این درخواست قبل از فاز فعال‌سازی ساخته شده و هش سازگار رمز عبور ندارد. لطفاً متقاضی یک ثبت‌نام جدید انجام دهد.'];

        $userId = (int)($row['created_user_id'] ?? 0);
        if ($userId <= 0) {
            if (backend_find_user_id_by_username((string)$row['username']) !== null) return ['ok' => false, 'error' => 'نام کاربری در سامانه مرکزی قبلاً ایجاد شده است؛ برای جلوگیری از اتصال اشتباه، فعال‌سازی متوقف شد.'];

            $temporaryPassword = bin2hex(random_bytes(24));
            [$ok, $info, $created] = backend_create_account([
                'username' => (string)$row['username'], 'password' => $temporaryPassword,
                'first_name' => (string)$row['first_name'], 'last_name' => (string)$row['last_name'],
                'email' => (string)$row['email'], 'mobile' => (int)$row['mobile'], 'national_id' => $nationalId,
                'domain_id' => $domainId, 'gender' => $gender, 'code' => (string)random_int(10000000, 99999999),
                'daily_limit' => max(1, (int)setting('registration_default_daily_limit', '1000')),
                'min_credit_notify' => 0, 'limit_time_from' => '00:00', 'limit_time_to' => '23:59',
            ]);
            unset($temporaryPassword);

            if (!$ok || !$created || empty($created['id'])) {
                $safeError = mb_substr((string)$info, 0, 400, 'UTF-8');
                $db->prepare('UPDATE ellsms_registration_requests SET activation_error=? WHERE id=?')->execute([$safeError, $registrationId]);
                Logger::error('registration.account_create_failed', ['registration_id' => $registrationId]);
                if (function_exists('audit_mongo_event')) audit_mongo_event('registration.account_create_failed', ['registration_id' => $registrationId], true);
                return ['ok' => false, 'error' => 'ساخت حساب در سامانه مرکزی موفق نبود: ' . $safeError];
            }

            $userId = (int)$created['id'];
            $db->prepare("UPDATE ellsms_registration_requests SET state='approved', approved_at=COALESCE(approved_at,NOW()), approved_by=COALESCE(approved_by,?), decision_note=?, created_user_id=?, national_id=?, gender=?, domain_id=?, activation_error='' WHERE id=?")
               ->execute([$adminUserId, $note, $userId, $nationalId, $gender, $domainId, $registrationId]);
        }

        backend_update_user_password($userId, $backendHash);

        $organizationId = db_transaction(function (PDO $db) use ($row, $userId, $adminUserId): int {
            $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?) ON DUPLICATE KEY UPDATE panel_access=1, is_admin=0')
               ->execute([$userId, setting('default_originator', '')]);

            $displayName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']) ?: (string)$row['username'];
            $orgName = $row['account_type'] === 'legal' && trim((string)$row['company_name']) !== '' ? trim((string)$row['company_name']) : $displayName . "'s Workspace";
            $orgResult = ensure_user_has_organization($userId, $orgName);
            if (empty($orgResult['ok']) || empty($orgResult['organization_id'])) throw new RuntimeException('organization_create_failed');
            $organizationId = (int)$orgResult['organization_id'];

            $profile = ['account_type' => (string)$row['account_type']];
            if ($row['account_type'] === 'legal' && trim((string)$row['company_name']) !== '') $profile['legal_name'] = trim((string)$row['company_name']);
            $profileResult = profile_organization_save($organizationId, $profile, $adminUserId);
            if (isset($profileResult['ok']) && !$profileResult['ok']) throw new RuntimeException('organization_profile_failed');

            if (billing_enabled() && subscription_for_organization($organizationId) === null) {
                $plan = billing_plan_by_code(billing_default_plan_code());
                if (!$plan) throw new RuntimeException('default_plan_missing');
                $periodMonths = $plan['billing_period'] === 'yearly' ? 12 : ($plan['billing_period'] === 'monthly' ? 1 : 0);
                $sub = subscription_create($organizationId, (int)$plan['id'], 'active', $adminUserId, 'registration', $periodMonths, 'registration:' . $row['id']);
                if (empty($sub['ok']) && ($sub['reason'] ?? '') !== 'already_subscribed') throw new RuntimeException('default_subscription_failed');
            }
            return $organizationId;
        });

        $db->prepare("UPDATE ellsms_registration_requests SET state='account_created', approved_at=COALESCE(approved_at,NOW()), approved_by=COALESCE(approved_by,?), account_created_at=NOW(), national_id=?, gender=?, domain_id=?, activation_error='', backend_password_hash=NULL, password_verifier='' WHERE id=?")
           ->execute([$adminUserId, $nationalId, $gender, $domainId, $registrationId]);

        audit($adminUserId, 'registration.account_created', "registration=#{$registrationId} user=#{$userId} org=#{$organizationId}");
        Logger::info('registration.account_created', ['registration_id' => $registrationId, 'user_id' => $userId, 'organization_id' => $organizationId]);
        if (function_exists('audit_mongo_event')) audit_mongo_event('registration.account_created', ['registration_id'=>$registrationId,'created_user_id'=>$userId,'organization_id'=>$organizationId,'admin_user_id'=>$adminUserId], true);

        $smsEnabled = notification_channel_enabled('registration.account_created', 'sms');
        $sms = ['ok' => true, 'disabled' => !$smsEnabled];
        if ($smsEnabled) {
            $loginUrl = app_url() !== '' ? app_url() . '/login.php' : '/login.php';
            $message = "حساب شما در ELLSMS فعال شد.\nنام کاربری: {$row['username']}\nورود: {$loginUrl}";
            $sms = registration_system_sms([(string)$row['mobile']], $message);
            if (empty($sms['ok'])) Logger::warning('registration.account_created.sms_failed', ['registration_id' => $registrationId]);
        }

        return ['ok'=>true,'user_id'=>$userId,'organization_id'=>$organizationId,'sms_sent'=>!empty($sms['ok']),'sms_enabled'=>$smsEnabled];
    } catch (Throwable $e) {
        $safeReason = match ($e->getMessage()) {
            'organization_create_failed' => 'ساخت سازمان پیش‌فرض کاربر موفق نبود.',
            'organization_profile_failed' => 'ثبت مشخصات سازمان کاربر موفق نبود.',
            'default_plan_missing' => 'پلن پیش‌فرض Billing پیدا نشد.',
            'default_subscription_failed' => 'اختصاص پلن پیش‌فرض موفق نبود.',
            default => 'فعال‌سازی حساب به دلیل خطای داخلی کامل نشد.',
        };
        try { $db->prepare('UPDATE ellsms_registration_requests SET activation_error=? WHERE id=?')->execute([$safeReason, $registrationId]); } catch (Throwable) {}
        Logger::error('registration.activation_failed', ['registration_id' => $registrationId, 'exception' => $e]);
        return ['ok' => false, 'error' => $safeReason];
    } finally {
        try { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable) {}
    }
}
