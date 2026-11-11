<?php
/**
 * ELLSMS — billing check of a finished bulk send («مغایرت مالی ارسال گروهی», docs/financial-log.md).
 *
 * The financial log shows a bulk send as ONE row: the sum of its per-message charges. The per-message
 * ledger rows stay (delivery refunds and crash-replay protection are keyed on them), so the summed row
 * must never hide a fault. When a job finishes or is cancelled, bulk_billing_check_job() compares:
 *
 *   - messages sent (ellsms_bulk_items.status = 'sent') with messages charged (sms_debit ledger rows);
 *   - the amount charged with the prices frozen on the sent messages (price_cost_credits);
 *   - the job's reservation with charged + released.
 *
 * A job that does not add up is recorded once in ellsms_bulk_billing_issues
 * (db/migrations/2026_11_17_bulk_billing_issues.sql), logged (bulk.billing.mismatch), notified to the
 * platform admins in the panel (event billing.bulk_mismatch) and posted to the support Telegram group.
 * A job without a reservation (an admin's own send, a free send) is not charged and is not checked.
 */

declare(strict_types=1);

function bulk_billing_issues_available(): bool {
    static $has = null;
    if ($has === null) {
        try {
            db()->query('SELECT 1 FROM ellsms_bulk_billing_issues LIMIT 0');
            $has = true;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * Pure: what is wrong with a job's figures, in Persian, or null when they add up.
 *
 * @param array{sent:int, unpriced:int, expected:int, charged_count:int, charged:int, reserved:int, released:int, reservation_active:bool} $f
 */
function bulk_billing_problem(array $f): ?string {
    $problems = [];
    if ($f['charged_count'] !== $f['sent']) {
        $problems[] = to_persian_digits(number_format($f['sent'])) . ' پیامک ارسال شده ولی برای '
            . to_persian_digits(number_format($f['charged_count'])) . ' پیامک هزینه کسر شده است';
    }
    if ($f['unpriced'] === 0 && $f['charged'] !== $f['expected']) {
        $problems[] = 'مبلغ کسرشده ' . to_persian_digits(number_format($f['charged'])) . ' ریال است ولی قیمت پیامک‌های ارسال‌شده '
            . to_persian_digits(number_format($f['expected'])) . ' ریال';
    }
    if ($f['reservation_active']) {
        $problems[] = 'رزرو این ارسال بعد از پایان آزاد نشده است';
    } elseif ($f['reserved'] !== $f['charged'] + $f['released']) {
        $problems[] = 'رزرو ' . to_persian_digits(number_format($f['reserved'])) . ' ریال با کسر و برگشت ('
            . to_persian_digits(number_format($f['charged'] + $f['released'])) . ' ریال) برابر نیست';
    }
    return $problems ? implode('؛ ', $problems) : null;
}

/**
 * Checks one finished or cancelled bulk job; records and announces a mismatch once.
 * Never throws: a failing check is logged and must not stop the worker that called it.
 *
 * @return ?string the problem, or null when the job adds up (or is not charged at all)
 */
function bulk_billing_check_job(int $jobId): ?string {
    try {
        $db = db();
        $res = $db->prepare("SELECT user_id, amount, status FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?");
        $res->execute([(string)$jobId]);
        $reservation = $res->fetch();
        if (!$reservation) {
            return null;
        }
        $items = $db->prepare("SELECT COUNT(*) sent, COALESCE(SUM(price_cost_credits), 0) expected, COALESCE(SUM(price_cost_credits IS NULL), 0) unpriced
                               FROM ellsms_bulk_items WHERE job_id = ? AND status = 'sent'");
        $items->execute([$jobId]);
        $i = $items->fetch();
        $ledger = $db->prepare("SELECT type, COUNT(*) n, COALESCE(SUM(amount), 0) total FROM ellsms_wallet_transactions
                                WHERE reference_type = 'bulk_job' AND reference_id = ? AND type IN ('sms_debit', 'reservation_release')
                                GROUP BY type");
        $ledger->execute([(string)$jobId]);
        $byType = [];
        foreach ($ledger->fetchAll() as $l) {
            $byType[(string)$l['type']] = $l;
        }
        $figures = [
            'sent'               => (int)$i['sent'],
            'unpriced'           => (int)$i['unpriced'],
            'expected'           => (int)$i['expected'],
            'charged_count'      => (int)($byType['sms_debit']['n'] ?? 0),
            'charged'            => -(int)($byType['sms_debit']['total'] ?? 0),
            'reserved'           => (int)$reservation['amount'],
            'released'           => (int)($byType['reservation_release']['total'] ?? 0),
            'reservation_active' => (string)$reservation['status'] === 'active',
        ];
        $problem = bulk_billing_problem($figures);
        if ($problem === null) {
            return null;
        }
        bulk_billing_record_issue($jobId, (int)$reservation['user_id'], $problem, $figures);
        return $problem;
    } catch (Throwable $t) {
        Logger::error('bulk.billing.check_failed', ['job_id' => $jobId, 'exception' => $t]);
        return null;
    }
}

/** Stores the issue (once per job) and tells the admins and the support group the first time. */
function bulk_billing_record_issue(int $jobId, int $userId, string $problem, array $figures): void {
    Logger::error('bulk.billing.mismatch', ['job_id' => $jobId, 'user_id' => $userId, 'problem' => $problem] + $figures);
    Metrics::increment('bulk.billing.mismatch', 1);
    $isNew = true;
    if (bulk_billing_issues_available()) {
        $st = db()->prepare('INSERT IGNORE INTO ellsms_bulk_billing_issues (job_id, user_id, details, sent_count, charged_count, expected_amount, charged_amount)
                             VALUES (?,?,?,?,?,?,?)');
        $st->execute([$jobId, $userId, mb_substr($problem, 0, 1000), $figures['sent'], $figures['charged_count'], $figures['expected'], $figures['charged']]);
        $isNew = $st->rowCount() > 0;
    }
    if (!$isNew) {
        return;
    }
    $username = backend_usernames_by_ids([$userId])[$userId] ?? ('#' . $userId);
    $title = 'مغایرت مالی ارسال گروهی #' . $jobId;
    $body = 'کاربر: ' . $username . "\n" . $problem;
    $url = '/messages/bulk-jobs?id=' . $jobId;
    notification_dispatch_admins('billing.bulk_mismatch', $title, $body, $url, 'error');
    // The support group too, unless the notification settings already send this event to Telegram.
    if (!notification_channel_enabled('billing.bulk_mismatch', 'telegram')) {
        try {
            [$ok, $info] = telegram_send_message('⚠ ' . $title . "\n" . $body . "\n" . rtrim(app_url(), '/') . $url);
            if (!$ok) {
                Logger::warning('bulk.billing.alert_failed', ['job_id' => $jobId, 'detail' => $info]);
            }
        } catch (Throwable $t) {
            Logger::warning('bulk.billing.alert_failed', ['job_id' => $jobId, 'exception' => $t]);
        }
    }
}

/** @return array<int, string> job id => recorded problem, for the given jobs */
function bulk_billing_issues_for_jobs(array $jobIds): array {
    $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds))));
    if ($jobIds === [] || !bulk_billing_issues_available()) {
        return [];
    }
    $st = db()->prepare('SELECT job_id, details FROM ellsms_bulk_billing_issues WHERE job_id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')');
    $st->execute($jobIds);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['job_id']] = (string)$r['details'];
    }
    return $out;
}
