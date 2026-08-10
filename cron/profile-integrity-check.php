<?php
/**
 * ELLSMS — customer/organization profile integrity audit (docs/customer-profile.md).
 *
 * Read-only, same design as every other integrity tool here: migration preflight AND ongoing
 * monitor, never an auto-fixer. Identity and legal data is exactly the category where a tool
 * "correcting" a value silently would be worse than the inconsistency it found — a wrong national
 * code that someone believes was repaired is worse than a wrong one that is reported.
 *
 * Exits non-zero on CRITICAL findings; warnings are reported but do not fail the run.
 *
 * Usage: php cron/profile-integrity-check.php
 */
require_once __DIR__ . '/../app/backend.php';

$db = db();
$critical = 0;
$warnings = 0;

function section(string $title): void {
    echo "\n=== {$title} ===\n";
}

function count_check(PDO $db, string $sql, string $label, bool $isCritical = true): int {
    $count = (int)$db->query($sql)->fetchColumn();
    $tag = $count > 0 ? ($isCritical ? "[CRITICAL {$count}]" : "[WARN {$count}]") : '[ok]';
    echo "  {$tag} {$label}\n";
    return $count;
}

section('Schema presence');
try {
    $db->query('SELECT 1 FROM ellsms_profile_documents LIMIT 1');
} catch (Throwable) {
    echo "  [CRITICAL] profile tables are missing — run `make db-migrations-apply` (db/migrations/2026_08_12_customer_profile.sql)\n";
    echo "\nCRITICAL: profile schema not installed.\n";
    exit(1);
}
echo "  [ok] profile tables present\n";

section('Ownership');
// The single-owner CHECK constraint makes both of these unrepresentable through a normal write;
// they are checked anyway because an ambiguously-owned document is the direct road to a
// cross-tenant read, and a constraint dropped during some future migration must not fail silently.
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_profile_documents WHERE organization_id IS NOT NULL AND user_id IS NOT NULL',
    'documents owned by BOTH a user and an organization — the ownership check may have been dropped');
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_profile_documents WHERE organization_id IS NULL AND user_id IS NULL',
    'documents owned by NEITHER a user nor an organization');

section('Orphans');
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_organization_profiles p LEFT JOIN ellsms_organizations o ON o.id = p.organization_id WHERE o.id IS NULL',
    'organization profiles whose organization no longer exists');
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_organization_addresses a LEFT JOIN ellsms_organizations o ON o.id = a.organization_id WHERE o.id IS NULL',
    'organization addresses whose organization no longer exists');
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_organization_notification_preferences n LEFT JOIN ellsms_organizations o ON o.id = n.organization_id WHERE o.id IS NULL',
    'notification preferences whose organization no longer exists');
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_profile_documents d LEFT JOIN ellsms_organizations o ON o.id = d.organization_id
     WHERE d.organization_id IS NOT NULL AND o.id IS NULL',
    'organization documents whose organization no longer exists');
$warnings += count_check($db,
    'SELECT COUNT(*) FROM ellsms_user_profiles p LEFT JOIN ellsms_meta m ON m.user_id = p.user_id WHERE m.user_id IS NULL',
    'user profiles for accounts no longer managed by ELLSMS — harmless, but nothing reads them', false);

section('Legal representative');
// A representative who is not a member of the organization they represent is not merely untidy: the
// field is rendered as an authoritative contact, so pointing it at an arbitrary account is wrong.
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_organization_profiles p
     WHERE p.legal_representative_user_id IS NOT NULL
       AND NOT EXISTS (
         SELECT 1 FROM ellsms_organization_memberships m
         WHERE m.user_id = p.legal_representative_user_id AND m.organization_id = p.organization_id AND m.status = 'active'
       )",
    'legal representatives who are not active members of the organization they represent');

section('Documents');
$critical += count_check($db,
    "SELECT COUNT(*) FROM (
       SELECT active_slot FROM ellsms_profile_documents WHERE active_slot IS NOT NULL
       GROUP BY active_slot HAVING COUNT(*) > 1
     ) x",
    'more than one ACTIVE document for the same owner and type — uniq_active_document should prevent this');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_profile_documents
     WHERE active_slot <=> (CASE WHEN status = 'active'
            THEN CONCAT(IF(organization_id IS NOT NULL, 'o:', 'u:'), COALESCE(organization_id, user_id), ':', document_type)
            ELSE NULL END) = 0",
    'active_slot out of sync with status/owner/type — the uniqueness index is no longer protecting the invariant');
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_profile_documents WHERE status = 'archived' AND archived_at IS NULL",
    'archived documents with no archived_at timestamp', false);

// File presence and checksum. Metadata pointing at a file that is gone means a download 404s and a
// restore is incomplete — the single most important thing this tool can notice.
$documents = $db->query("SELECT id, storage_key, sha256, size_bytes, status FROM ellsms_profile_documents ORDER BY id")->fetchAll();
$missingFiles = 0;
$checksumMismatches = 0;
foreach ($documents as $document) {
    $path = profile_document_path((string)$document['storage_key']);
    if ($path === null) {
        echo "  [CRITICAL] document #{$document['id']}: file missing or malformed storage key\n";
        $missingFiles++;
        continue;
    }
    if (($document['sha256'] ?? '') !== '' && hash_file('sha256', $path) !== $document['sha256']) {
        echo "  [CRITICAL] document #{$document['id']}: on-disk checksum does not match the recorded one\n";
        $checksumMismatches++;
    }
}
$critical += $missingFiles + $checksumMismatches;
if ($missingFiles === 0 && $checksumMismatches === 0) {
    echo '  [ok] every one of ' . count($documents) . " document(s) is present on disk with a matching checksum\n";
}

// Orphan files: on disk but referenced by no row. Not dangerous, but they are personal data with no
// owner, which is its own problem.
$directory = profile_document_dir();
if (is_dir($directory)) {
    $referenced = array_flip(array_map(static fn(array $d): string => (string)$d['storage_key'], $documents));
    $orphans = 0;
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!isset($referenced[$entry])) {
            $orphans++;
        }
    }
    if ($orphans > 0) {
        echo "  [WARN {$orphans}] file(s) in storage/profile-documents referenced by no database row — personal data with no owner\n";
        $warnings += $orphans;
    } else {
        echo "  [ok] no orphan files in storage/profile-documents\n";
    }
}

section('Field validity');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_user_profiles WHERE national_code <> '' AND national_code NOT REGEXP '^[0-9]{10}$'",
    'user national codes that are not exactly 10 digits');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_organization_profiles WHERE ceo_national_code <> '' AND ceo_national_code NOT REGEXP '^[0-9]{10}$'",
    'CEO national codes that are not exactly 10 digits');
$critical += count_check($db,
    "SELECT COUNT(*) FROM ellsms_organization_addresses WHERE postal_code <> '' AND postal_code NOT REGEXP '^[0-9]{10}$'",
    'postal codes that are not exactly 10 digits');
$critical += count_check($db,
    'SELECT COUNT(*) FROM ellsms_organization_profiles
     WHERE company_start_date IS NOT NULL AND company_expiry_date IS NOT NULL AND company_expiry_date < company_start_date',
    'companies whose expiry date precedes their start date');
$warnings += count_check($db,
    'SELECT COUNT(*) FROM ellsms_organization_notification_preferences WHERE low_credit_alert_enabled = 1 AND low_credit_threshold = 0',
    'low-credit alerts enabled with a zero threshold — the alert can never fire', false);
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_organization_notification_preferences
     WHERE email_alert_enabled = 1 AND alert_email <> '' AND alert_email NOT LIKE '%_@_%'",
    'notification email addresses that are not usable', false);

section('Legacy dependency (STEP 52)');
// Every user still being served father_name/address by the read-through fallback rather than from
// the new model. Not an error — but it is the number that has to reach zero before the legacy
// columns can ever be retired, so it is reported rather than left invisible.
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_user_kyc k
     LEFT JOIN ellsms_user_profiles p ON p.user_id = k.user_id
     WHERE p.user_id IS NULL AND (COALESCE(k.father_name,'') <> '' OR COALESCE(k.address,'') <> '')",
    'users whose personal profile still reads through to ellsms_user_kyc — run `make profile-backfill`', false);
$warnings += count_check($db,
    "SELECT COUNT(*) FROM ellsms_user_kyc k
     WHERE (COALESCE(k.id_card_photo,'') <> '' OR COALESCE(k.second_doc_photo,'') <> '')
       AND NOT EXISTS (SELECT 1 FROM ellsms_profile_documents d WHERE d.user_id = k.user_id AND d.legacy_source IS NOT NULL)",
    'users with legacy KYC files not yet imported into the document model', false);

echo "\n";
echo $warnings > 0 ? "{$warnings} warning(s).\n" : "No warnings.\n";
echo $critical > 0
    ? "CRITICAL: {$critical} profile-integrity violation(s) found — see above. Nothing was changed.\n"
    : "OK: zero critical profile-integrity violations.\n";

Logger::info('profile.integrity_check.finished', ['critical' => $critical, 'warnings' => $warnings]);
exit($critical > 0 ? 1 : 0);
