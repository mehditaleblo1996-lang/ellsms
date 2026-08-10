<?php
/**
 * ELLSMS — authenticated download of a private profile document (docs/customer-profile.md §Documents).
 *
 * The ONLY way a stored document is ever readable. Files live outside the web root under
 * storage/profile-documents/ with opaque random names, so there is no URL to guess and no directory
 * to list — every read arrives here and is authorized before a single byte is written.
 *
 * Authorization, in order:
 *   - a USER document is readable by that user, or by a platform admin (not while impersonating);
 *   - an ORGANIZATION document is readable by any ACTIVE member of that organization, or by a
 *     platform admin (not while impersonating).
 *
 * IMPERSONATION (STEP 27): a support session sees exactly what the target user could see and nothing
 * more. The real actor's platform-admin privilege is deliberately NOT consulted here — combining
 * admin document access with a target-user view context is precisely the escalation the support mode
 * exists to prevent. An administrator who needs unrestricted access exits impersonation first.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();

$documentId = (int)($_GET['id'] ?? 0);
$document = profile_document_find($documentId);
if ($document === null) {
    http_response_code(404);
    exit('مدرک یافت نشد.');
}

// While impersonating, is_admin() is already false (the effective user is the customer), so this
// resolves to the target's own reach. Stated explicitly because it is load-bearing, not incidental.
$isPlatformAdmin = is_admin() && !is_impersonating();

$allowed = false;
if ($document['user_id'] !== null) {
    $allowed = (int)$document['user_id'] === (int)$me['id'] || $isPlatformAdmin;
} elseif ($document['organization_id'] !== null) {
    $allowed = can_access_organization((int)$me['id'], (int)$document['organization_id']) || $isPlatformAdmin;
}

if (!$allowed) {
    // 404, not 403: a distinguishable "exists but forbidden" would confirm which document ids are
    // real, which is the whole of an enumeration attack against an opaque id.
    Logger::warning('profile.document_access_denied', [
        'document_id' => $documentId, 'effective_user_id' => $me['id'],
        'impersonator_user_id' => is_impersonating() ? real_actor_user_id() : null,
    ]);
    http_response_code(404);
    exit('مدرک یافت نشد.');
}

$path = profile_document_path((string)$document['storage_key']);
if ($path === null) {
    // Metadata without a file — a real operational fault, surfaced by profile-integrity-check.
    Logger::error('profile.document_file_missing', ['document_id' => $documentId, 'storage_key' => $document['storage_key']]);
    http_response_code(404);
    exit('فایل این مدرک در دسترس نیست.');
}

$mime = (string)$document['mime_type'];
if (!isset(array_flip(PROFILE_DOCUMENT_ALLOWED_MIME)[PROFILE_DOCUMENT_ALLOWED_MIME[$mime] ?? ''])) {
    // Defense in depth: only ever serve a type this application itself accepted on upload, never a
    // type read back from a row that could have been altered.
    $mime = 'application/octet-stream';
}

Logger::info('profile.document_downloaded', [
    'document_id' => $documentId, 'effective_user_id' => $me['id'],
    'impersonator_user_id' => is_impersonating() ? real_actor_user_id() : null,
]);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
// The filename is rebuilt from the validated document TYPE and extension — never from
// original_filename, which is uploader-controlled and would otherwise land in a response header.
$extension = PROFILE_DOCUMENT_ALLOWED_MIME[$mime] ?? 'bin';
header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9_\-]/', '', (string)$document['document_type']) . '.' . $extension . '"');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=0, no-store');
header('Pragma: no-cache');
readfile($path);
