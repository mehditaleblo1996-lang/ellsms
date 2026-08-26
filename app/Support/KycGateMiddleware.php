<?php
/** HTTP-side KYC gating for customer-facing capabilities. Loaded from auto_prepend after bootstrap. */
declare(strict_types=1);

function kyc_http_gate_enforce(): void {
    if (PHP_SAPI === 'cli') return;

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $gatesByScript = [
        'send.php'       => ['sms_send'],
        'new-send.php'   => ['sms_send'],
        'p2p-send.php'   => ['sms_send', 'high_volume_send'],
        'smart-send.php' => ['sms_send', 'high_volume_send'],
        'buy-credit.php' => ['credit_purchase'],
        'api-keys.php'   => ['production_api'],
    ];
    $gates = $gatesByScript[$script] ?? [];
    if ($gates === []) return;

    $user = current_user();
    if (!$user || ($user['role'] ?? '') === 'admin') return;

    $org = current_organization();
    $organizationId = (int)($org['organization_id'] ?? 0);
    if ($organizationId <= 0) return; // existing tenant resolution remains authoritative.

    foreach ($gates as $gate) {
        if (!kyc_feature_allowed($organizationId, $gate)) {
            Logger::warning('kyc.feature_blocked', [
                'organization_id' => $organizationId,
                'user_id' => (int)$user['id'],
                'gate' => $gate,
                'script' => $script,
            ]);
            flash('error', kyc_gate_denial_message($organizationId, $gate) . ' ابتدا مراحل احراز هویت را تکمیل کنید.');
            header('Location: /onboarding.php?blocked=kyc&gate=' . rawurlencode($gate));
            exit;
        }
    }
}
