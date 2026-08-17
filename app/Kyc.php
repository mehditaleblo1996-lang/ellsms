<?php
/**
 * ELLSMS — KYC review workflow: state machine, per-document review, submission eligibility, and
 * centralized feature gating (docs/profile-kyc.md).
 *
 * Builds directly on app/Profile.php (docs/customer-profile.md), which deliberately stopped short of
 * a review workflow: "Documents are stored, replaced and archived; nothing reviews or approves them."
 * This file is exactly that missing piece, and nothing more — it does not touch document storage,
 * upload validation, or the personal/company field model, all of which stay owned by Profile.php.
 *
 * OWNERSHIP: one ellsms_kyc_requests row per ORGANIZATION (the account/tenant boundary), covering
 * both individual and legal accounts — the same boundary account_type itself lives on
 * (ellsms_organization_profiles.organization_id). A row is created lazily, on first read, so an
 * organization that never touches KYC has no row and is completely unaffected by this feature.
 *
 * RBAC: exactly like the pre-existing KYC surface (public/users.php's kyc_save, public/kyc-photo.php),
 * every admin-facing action in this file is PLATFORM-ADMIN-ONLY (require_admin() at the call site,
 * never delegated to an organization role) — Permissions::KYC_VIEW / KYC_MANAGE stay RESERVED exactly
 * as app/rbac.php already documents ("nobody but platform admin gets those"). This phase does not
 * reopen that decision; see docs/profile-kyc.md §RBAC for the full reasoning.
 */

declare(strict_types=1);

/* ==========================================================================
   State machine catalog
   ========================================================================== */

const KYC_STATUSES = [
    'draft'             => 'احراز نشده',
    'submitted'         => 'ارسال شده',
    'under_review'      => 'در حال بررسی',
    'needs_correction'  => 'نیازمند اصلاح',
    'approved'          => 'تأیید شده',
    'rejected'          => 'رد شده',
];

/** Every allowed transition, explicit — anything not listed here is refused, never silently allowed. */
const KYC_TRANSITIONS = [
    'draft'            => ['submitted'],
    'submitted'        => ['under_review'],
    'under_review'     => ['approved', 'needs_correction', 'rejected'],
    'needs_correction' => ['submitted'],
    'rejected'         => ['submitted'],
    'approved'         => [],
];

const KYC_DOCUMENT_REVIEW_STATUSES = [
    'pending'  => 'در انتظار بررسی',
    'approved' => 'تأیید شده',
    'rejected' => 'رد شده',
];

function kyc_status_label(string $status): string {
    return KYC_STATUSES[$status] ?? $status;
}

function kyc_document_review_status_label(string $status): string {
    return KYC_DOCUMENT_REVIEW_STATUSES[$status] ?? $status;
}

/** Raised for any KYC validation/transition failure; ->getMessage() is safe to show (AppException semantics). */
class KycException extends AppException {}

/* ==========================================================================
   Request read/write
   ========================================================================== */

/** Always an array — an organization with no row yet reads as a fresh 'draft' request, never null. */
function kyc_request_get(int $organizationId): array {
    $empty = [
        'organization_id' => $organizationId, 'status' => 'draft',
        'submitted_at' => null, 'review_started_at' => null, 'reviewed_at' => null,
        'reviewed_by_user_id' => null, 'review_note' => '',
    ];
    if ($organizationId <= 0) {
        return $empty;
    }
    try {
        $st = db()->prepare('SELECT * FROM ellsms_kyc_requests WHERE organization_id = ?');
        $st->execute([$organizationId]);
        return $st->fetch() ?: $empty;
    } catch (Throwable $t) {
        Logger::error('kyc.request_get_failed', ['organization_id' => $organizationId, 'exception' => $t]);
        return $empty;
    }
}

/**
 * The single choke point every status change goes through — no call site anywhere is allowed to
 * UPDATE ellsms_kyc_requests.status directly, so KYC_TRANSITIONS is the only place "what's allowed
 * next" is decided (§7: "Do not allow arbitrary status mutation").
 */
function kyc_transition(int $organizationId, string $toStatus, int $actorUserId, string $note = ''): array {
    if ($organizationId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_organization'];
    }
    if (!array_key_exists($toStatus, KYC_STATUSES)) {
        return ['ok' => false, 'reason' => 'invalid_status'];
    }

    return db_transaction(function (PDO $db) use ($organizationId, $toStatus, $actorUserId, $note): array {
        $st = $db->prepare('SELECT * FROM ellsms_kyc_requests WHERE organization_id = ? FOR UPDATE');
        $st->execute([$organizationId]);
        $current = $st->fetch();
        $fromStatus = $current ? (string)$current['status'] : 'draft';

        if (!in_array($toStatus, KYC_TRANSITIONS[$fromStatus] ?? [], true)) {
            return ['ok' => false, 'reason' => 'invalid_transition', 'from' => $fromStatus];
        }

        $note = profile_clean_text($note, 1000);
        $sets = ['status = ?'];
        $params = [$toStatus];

        if ($toStatus === 'submitted') {
            $sets[] = 'submitted_at = UTC_TIMESTAMP()';
            $sets[] = 'review_started_at = NULL';
            $sets[] = 'reviewed_at = NULL';
            $sets[] = "review_note = ''";
        } elseif ($toStatus === 'under_review') {
            $sets[] = 'review_started_at = UTC_TIMESTAMP()';
            $sets[] = 'reviewed_by_user_id = ?';
            $params[] = $actorUserId;
        } elseif (in_array($toStatus, ['approved', 'needs_correction', 'rejected'], true)) {
            $sets[] = 'reviewed_at = UTC_TIMESTAMP()';
            $sets[] = 'reviewed_by_user_id = ?';
            $sets[] = 'review_note = ?';
            $params[] = $actorUserId;
            $params[] = $note;
        }

        $db->prepare(
            "INSERT INTO ellsms_kyc_requests (organization_id, status) VALUES (?, 'draft')
             ON DUPLICATE KEY UPDATE organization_id = organization_id"
        )->execute([$organizationId]);
        $db->prepare('UPDATE ellsms_kyc_requests SET ' . implode(', ', $sets) . ' WHERE organization_id = ?')
           ->execute([...$params, $organizationId]);

        $auditAction = [
            'submitted'        => 'kyc.submitted',
            'under_review'     => 'kyc.review_started',
            'approved'         => 'kyc.approved',
            'needs_correction' => 'kyc.needs_correction',
            'rejected'         => 'kyc.rejected',
        ][$toStatus] ?? 'kyc.status_changed';
        // Review notes are shown back to the customer verbatim (escaped at render) but are NEVER
        // written into the audit trail themselves — a review note can legitimately reference
        // sensitive specifics ("national code photo is blurry"), and the audit log is not the place
        // for a second copy of that text (§19/§28, mirrors profile_mask_identifier's own reasoning).
        audit($actorUserId, $auditAction, "org={$organizationId} from={$fromStatus} to={$toStatus}");
        Logger::info('kyc.transitioned', ['organization_id' => $organizationId, 'from' => $fromStatus, 'to' => $toStatus, 'actor_user_id' => $actorUserId]);

        return ['ok' => true, 'from' => $fromStatus, 'to' => $toStatus];
    });
}

/* ==========================================================================
   Submission eligibility (§16) — ONE centralized check, never duplicated between UI and backend
   ========================================================================== */

/**
 * @return array{ok:bool, missing:list<string>}
 */
function kyc_can_submit(int $organizationId, int $userId, string $accountType, array $userProfile, array $organizationProfile, array $address): array {
    $missing = [];

    if ($accountType === 'legal') {
        foreach (['legal_name', 'national_id', 'ceo_name', 'ceo_national_code'] as $field) {
            if (($organizationProfile[$field] ?? '') === '') {
                $missing[] = 'اطلاعات سازمان/نماینده ناقص است';
                break;
            }
        }
        $requiredDocs = PROFILE_REQUIRED_DOCUMENTS_LEGAL;
        $documents = profile_documents_list(['organization' => $organizationId], false);
    } else {
        foreach (['father_name', 'national_code'] as $field) {
            if (($userProfile[$field] ?? '') === '') {
                $missing[] = 'اطلاعات فردی ناقص است';
                break;
            }
        }
        $requiredDocs = PROFILE_REQUIRED_DOCUMENTS_INDIVIDUAL;
        $documents = profile_documents_list(['user' => $userId], false);
    }

    if (($address['postal_code'] ?? '') === '' || ($address['city'] ?? '') === '') {
        $missing[] = 'آدرس ناقص است';
    }

    $presentTypes = array_column($documents, 'document_type');
    foreach ($requiredDocs as $type) {
        if (!in_array($type, $presentTypes, true)) {
            $missing[] = 'مدرک «' . profile_document_type_label($type) . '» بارگذاری نشده است';
        }
    }

    return ['ok' => $missing === [], 'missing' => $missing];
}

/**
 * The one action the self-service profile page calls. Re-validates eligibility itself (never trusts
 * the caller already checked) and re-uses kyc_transition() so the same state machine guard applies
 * here as everywhere else.
 */
function kyc_submit(int $organizationId, int $userId, string $accountType, array $userProfile, array $organizationProfile, array $address): array {
    $eligibility = kyc_can_submit($organizationId, $userId, $accountType, $userProfile, $organizationProfile, $address);
    if (!$eligibility['ok']) {
        return ['ok' => false, 'reason' => 'incomplete', 'missing' => $eligibility['missing']];
    }
    $result = kyc_transition($organizationId, 'submitted', $userId);
    if (!$result['ok']) {
        return ['ok' => false, 'reason' => $result['reason'] ?? 'invalid_transition'];
    }
    return ['ok' => true];
}

/* ==========================================================================
   Per-document review (§9/§17) — admin-only, enforced by the caller (require_admin())
   ========================================================================== */

function kyc_document_review(int $documentId, string $reviewStatus, int $actorUserId, string $note = ''): array {
    if (!array_key_exists($reviewStatus, KYC_DOCUMENT_REVIEW_STATUSES) || $reviewStatus === 'pending') {
        return ['ok' => false, 'reason' => 'invalid_status'];
    }
    $document = profile_document_find($documentId);
    if ($document === null) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $note = profile_clean_text($note, 500);

    db()->prepare(
        'UPDATE ellsms_profile_documents
         SET review_status = ?, reviewed_at = UTC_TIMESTAMP(), reviewed_by_user_id = ?, review_note = ?
         WHERE id = ?'
    )->execute([$reviewStatus, $actorUserId, $note, $documentId]);

    $auditAction = $reviewStatus === 'approved' ? 'kyc.document_approved' : 'kyc.document_rejected';
    $ownerRef = $document['organization_id'] !== null
        ? 'org=#' . $document['organization_id']
        : 'user=#' . $document['user_id'];
    audit($actorUserId, $auditAction, "{$ownerRef} type={$document['document_type']} id={$documentId}");
    Logger::info('kyc.document_reviewed', ['document_id' => $documentId, 'review_status' => $reviewStatus, 'actor_user_id' => $actorUserId]);
    return ['ok' => true];
}

/* ==========================================================================
   Admin listing/search (§17)
   ========================================================================== */

/**
 * @return list<array<string,mixed>>
 */
function kyc_requests_list(?string $statusFilter = null, string $search = '', int $limit = 100): array {
    $sql = "SELECT r.*, o.name AS organization_name, op.account_type, op.legal_name
            FROM ellsms_kyc_requests r
            JOIN ellsms_organizations o ON o.id = r.organization_id
            LEFT JOIN ellsms_organization_profiles op ON op.organization_id = r.organization_id
            WHERE 1=1";
    $params = [];
    if ($statusFilter !== null && $statusFilter !== '' && array_key_exists($statusFilter, KYC_STATUSES)) {
        $sql .= ' AND r.status = ?';
        $params[] = $statusFilter;
    }
    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (o.name LIKE ? OR op.legal_name LIKE ? OR r.organization_id = ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = ctype_digit($search) ? (int)$search : -1;
    }
    $sql .= ' ORDER BY (r.submitted_at IS NULL), r.submitted_at DESC, r.organization_id DESC LIMIT ' . max(1, min(500, $limit));
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ==========================================================================
   Feature gating (§22) — the ONE place any KYC-gated feature is decided
   ========================================================================== */

/**
 * Every gate this catalog defines, with a human label for the admin config UI. Adding a new gate is a
 * one-line change here — never a scattered `if ($kyc === 'approved')` at the call site.
 */
const KYC_FEATURE_GATES = [
    'credit_purchase'          => 'خرید اعتبار',
    'dedicated_number_request' => 'درخواست شماره اختصاصی',
    'production_api'           => 'دسترسی API عملیاتی',
    'high_volume_send'         => 'ارسال حجم بالا',
];

/** ellsms_settings key for one gate's on/off switch. */
function kyc_gate_setting_key(string $gate): string {
    return 'kyc_gate.' . $gate;
}

/**
 * Whether $gate currently REQUIRES an approved KYC (admin-configurable, defaults to false/off for
 * every gate — §22: "Default migration behavior should preserve the current production behavior. Do
 * not unexpectedly block current customers.").
 */
function kyc_gate_required(string $gate): bool {
    if (!array_key_exists($gate, KYC_FEATURE_GATES)) {
        return false;
    }
    return setting(kyc_gate_setting_key($gate), '0') === '1';
}

function kyc_gate_set_required(string $gate, bool $required, int $actorUserId): void {
    if (!array_key_exists($gate, KYC_FEATURE_GATES)) {
        return;
    }
    set_setting(kyc_gate_setting_key($gate), $required ? '1' : '0');
    audit($actorUserId, 'kyc.gate_configured', "gate={$gate} required=" . ($required ? '1' : '0'));
}

/**
 * The pure decision, isolated from the ellsms_settings lookup on purpose — trivially unit-testable
 * (tests/Unit/KycWorkflowTest.php) without touching setting()'s process-wide cache, which by design
 * (see IntegrationTestCase's own docblock on `default_originator`) only ever reflects the FIRST value
 * read in a process and cannot be exercised for a second value from an integration test.
 */
function kyc_feature_allowed_for_status(bool $gateRequired, string $kycStatus): bool {
    return !$gateRequired || $kycStatus === 'approved';
}

/**
 * THE centralized policy function (§22's explicit request) — every call site in this codebase that
 * needs to know "is $organizationId allowed to use $gate right now" calls this and NOTHING else. It
 * is intentionally a pure yes/no: a gate that is off always returns true (today's behavior, unchanged
 * for every existing customer); a gate that is on returns true only for an organization whose KYC
 * request is 'approved'.
 *
 * Not wired into any existing feature's code path by this phase (billing/wallet/numbers stay
 * untouched per the phase brief's own "do not change... except where explicitly required for
 * configurable KYC gating" — and no gate defaults to required, so nothing needs to change yet). The
 * function exists, is tested, and is ready for the day an operator flips a gate on; see
 * docs/profile-kyc.md §Feature gating for exactly what integrating a real call site looks like.
 */
function kyc_feature_allowed(int $organizationId, string $gate): bool {
    $request = kyc_request_get($organizationId);
    return kyc_feature_allowed_for_status(kyc_gate_required($gate), (string)($request['status'] ?? 'draft'));
}
