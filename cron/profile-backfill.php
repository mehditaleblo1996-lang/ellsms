<?php
/**
 * ELLSMS — migrate legacy `ellsms_user_kyc` data into the customer-profile model
 * (docs/customer-profile.md §Legacy migration).
 *
 * A SEPARATE, EXPLICIT operator command, not part of the migration — the same split Phase 3 (wallet),
 * Phase 6 (tenant), Phase 11 and Phase 13 established: migrations change schema, backfills move data,
 * and an operator decides when the second one happens.
 *
 * Two jobs, both idempotent:
 *
 *   1. `ellsms_user_kyc.father_name` / `.address` -> `ellsms_user_profiles`. Only for users who have
 *      no profile row yet, so a value the customer has since edited in the new model is never
 *      overwritten by the older one.
 *   2. `ellsms_user_kyc.id_card_photo` / `.second_doc_photo` -> `ellsms_profile_documents`, COPYING
 *      the file into the new store. `legacy_source` records where each came from and is what makes a
 *      second run a no-op; the original file under storage/kyc is left untouched, so this is
 *      reversible by deleting the new rows.
 *
 * Copy rather than move, deliberately: public/kyc-photo.php still serves the legacy files, so moving
 * them would break every existing link the moment this runs.
 *
 * Usage:
 *   php cron/profile-backfill.php            # dry run — reports, writes nothing
 *   php cron/profile-backfill.php --apply    # actually backfill
 */
require_once __DIR__ . '/../app/backend.php';

$apply = in_array('--apply', $argv ?? [], true);
$db = db();

echo "ELLSMS profile backfill" . ($apply ? " (APPLY)" : " (dry run — nothing is written)") . "\n\n";

/* ---------- 1. Personal profile fields ---------- */
$candidates = $db->query(
    "SELECT k.user_id, k.father_name, k.address
     FROM ellsms_user_kyc k
     LEFT JOIN ellsms_user_profiles p ON p.user_id = k.user_id
     WHERE p.user_id IS NULL AND (COALESCE(k.father_name,'') <> '' OR COALESCE(k.address,'') <> '')"
)->fetchAll();

echo "Personal profile rows to create: " . count($candidates) . "\n";
$profilesCreated = 0;
foreach ($candidates as $row) {
    if (!$apply) {
        continue;
    }
    // ON DUPLICATE KEY UPDATE user_id = user_id is a deliberate no-op: if a profile row appeared
    // between the SELECT above and this INSERT, the NEWER data wins and the legacy value is dropped.
    $db->prepare(
        'INSERT INTO ellsms_user_profiles (user_id, father_name, personal_address)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE user_id = user_id'
    )->execute([(int)$row['user_id'], (string)($row['father_name'] ?? ''), $row['address']]);
    $profilesCreated++;
}
if ($apply) {
    echo "  created: {$profilesCreated}\n";
}

/* ---------- 2. Legacy KYC documents ---------- */
// The two legacy slots map onto the two personal document types. `second_doc_photo` was a free
// "second identity document" slot, which in practice is the birth certificate — the only other
// personal document this product asks for.
$legacySlots = [
    'id_card_photo'    => 'national_card',
    'second_doc_photo' => 'birth_certificate',
];

$documentsCopied = 0;
$documentsSkipped = 0;
$missingFiles = 0;

foreach ($legacySlots as $column => $documentType) {
    $rows = $db->query("SELECT user_id, {$column} AS filename FROM ellsms_user_kyc WHERE COALESCE({$column}, '') <> ''")->fetchAll();
    echo "\nLegacy {$column} -> {$documentType}: " . count($rows) . " candidate(s)\n";

    foreach ($rows as $row) {
        $userId = (int)$row['user_id'];
        $filename = (string)$row['filename'];
        $legacySource = $column . ':' . $filename;

        $already = $db->prepare('SELECT COUNT(*) FROM ellsms_profile_documents WHERE legacy_source = ?');
        $already->execute([$legacySource]);
        if ((int)$already->fetchColumn() > 0) {
            $documentsSkipped++;
            continue;   // idempotency: this exact legacy file has already been imported
        }

        $sourcePath = KYC_STORAGE_DIR . '/' . $filename;
        // The legacy filename shape is validated the same way public/kyc-photo.php validates it,
        // rather than trusting a database value to be a safe path component.
        if (preg_match('/^u\d+_[a-z_]+_[0-9a-f]{16}\.(jpg|png|webp|pdf)$/', $filename) !== 1 || !is_file($sourcePath)) {
            echo "  [missing] user #{$userId}: {$filename}\n";
            $missingFiles++;
            continue;
        }

        if (!$apply) {
            $documentsCopied++;
            continue;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $storageKey = bin2hex(random_bytes(20)) . '.' . $extension;
        $directory = profile_document_dir();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            fwrite(STDERR, "Cannot create {$directory}\n");
            exit(1);
        }
        if (!copy($sourcePath, $directory . '/' . $storageKey)) {
            echo "  [copy failed] user #{$userId}: {$filename}\n";
            $missingFiles++;
            continue;
        }
        @chmod($directory . '/' . $storageKey, 0640);

        $mimeByExtension = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        try {
            $db->prepare(
                'INSERT INTO ellsms_profile_documents
                   (user_id, document_type, storage_key, original_filename, mime_type, size_bytes, sha256,
                    status, uploaded_by_user_id, legacy_source, active_slot)
                 VALUES (?,?,?,?,?,?,?,\'active\',NULL,?,?)'
            )->execute([
                $userId, $documentType, $storageKey, $filename,
                $mimeByExtension[$extension] ?? 'application/octet-stream',
                (int)filesize($sourcePath), hash_file('sha256', $sourcePath) ?: '',
                $legacySource,
                profile_document_slot('user', $userId, $documentType),
            ]);
            $documentsCopied++;
        } catch (PDOException $e) {
            // Most likely the active slot is already taken by a document uploaded through the new
            // model. That newer document wins — the legacy one is simply not imported, and the file
            // just written is removed so nothing is orphaned.
            @unlink($directory . '/' . $storageKey);
            echo "  [skipped] user #{$userId}: an active {$documentType} already exists\n";
            $documentsSkipped++;
        }
    }
}

echo "\nDocuments " . ($apply ? 'imported' : 'that would be imported') . ": {$documentsCopied}\n";
echo "Already imported / superseded: {$documentsSkipped}\n";
echo "Missing or unreadable legacy files: {$missingFiles}\n";

if (!$apply) {
    echo "\nDry run — re-run with --apply to write. Legacy rows and files are never modified or deleted.\n";
} else {
    Logger::info('profile.backfill.finished', [
        'profiles_created' => $profilesCreated, 'documents_imported' => $documentsCopied,
        'skipped' => $documentsSkipped, 'missing_files' => $missingFiles,
    ]);
    echo "\nDone. storage/kyc is untouched — public/kyc-photo.php keeps working for legacy links.\n";
}
exit($missingFiles > 0 ? 1 : 0);
