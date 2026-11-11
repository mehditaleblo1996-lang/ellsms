<?php
/**
 * ELLSMS — what happens when a large P2P file is ready to send (docs/p2p-auto-send.md).
 *
 * A P2P («ارسال نظیر به نظیر») file above SMS_SYNC_MAX_RECIPIENTS goes through the import pipeline
 * and stops at «منتظر تأیید» (ready_for_confirmation) until the user presses «ادامه و تأیید ارسال».
 * At that moment, exactly once per import:
 *
 *   - if a platform admin ticked «ارسال خودکار نظیر به نظیر» on the owner (public/users.php), the
 *     import is confirmed on the owner's behalf with the same import_confirm_job() the button uses,
 *     so it starts sending right away. If that confirmation fails (wallet, quota), the import stays
 *     «منتظر تأیید» and the support group is alerted with the reason;
 *   - otherwise the support group (the Telegram chat of telegram_chat_id, the same chat the
 *     «تماس با ما» tickets go to) is alerted that a P2P send is waiting for confirmation.
 *
 * Schema: db/migrations/2026_11_15_p2p_auto_send.sql. Before it is applied nobody has auto-send,
 * the option is not offered, and the alert still works.
 */

declare(strict_types=1);

/** Whether ellsms_meta has p2p_auto_send (2026_11_15_p2p_auto_send.sql). */
function p2p_auto_send_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            db()->query('SELECT p2p_auto_send FROM ellsms_meta LIMIT 0');
            $has = true;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/** Whether $userId's large P2P files start sending without confirmation. */
function p2p_auto_send_is(int $userId): bool {
    if (!p2p_auto_send_available() || $userId <= 0) return false;
    $st = db()->prepare('SELECT p2p_auto_send FROM ellsms_meta WHERE user_id = ?');
    $st->execute([$userId]);
    return (int)$st->fetchColumn() === 1;
}

/**
 * When and by whom auto-send was turned on, or null when it is off.
 *
 * @return array{set_at:?string,set_by:?int}|null
 */
function p2p_auto_send_details(int $userId): ?array {
    if (!p2p_auto_send_available()) return null;
    $st = db()->prepare('SELECT p2p_auto_send, p2p_auto_send_set_at, p2p_auto_send_set_by FROM ellsms_meta WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || (int)$row['p2p_auto_send'] !== 1) return null;
    return ['set_at' => $row['p2p_auto_send_set_at'] !== null ? (string)$row['p2p_auto_send_set_at'] : null,
            'set_by' => $row['p2p_auto_send_set_by'] !== null ? (int)$row['p2p_auto_send_set_by'] : null];
}

/**
 * Turns «ارسال خودکار نظیر به نظیر» on or off for $userId (an existing ellsms_meta row). Audited; a
 * no-op when nothing changes. Only imports that become ready after the change are affected.
 *
 * @return bool whether the flag changed
 */
function p2p_auto_send_set(int $userId, bool $on, int $byUserId): bool {
    if (!p2p_auto_send_available() || $userId <= 0) return false;
    $st = db()->prepare('UPDATE ellsms_meta SET p2p_auto_send = ?, p2p_auto_send_set_at = IF(? = 1, UTC_TIMESTAMP(), NULL),
                                p2p_auto_send_set_by = IF(? = 1, ?, NULL)
                         WHERE user_id = ? AND p2p_auto_send <> ?');
    $st->execute([(int)$on, (int)$on, (int)$on, $byUserId, $userId, (int)$on]);
    $changed = $st->rowCount() > 0;
    if ($changed) {
        audit($byUserId, $on ? 'user.p2p_auto_send_on' : 'user.p2p_auto_send_off', 'user_id=' . $userId);
    }
    return $changed;
}

/**
 * Pure: the support-group message for a P2P import waiting for confirmation. $failure is the reason
 * auto-send could not confirm it, or null when the user simply has no auto-send.
 *
 * @param array<string,mixed> $job an ellsms_import_jobs row
 */
function p2p_ready_alert_text(array $job, string $username, string $baseUrl, ?string $failure = null): string {
    $id = (int)($job['id'] ?? 0);
    $title = trim((string)($job['title'] ?? '')) ?: (string)($job['original_filename'] ?? '');
    $lines = [
        $failure === null ? '⏳ ارسال نظیر به نظیر منتظر تأیید است' : '⚠️ ارسال خودکار نظیر به نظیر انجام نشد — منتظر تأیید',
        'کاربر: ' . ($username !== '' ? $username : '#' . (int)($job['user_id'] ?? 0)),
        'عنوان: ' . ($title !== '' ? $title : 'بدون عنوان'),
        'خط ارسال: ' . (string)($job['originator'] ?? ''),
        'تعداد پیام: ' . number_format((int)($job['queued_rows'] ?? 0)),
        'هزینه‌ی تخمینی: ' . number_format((int)($job['estimated_cost_credits'] ?? 0)) . ' ریال',
    ];
    if ($failure !== null) {
        $lines[] = 'علت: ' . $failure;
    }
    $lines[] = 'واردسازی #' . $id . ($baseUrl !== '' ? ': ' . $baseUrl . '/import.php?id=' . $id : '');
    return implode("\n", $lines);
}

/** Sends $text to the support group; a failure is logged, never thrown into the import worker. */
function p2p_support_alert(int $jobId, string $text): void {
    try {
        [$ok, $info] = telegram_send_message($text);
        if ($ok) {
            Logger::info('p2p.ready.alert_sent', ['import_job_id' => $jobId]);
        } else {
            Logger::warning('p2p.ready.alert_failed', ['import_job_id' => $jobId, 'detail' => $info]);
        }
    } catch (Throwable $t) {
        Logger::warning('p2p.ready.alert_failed', ['import_job_id' => $jobId, 'exception' => $t]);
    }
}

/**
 * Called once, by the import worker, right after import $jobId became ready_for_confirmation.
 * Does nothing for imports that are not P2P.
 */
function p2p_import_ready(int $jobId): void {
    $job = import_load_job($jobId, null);
    if ($job === null || (string)($job['source_type'] ?? '') !== 'p2p' || (string)$job['status'] !== 'ready_for_confirmation') {
        return;
    }
    $userId = (int)$job['user_id'];
    $failure = null;

    if (p2p_auto_send_is($userId)) {
        // The owner's own confirmation, exactly what «ادامه و تأیید ارسال» does. Import jobs are never
        // admin-exempt (import_job_reserve_and_stage), so the owner is confirmed as a regular user.
        $owner = ['id' => $userId, 'organization_id' => $job['organization_id'] !== null ? (int)$job['organization_id'] : null, 'role' => 'user'];
        $result = import_confirm_job($jobId, $owner);
        if ($result['ok']) {
            audit($userId, 'p2p.auto_confirmed', 'import_job=' . $jobId . ' bulk_job=' . (int)$result['bulk_job_id']);
            Logger::info('p2p.auto_confirmed', ['import_job_id' => $jobId, 'bulk_job_id' => (int)$result['bulk_job_id']]);
            return;
        }
        $failure = (string)$result['error'];
        Logger::warning('p2p.auto_confirm_failed', ['import_job_id' => $jobId, 'error' => $failure]);
    }

    $username = backend_usernames_by_ids([$userId])[$userId] ?? '';
    p2p_support_alert($jobId, p2p_ready_alert_text($job, (string)$username, app_url(), $failure));
}
