<?php
/**
 * ELLSMS — KYC review workflow: state machine, per-document review, submission eligibility, and
 * centralized feature gating (docs/profile-kyc.md).
 */
declare(strict_types=1);
require_once __DIR__ . '/NotificationCenter.php';

const KYC_STATUSES = [
    'draft'             => 'احراز نشده',
    'submitted'         => 'ارسال شده',
    'under_review'      => 'در حال بررسی',
    'needs_correction'  => 'نیازمند اصلاح',
    'approved'          => 'تأیید شده',
    'rejected'          => 'رد شده',
];

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

function kyc_status_label(string $status): string { return KYC_STATUSES[$status] ?? $status; }
function kyc_document_review_status_label(string $status): string { return KYC_DOCUMENT_REVIEW_STATUSES[$status] ?? $status; }
class KycException extends AppException {}

function kyc_request_get(int $organizationId): array {
    $empty = [
        'organization_id' => $organizationId, 'status' => 'draft',
        'submitted_at' => null, 'review_started_at' => null, 'reviewed_at' => null,
        'reviewed_by_user_id' => null, 'review_note' => '',
    ];
    if ($organizationId <= 0) return $empty;
    try {
        $st = db()->prepare('SELECT * FROM ellsms_kyc_requests WHERE organization_id = ?');
        $st->execute([$organizationId]);
        return $st->fetch() ?: $empty;
    } catch (Throwable $t) {
        Logger::error('kyc.request_get_failed', ['organization_id' => $organizationId, 'exception' => $t]);
        return $empty;
    }
}

/** Owner user id for an organization, used for individual KYC and user notifications. */
function kyc_owner_user_id(int $organizationId): int {
    if ($organizationId <= 0) return 0;
    $st = db()->prepare("SELECT user_id FROM ellsms_organization_memberships WHERE organization_id=? AND role='owner' AND status='active' ORDER BY id LIMIT 1");
    $st->execute([$organizationId]);
    return (int)($st->fetchColumn() ?: 0);
}

function kyc_can_approve(int $organizationId): array {
    $orgProfile = profile_organization_get($organizationId);
    $accountType = (string)($orgProfile['account_type'] ?? 'individual');
    if ($accountType === 'legal') {
        $required = PROFILE_REQUIRED_DOCUMENTS_LEGAL;
        $documents = profile_documents_list(['organization' => $organizationId], false);
    } else {
        $ownerUserId = kyc_owner_user_id($organizationId);
        if ($ownerUserId <= 0) return ['ok' => false, 'missing' => ['مالک فعال سازمان پیدا نشد']];
        $required = PROFILE_REQUIRED_DOCUMENTS_INDIVIDUAL;
        $documents = profile_documents_list(['user' => $ownerUserId], false);
    }

    $byType = [];
    foreach ($documents as $document) {
        if (($document['status'] ?? 'active') !== 'active') continue;
        $byType[(string)$document['document_type']] = $document;
    }

    $missing = [];
    foreach ($required as $type) {
        if (!isset($byType[$type])) {
            $missing[] = 'مدرک «' . profile_document_type_label($type) . '» موجود نیست';
            continue;
        }
        $review = (string)($byType[$type]['review_status'] ?? 'pending');
        if ($review !== 'approved') $missing[] = 'مدرک «' . profile_document_type_label($type) . '» هنوز تأیید نشده است';
    }
    return ['ok' => $missing === [], 'missing' => $missing];
}

function kyc_transition(int $organizationId, string $toStatus, int $actorUserId, string $note = ''): array {
    if ($organizationId <= 0) return ['ok' => false, 'reason' => 'invalid_organization'];
    if (!array_key_exists($toStatus, KYC_STATUSES)) return ['ok' => false, 'reason' => 'invalid_status'];

    $note = profile_clean_text($note, 1000);
    if (in_array($toStatus, ['needs_correction', 'rejected'], true) && $note === '') {
        return ['ok' => false, 'reason' => 'review_note_required'];
    }
    if ($toStatus === 'approved') {
        $approval = kyc_can_approve($organizationId);
        if (!$approval['ok']) return ['ok' => false, 'reason' => 'documents_not_approved', 'missing' => $approval['missing']];
    }

    $result = db_transaction(function (PDO $db) use ($organizationId, $toStatus, $actorUserId, $note): array {
        $st = $db->prepare('SELECT * FROM ellsms_kyc_requests WHERE organization_id = ? FOR UPDATE');
        $st->execute([$organizationId]);
        $current = $st->fetch();
        $fromStatus = $current ? (string)$current['status'] : 'draft';
        if (!in_array($toStatus, KYC_TRANSITIONS[$fromStatus] ?? [], true)) {
            return ['ok' => false, 'reason' => 'invalid_transition', 'from' => $fromStatus];
        }

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

        $db->prepare("INSERT INTO ellsms_kyc_requests (organization_id, status) VALUES (?, 'draft') ON DUPLICATE KEY UPDATE organization_id=organization_id")
           ->execute([$organizationId]);
        $db->prepare('UPDATE ellsms_kyc_requests SET ' . implode(', ', $sets) . ' WHERE organization_id = ?')
           ->execute([...$params, $organizationId]);

        $auditAction = [
            'submitted' => 'kyc.submitted', 'under_review' => 'kyc.review_started',
            'approved' => 'kyc.approved', 'needs_correction' => 'kyc.needs_correction',
            'rejected' => 'kyc.rejected',
        ][$toStatus] ?? 'kyc.status_changed';
        audit($actorUserId, $auditAction, "org={$organizationId} from={$fromStatus} to={$toStatus}");
        Logger::info('kyc.transitioned', ['organization_id'=>$organizationId,'from'=>$fromStatus,'to'=>$toStatus,'actor_user_id'=>$actorUserId]);
        return ['ok'=>true,'from'=>$fromStatus,'to'=>$toStatus];
    });

    // External channels are dispatched only after the DB transition commits. Notification failures
    // are fail-open and cannot roll back a valid KYC decision.
    if (!empty($result['ok'])) {
        if ($toStatus === 'submitted') {
            notification_dispatch_admins(
                'kyc.submitted',
                'درخواست احراز هویت جدید',
                'یک درخواست KYC برای سازمان #' . $organizationId . ' آماده بررسی است.',
                '/kyc-review.php?id=' . $organizationId,
                'info'
            );
        } elseif (in_array($toStatus, ['approved','needs_correction','rejected'], true)) {
            $ownerUserId = kyc_owner_user_id($organizationId);
            if ($ownerUserId > 0) {
                $event = 'kyc.' . $toStatus;
                $title = match ($toStatus) {
                    'approved' => 'احراز هویت شما تأیید شد',
                    'needs_correction' => 'احراز هویت نیاز به اصلاح دارد',
                    default => 'احراز هویت شما رد شد',
                };
                $body = $toStatus === 'approved'
                    ? 'احراز هویت حساب با موفقیت تأیید شد.'
                    : ($note !== '' ? $note : 'برای جزئیات وارد حساب کاربری شوید.');
                $severity = $toStatus === 'approved' ? 'success' : ($toStatus === 'rejected' ? 'error' : 'warning');
                notification_dispatch_user($ownerUserId, $organizationId, $event, $title, $body, '/profile.php', $severity);
            }
        }
    }
    return $result;
}

function kyc_can_submit(int $organizationId, int $userId, string $accountType, array $userProfile, array $organizationProfile, array $address): array {
    $missing = [];
    if ($accountType === 'legal') {
        foreach (['legal_name','national_id','ceo_name','ceo_national_code'] as $field) {
            if (($organizationProfile[$field] ?? '') === '') { $missing[] = 'اطلاعات سازمان/نماینده ناقص است'; break; }
        }
        $requiredDocs = PROFILE_REQUIRED_DOCUMENTS_LEGAL;
        $documents = profile_documents_list(['organization'=>$organizationId], false);
    } else {
        foreach (['father_name','national_code'] as $field) {
            if (($userProfile[$field] ?? '') === '') { $missing[] = 'اطلاعات فردی ناقص است'; break; }
        }
        $requiredDocs = PROFILE_REQUIRED_DOCUMENTS_INDIVIDUAL;
        $documents = profile_documents_list(['user'=>$userId], false);
    }
    if (($address['postal_code'] ?? '') === '' || ($address['city'] ?? '') === '') $missing[] = 'آدرس ناقص است';
    $presentTypes = array_column($documents, 'document_type');
    foreach ($requiredDocs as $type) {
        if (!in_array($type, $presentTypes, true)) $missing[] = 'مدرک «' . profile_document_type_label($type) . '» بارگذاری نشده است';
    }
    return ['ok'=>$missing===[],'missing'=>$missing];
}

function kyc_submit(int $organizationId, int $userId, string $accountType, array $userProfile, array $organizationProfile, array $address): array {
    $eligibility = kyc_can_submit($organizationId,$userId,$accountType,$userProfile,$organizationProfile,$address);
    if (!$eligibility['ok']) return ['ok'=>false,'reason'=>'incomplete','missing'=>$eligibility['missing']];
    $result = kyc_transition($organizationId,'submitted',$userId);
    return $result['ok'] ? ['ok'=>true] : ['ok'=>false,'reason'=>$result['reason'] ?? 'invalid_transition'];
}

function kyc_document_review(int $documentId, string $reviewStatus, int $actorUserId, string $note = ''): array {
    if (!array_key_exists($reviewStatus,KYC_DOCUMENT_REVIEW_STATUSES) || $reviewStatus==='pending') return ['ok'=>false,'reason'=>'invalid_status'];
    $document = profile_document_find($documentId);
    if ($document === null) return ['ok'=>false,'reason'=>'not_found'];
    $note = profile_clean_text($note,500);
    if ($reviewStatus === 'rejected' && $note === '') return ['ok'=>false,'reason'=>'review_note_required'];
    db()->prepare('UPDATE ellsms_profile_documents SET review_status=?, reviewed_at=UTC_TIMESTAMP(), reviewed_by_user_id=?, review_note=? WHERE id=?')
       ->execute([$reviewStatus,$actorUserId,$note,$documentId]);
    $auditAction = $reviewStatus==='approved' ? 'kyc.document_approved' : 'kyc.document_rejected';
    $ownerRef = $document['organization_id'] !== null ? 'org=#'.$document['organization_id'] : 'user=#'.$document['user_id'];
    audit($actorUserId,$auditAction,"{$ownerRef} type={$document['document_type']} id={$documentId}");
    Logger::info('kyc.document_reviewed',['document_id'=>$documentId,'review_status'=>$reviewStatus,'actor_user_id'=>$actorUserId]);
    return ['ok'=>true];
}

function kyc_requests_list(?string $statusFilter = null, string $search = '', int $limit = 100): array {
    $sql = "SELECT r.*, o.name AS organization_name, op.account_type, op.legal_name FROM ellsms_kyc_requests r JOIN ellsms_organizations o ON o.id=r.organization_id LEFT JOIN ellsms_organization_profiles op ON op.organization_id=r.organization_id WHERE 1=1";
    $params=[];
    if ($statusFilter!==null && $statusFilter!=='' && array_key_exists($statusFilter,KYC_STATUSES)) { $sql.=' AND r.status=?'; $params[]=$statusFilter; }
    $search=trim($search);
    if ($search!=='') { $sql.=' AND (o.name LIKE ? OR op.legal_name LIKE ? OR r.organization_id=?)'; $like='%'.$search.'%'; array_push($params,$like,$like,ctype_digit($search)?(int)$search:-1); }
    $sql.=' ORDER BY (r.submitted_at IS NULL), r.submitted_at DESC, r.organization_id DESC LIMIT '.max(1,min(500,$limit));
    $st=db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}

const KYC_FEATURE_GATES = [
    'sms_send'                 => 'ارسال پیامک',
    'credit_purchase'          => 'خرید اعتبار',
    'dedicated_number_request' => 'درخواست شماره اختصاصی',
    'production_api'           => 'دسترسی API عملیاتی',
    'high_volume_send'         => 'ارسال حجم بالا',
];
function kyc_gate_setting_key(string $gate): string { return 'kyc_gate.'.$gate; }
function kyc_gate_required(string $gate): bool { return array_key_exists($gate,KYC_FEATURE_GATES) && setting(kyc_gate_setting_key($gate),'0')==='1'; }
function kyc_gate_set_required(string $gate,bool $required,int $actorUserId): void {
    if (!array_key_exists($gate,KYC_FEATURE_GATES)) return;
    set_setting(kyc_gate_setting_key($gate),$required?'1':'0');
    audit($actorUserId,'kyc.gate_configured',"gate={$gate} required=".($required?'1':'0'));
}
function kyc_feature_allowed_for_status(bool $gateRequired,string $kycStatus): bool { return !$gateRequired || $kycStatus==='approved'; }
function kyc_feature_allowed(int $organizationId,string $gate): bool {
    $request=kyc_request_get($organizationId);
    return kyc_feature_allowed_for_status(kyc_gate_required($gate),(string)($request['status'] ?? 'draft'));
}
function kyc_gate_denial_message(int $organizationId,string $gate): string {
    $status=(string)(kyc_request_get($organizationId)['status'] ?? 'draft');
    $label=KYC_FEATURE_GATES[$gate] ?? 'این قابلیت';
    return "{$label} تا تکمیل و تأیید احراز هویت در دسترس نیست. وضعیت فعلی: ".kyc_status_label($status).'.';
}