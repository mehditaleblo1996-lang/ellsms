<?php
/** Customer onboarding/account-completion progress. */
declare(strict_types=1);

function onboarding_enabled(): bool {
    return setting('onboarding_enabled', '1') !== '0';
}

function onboarding_video_url(): string {
    $url = trim((string)setting('onboarding_video_url', ''));
    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
}

function onboarding_video_title(): string {
    return trim((string)setting('onboarding_video_title', 'آموزش شروع کار با ELLSMS'));
}

/**
 * Derived live from authoritative profile/KYC/billing/message state. No denormalized progress table,
 * so a user can never become permanently "complete" while the underlying requirement is missing.
 */
function onboarding_status(array $user): array {
    $userId = (int)($user['id'] ?? 0);
    $organizationId = (int)($user['organization_id'] ?? 0);
    if ($organizationId <= 0) {
        $organizationId = user_default_organization_id($userId) ?? 0;
    }

    $userProfile = profile_user_get($userId);
    $organizationProfile = $organizationId > 0 ? profile_organization_get($organizationId) : null;
    $address = $organizationId > 0 ? profile_address_get($organizationId) : null;
    $accountType = $organizationProfile['account_type'] ?? 'individual';
    $profileScore = $organizationId > 0
        ? profile_account_completeness($accountType, $userProfile, $organizationProfile, $address)
        : profile_user_completeness($userProfile);

    $kyc = $organizationId > 0 ? kyc_request_get($organizationId) : null;
    $kycStatus = (string)($kyc['status'] ?? 'draft');
    $kycDone = $kycStatus === 'approved';

    $planOptional = !billing_enabled();
    $subscription = $organizationId > 0 && billing_enabled() ? subscription_for_organization($organizationId) : null;
    $planDone = $planOptional || $subscription !== null;

    $hasCredit = (int)($user['credit'] ?? 0) > 0;
    $firstMessage = backend_outbound_rows('sender_user_id = ?', [$userId], 1);
    $hasSent = $firstMessage !== [];

    $steps = [
        [
            'key' => 'account', 'label' => 'حساب فعال', 'done' => true, 'optional' => false,
            'description' => 'ثبت‌نام و تأیید مدیر با موفقیت انجام شده است.', 'href' => '/profile.php',
        ],
        [
            'key' => 'profile', 'label' => 'تکمیل مشخصات', 'done' => $profileScore >= 80, 'optional' => false,
            'description' => 'اطلاعات فردی، سازمانی و آدرس خود را حداقل تا ۸۰٪ کامل کنید.', 'href' => '/profile.php',
            'meta' => $profileScore . '%',
        ],
        [
            'key' => 'kyc', 'label' => 'احراز هویت', 'done' => $kycDone, 'optional' => false,
            'description' => $kycDone ? 'احراز هویت تأیید شده است.' : 'مدارک را تکمیل و درخواست احراز هویت را ارسال کنید.', 'href' => '/profile.php',
            'meta' => $kycStatus,
        ],
        [
            'key' => 'plan', 'label' => 'پلن سرویس', 'done' => $planDone, 'optional' => $planOptional,
            'description' => $planOptional ? 'Billing فعلاً غیرفعال است؛ این مرحله اختیاری است.' : 'پلن مناسب سرویس را انتخاب کنید.', 'href' => '/billing.php',
            'meta' => $subscription['plan_name'] ?? ($planOptional ? 'اختیاری' : ''),
        ],
        [
            'key' => 'credit', 'label' => 'تهیه اعتبار', 'done' => $hasCredit, 'optional' => false,
            'description' => $hasCredit ? 'حساب شما اعتبار قابل استفاده دارد.' : 'برای شروع ارسال، حساب خود را شارژ کنید.', 'href' => '/buy-credit.php',
        ],
        [
            'key' => 'first_send', 'label' => 'اولین پیامک', 'done' => $hasSent, 'optional' => false,
            'description' => $hasSent ? 'اولین ارسال انجام شده است.' : 'یک پیامک آزمایشی ارسال کنید.', 'href' => '/send.php',
        ],
    ];

    $required = array_values(array_filter($steps, static fn(array $s): bool => empty($s['optional'])));
    $doneRequired = count(array_filter($required, static fn(array $s): bool => !empty($s['done'])));
    $progress = $required ? (int)round(($doneRequired / count($required)) * 100) : 100;

    return [
        'enabled' => onboarding_enabled(),
        'complete' => $progress >= 100,
        'progress' => $progress,
        'steps' => $steps,
        'organization_id' => $organizationId,
        'profile_score' => $profileScore,
        'kyc_status' => $kycStatus,
        'video_url' => onboarding_video_url(),
        'video_title' => onboarding_video_title(),
    ];
}
