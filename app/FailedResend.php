<?php
/**
 * ELLSMS — «ارسال مجدد ناموفق‌ها»: resend a bulk job's failed rows as a NEW p2p job
 * (docs/failed-resend.md).
 *
 * The rows are copied (mobile + the row's own text) into a fresh job through bulk_queue_job(), so
 * the new job is priced, quota-checked and wallet-reserved exactly like any other p2p upload, in one
 * transaction — nothing here touches the original job or its items. Every resend is recorded in
 * ellsms_failed_resends (db/migrations/2026_11_15_failed_resends.sql) and in the audit log.
 *
 * The new job always belongs to the ORIGINAL job's owner: when a platform admin resends a
 * customer's failures, the customer pays and sees the job in their own panel.
 */

declare(strict_types=1);

/** Job types whose rows are plain (number, text) pairs that can be sent again as p2p. */
const FAILED_RESEND_TYPES = ['p2p', 'gradual', 'smart'];

/** Rows refused per resend above this (FAILED_RESEND_MAX_ITEMS) — one web request prices them all. */
function failed_resend_max_items(): int {
    return max(1, (int)(env('FAILED_RESEND_MAX_ITEMS', '10000') ?? '10000'));
}

/**
 * The «ناموفق» rows to resend: the same rows the job page counts as failed, except rows the user
 * cancelled. Delivery failures (failed/rejected/expired) count only when $withDelivery — for a
 * viewer who does not see delivery states, those rows read «ارسال‌شده» and are not resent.
 * The fragment is over `bi` (ellsms_bulk_items).
 */
function failed_resend_rows_sql(bool $withDelivery): string {
    $failed = "bi.status = 'failed'";
    if ($withDelivery) {
        $failed = "(bi.status = 'failed' OR (bi.status <> 'cancelled' AND bi.delivery_status IN ('failed','rejected','expired')))";
    }
    return "{$failed} AND COALESCE(bi.delivery_status, '') <> 'delivered'";
}

/** Whether this job can be resent at all (type, finished, not a masked aimed send). */
function failed_resend_job_eligible(array $job): bool {
    return in_array((string)($job['type'] ?? ''), FAILED_RESEND_TYPES, true)
        && in_array((string)($job['status'] ?? ''), ['done', 'cancelled', 'failed'], true)
        && empty($job['recipients_masked']);
}

/** Whether ellsms_failed_resends exists (the migration may not be applied yet). */
function failed_resend_table_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $st = db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_failed_resends'");
            $has = (int)$st->fetchColumn() === 1;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * The account the new job is queued as: the actor itself, or — for a platform admin resending
 * someone else's job — the job's owner, re-checked like any send made on their behalf.
 *
 * @return array{ok: bool, user?: array, error?: string}
 */
function failed_resend_sender(array $job, array $actor): array {
    $isAdmin = ($actor['role'] ?? null) === 'admin';
    if (!$isAdmin || (int)$job['user_id'] === (int)($actor['id'] ?? 0)) {
        return ['ok' => true, 'user' => $actor];
    }
    $owner = backend_find_user_by_id((int)$job['user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        return ['ok' => false, 'error' => 'حساب صاحب این ارسال غیرفعال است یا دسترسی پنل ندارد.'];
    }
    $orgId = isset($job['organization_id']) && $job['organization_id'] !== null ? (int)$job['organization_id'] : null;
    if ($orgId !== null && $orgId > 0) {
        $orgStatus = organization_status($orgId);
        if ($orgStatus !== null && in_array($orgStatus, ['disabled', 'suspended'], true)) {
            return ['ok' => false, 'error' => 'سازمان صاحب این ارسال معلق یا غیرفعال است.'];
        }
        if (!organization_subscription_serviceable($orgId)) {
            return ['ok' => false, 'error' => 'اشتراک سازمان صاحب این ارسال فعال نیست.'];
        }
        if (!organization_has_entitlement($orgId, Entitlements::BULK_SEND)) {
            return ['ok' => false, 'error' => 'ارسال انبوه در پلن سازمان صاحب این ارسال نیست.'];
        }
    }
    return ['ok' => true, 'user' => [
        'id' => (int)$owner['id'],
        'role' => !empty($owner['is_admin']) ? 'admin' : 'user',
        'originator' => $owner['originator'] ?? '',
        'organization_id' => $orgId,
    ]];
}

/** Number of rows a resend would take right now. */
function failed_resend_count(int $jobId, bool $withDelivery): int {
    $st = db()->prepare('SELECT COUNT(*) FROM ellsms_bulk_items bi WHERE bi.job_id = ? AND ' . failed_resend_rows_sql($withDelivery));
    $st->execute([$jobId]);
    return (int)$st->fetchColumn();
}

/**
 * Queue the job's failed rows as a new p2p job.
 *
 * @return array{ok: bool, message: string, new_job_id: ?int}
 */
function failed_resend_queue(int $jobId, array $actor, bool $withDelivery): array {
    $fail = static fn(string $m): array => ['ok' => false, 'message' => $m, 'new_job_id' => null];

    $st = db()->prepare('SELECT * FROM ellsms_bulk_jobs WHERE id = ?');
    $st->execute([$jobId]);
    $job = $st->fetch();
    if (!$job) return $fail('ارسال پیدا نشد.');
    if (!failed_resend_job_eligible($job)) return $fail('ارسال مجدد ناموفق‌ها برای این ارسال ممکن نیست.');

    $sender = failed_resend_sender($job, $actor);
    if (!$sender['ok']) return $fail($sender['error']);
    $user = $sender['user'];

    $originator = (string)$job['originator'];
    if (!can_use_originator($user, $originator)) {
        return $fail('خط ارسال این Job (' . $originator . ') دیگر برای ارسال‌کننده مجاز یا فعال نیست.');
    }

    // Two clicks (or two tabs) must not queue the same failures twice.
    $lock = 'ellsms_failed_resend_' . $jobId;
    $got = db()->prepare('SELECT GET_LOCK(?, 0)');
    $got->execute([$lock]);
    if ((int)$got->fetchColumn() !== 1) {
        return $fail('ارسال مجدد همین Job در حال انجام است؛ چند لحظه بعد صفحه را تازه کنید.');
    }
    try {
        if (failed_resend_table_available()) {
            $recent = db()->prepare('SELECT new_job_id FROM ellsms_failed_resends WHERE source_job_id = ? AND created_at >= NOW() - INTERVAL 60 SECOND ORDER BY id DESC LIMIT 1');
            $recent->execute([$jobId]);
            $recentId = $recent->fetchColumn();
            if ($recentId !== false) {
                return $fail('ناموفق‌های این ارسال همین الان دوباره ارسال شدند (Job #' . to_persian_digits((string)(int)$recentId) . ').');
            }
        }

        $count = failed_resend_count($jobId, $withDelivery);
        if ($count === 0) return $fail('ردیف ناموفقی برای ارسال مجدد وجود ندارد.');
        if ($count > failed_resend_max_items()) {
            return $fail('تعداد ناموفق‌ها (' . to_persian_digits(number_format($count)) . ') از سقف ارسال مجدد ('
                . to_persian_digits(number_format(failed_resend_max_items())) . ') بیشتر است؛ آن‌ها را از گزارش خروجی بگیرید و با فایل ارسال کنید.');
        }

        $rows = db()->prepare('SELECT bi.mobile, bi.content FROM ellsms_bulk_items bi WHERE bi.job_id = ? AND '
            . failed_resend_rows_sql($withDelivery) . ' ORDER BY bi.id');
        $rows->execute([$jobId]);
        $items = [];
        foreach ($rows as $r) {
            $items[] = ['mobile' => (string)$r['mobile'], 'content' => (string)$r['content']];
        }

        $title = mb_substr('ارسال مجدد ناموفق‌های #' . $jobId . ' - ' . (string)($job['title'] ?? ''), 0, 160);
        [$ok, $info, $newJobId] = bulk_queue_job($user, 'p2p', $title, $originator, null, $items, null, null, null, (string)($job['message_class'] ?? '') ?: null);
        if (!$ok) return $fail($info);
        $newJobId = (int)$newJobId;

        if (failed_resend_table_available()) {
            try {
                db()->prepare('INSERT INTO ellsms_failed_resends (source_job_id, new_job_id, actor_user_id, owner_user_id, item_count) VALUES (?,?,?,?,?)')
                    ->execute([$jobId, $newJobId, (int)($actor['id'] ?? 0), (int)$user['id'], count($items)]);
            } catch (Throwable $t) {
                Logger::warning('bulk.failed_resend.log_failed', ['source_job_id' => $jobId, 'new_job_id' => $newJobId, 'exception' => $t]);
            }
        }
        audit((int)($actor['id'] ?? 0), 'bulk.failed_resend', "job #{$jobId} -> job #{$newJobId}: " . count($items) . ' rows'
            . ((int)$user['id'] !== (int)($actor['id'] ?? 0) ? ' (as user #' . (int)$user['id'] . ')' : ''));
        Logger::info('bulk.failed_resend', ['source_job_id' => $jobId, 'new_job_id' => $newJobId, 'rows' => count($items), 'actor_user_id' => $actor['id'] ?? null]);

        return ['ok' => true, 'message' => to_persian_digits((string)count($items)) . ' پیامک ناموفق به‌عنوان ارسال نظیر به نظیر جدید (Job #'
            . to_persian_digits((string)$newJobId) . ') در صف قرار گرفت.', 'new_job_id' => $newJobId];
    } finally {
        $rel = db()->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([$lock]);
    }
}

/**
 * Earlier resends of this job, newest first.
 *
 * @return list<array{new_job_id: int, item_count: int, actor_user_id: int, created_at: string}>
 */
function failed_resend_history(int $jobId, int $limit = 20): array {
    if (!failed_resend_table_available()) return [];
    $st = db()->prepare('SELECT new_job_id, item_count, actor_user_id, created_at FROM ellsms_failed_resends WHERE source_job_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $st->execute([$jobId]);
    return $st->fetchAll();
}

/** The job this one resends the failures of, or null. */
function failed_resend_source_job(int $newJobId): ?int {
    if (!failed_resend_table_available()) return null;
    $st = db()->prepare('SELECT source_job_id FROM ellsms_failed_resends WHERE new_job_id = ? LIMIT 1');
    $st->execute([$newJobId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}
