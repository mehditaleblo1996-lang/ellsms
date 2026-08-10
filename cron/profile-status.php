<?php
/**
 * ELLSMS — read-only customer/organization profile status, for support and operations
 * (docs/customer-profile.md §Operations).
 *
 * Answers "how complete is this customer's profile, and what is missing" without anyone having to
 * open the panel or write a query. Deliberately shows WHICH fields are absent and never the values
 * of the sensitive ones: national codes, addresses and document contents are not printed, because a
 * support tool that echoes identity data into a terminal (and from there into a chat log or a ticket)
 * is its own kind of leak — see docs/customer-profile.md §Privacy.
 *
 * Usage:
 *   php cron/profile-status.php --org=<id>    # one organization and its members
 *   php cron/profile-status.php --user=<id>   # one user's personal profile
 *   php cron/profile-status.php               # summary across every organization
 *   php cron/profile-status.php --json
 */
require_once __DIR__ . '/../app/backend.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}
$organizationId = 0;
$userId = 0;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--org=')) $organizationId = (int)substr($arg, 6);
    if (str_starts_with($arg, '--user=')) $userId = (int)substr($arg, 7);
}

$db = db();

/** Everything worth knowing about one organization's profile, with no sensitive values in it. */
function profile_status_for_organization(int $organizationId): array {
    $profile = profile_organization_get($organizationId);
    $address = profile_address_get($organizationId);
    $notifications = profile_notifications_get($organizationId);
    $completeness = profile_organization_completeness($profile, $address);

    $documents = profile_documents_list(['organization' => $organizationId], false);
    $presentTypes = array_map(static fn(array $d): string => (string)$d['document_type'], $documents);

    return [
        'organization_id'   => $organizationId,
        'company_type'      => $profile['company_type'],
        'has_legal_name'    => $profile['legal_name'] !== '',
        'has_ceo'           => $profile['ceo_name'] !== '',
        // Presence, not the value — a postal code is a sensitive identifier.
        'has_postal_code'   => $address['postal_code'] !== '',
        'has_address'       => $address['city'] !== '' || (string)($address['address_text'] ?? '') !== '',
        'completeness'      => $completeness['percent'],
        'missing'           => $completeness['missing'],
        'documents_active'  => count($documents),
        'document_types'    => $presentTypes,
        'documents_missing' => array_values(array_diff(array_keys(PROFILE_ORGANIZATION_DOCUMENT_TYPES), $presentTypes)),
        'low_credit_alert'  => [
            'enabled'   => (bool)$notifications['low_credit_alert_enabled'],
            'threshold' => (int)$notifications['low_credit_threshold'],
            'email'     => (bool)$notifications['email_alert_enabled'],
            'sms'       => (bool)$notifications['sms_alert_enabled'],
        ],
    ];
}

function profile_status_for_user(int $userId): array {
    $profile = profile_user_get($userId);
    $completeness = profile_user_completeness($profile);
    $documents = profile_documents_list(['user' => $userId], false);
    $presentTypes = array_map(static fn(array $d): string => (string)$d['document_type'], $documents);

    return [
        'user_id'           => $userId,
        'gender'            => $profile['gender'],
        'has_national_code' => ($profile['national_code'] ?? '') !== '',
        'has_birth_date'    => ($profile['birth_date'] ?? null) !== null,
        'from_legacy_kyc'   => (bool)($profile['from_legacy_kyc'] ?? false),
        'completeness'      => $completeness['percent'],
        'missing'           => $completeness['missing'],
        'documents_active'  => count($documents),
        'documents_missing' => array_values(array_diff(array_keys(PROFILE_USER_DOCUMENT_TYPES), $presentTypes)),
    ];
}

$report = ['generated_at_utc' => gmdate('Y-m-d H:i:s')];

if ($userId > 0) {
    $report['user'] = profile_status_for_user($userId);
} elseif ($organizationId > 0) {
    $report['organization'] = profile_status_for_organization($organizationId);
    $report['members'] = [];
    foreach (organization_member_user_ids($organizationId) as $memberId) {
        $report['members'][] = profile_status_for_user((int)$memberId);
    }
} else {
    $report['organizations'] = [];
    foreach ($db->query("SELECT id FROM ellsms_organizations WHERE status <> 'disabled' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $report['organizations'][] = profile_status_for_organization((int)$id);
    }
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo "ELLSMS profile status (UTC {$report['generated_at_utc']})\n";
echo "  (values of national codes, addresses and documents are deliberately not printed)\n";

if (isset($report['user'])) {
    $u = $report['user'];
    echo "\nUser #{$u['user_id']}\n";
    printf("  completeness      %d%%\n", $u['completeness']);
    printf("  gender            %s\n", $u['gender']);
    printf("  national code     %s\n", $u['has_national_code'] ? 'ثبت شده' : '—');
    printf("  birth date        %s\n", $u['has_birth_date'] ? 'ثبت شده' : '—');
    printf("  active documents  %d\n", $u['documents_active']);
    if ($u['documents_missing']) printf("  missing documents %s\n", implode(', ', $u['documents_missing']));
    if ($u['missing'])           printf("  missing fields    %s\n", implode('، ', $u['missing']));
    if ($u['from_legacy_kyc'])   echo "  NOTE: still reading through to ellsms_user_kyc — run `make profile-backfill`\n";
}

foreach (array_merge(isset($report['organization']) ? [$report['organization']] : [], $report['organizations'] ?? []) as $o) {
    echo "\nOrganization #{$o['organization_id']}\n";
    printf("  completeness      %d%%\n", $o['completeness']);
    printf("  company type      %s\n", $o['company_type']);
    printf("  legal name / CEO  %s / %s\n", $o['has_legal_name'] ? '✓' : '—', $o['has_ceo'] ? '✓' : '—');
    printf("  address / postal  %s / %s\n", $o['has_address'] ? '✓' : '—', $o['has_postal_code'] ? '✓' : '—');
    printf("  active documents  %d\n", $o['documents_active']);
    if ($o['documents_missing']) printf("  missing documents %s\n", implode(', ', $o['documents_missing']));
    if ($o['missing'])           printf("  missing fields    %s\n", implode('، ', $o['missing']));
    printf("  low-credit alert  %s (threshold %d)\n", $o['low_credit_alert']['enabled'] ? 'on' : 'off', $o['low_credit_alert']['threshold']);
}

foreach ($report['members'] ?? [] as $m) {
    printf("\n  member #%d — %d%% complete, %d active document(s)\n", $m['user_id'], $m['completeness'], $m['documents_active']);
}

echo "\n";
exit(0);
