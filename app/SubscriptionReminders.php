<?php
/**
 * #41 — remind an organization before its subscription, trial or grace period ends.
 *
 * Mirrors Vesal's UserExpirationDateNotificationJob. Reminders at SUBSCRIPTION_REMINDER_DAYS (default
 * "7,3,1") days before the end moment of the organization's effective subscription:
 *   active   → current_period_end   (skipped when cancel_at_period_end = 1: they chose to stop)
 *   trialing → trial_ends_at
 *   past_due / grace → grace_ends_at
 * Only the most urgent reminder that is due is sent — a worker that was down for a week sends the
 * 1-day reminder, not 7-, 3- and 1-day ones at once. Each reminder is claimed in
 * ellsms_subscription_reminders (UNIQUE subscription/end/offset) before sending, so it goes out once
 * per period even with concurrent runs; a renewal moves the end moment and re-arms them.
 *
 * Recipients: the organization's alert mobile/email (Profile → notifications) or else the owner's; the
 * owner also gets a panel notification. Text: setting `subscription_reminder_template`.
 */

declare(strict_types=1);

const SUBSCRIPTION_REMINDER_DEFAULT_TEMPLATE =
    '{name} گرامی، {what} «{plan}» شما در تاریخ {date} ({days} روز دیگر) به پایان می‌رسد. برای جلوگیری از توقف سرویس، آن را تمدید کنید.';

/** @return list<int> reminder offsets in days, largest first */
function subscription_reminder_offsets(): array {
    $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)env('SUBSCRIPTION_REMINDER_DAYS', '7,3,1'))), static fn(int $d): bool => $d > 0 && $d <= 90)));
    rsort($days);
    return $days;
}

function subscription_reminder_check_seconds(): int {
    return max(0, (int)env('SUBSCRIPTION_REMINDER_CHECK_SECONDS', '3600'));
}

/** The end moment a reminder is about, or null when this subscription gets no reminder. */
function subscription_reminder_target(array $sub): ?array {
    return match ((string)$sub['status']) {
        'active'            => (int)$sub['cancel_at_period_end'] === 1 || empty($sub['current_period_end']) ? null : ['kind' => 'period', 'ends_at' => (string)$sub['current_period_end']],
        'trialing'          => empty($sub['trial_ends_at']) ? null : ['kind' => 'trial', 'ends_at' => (string)$sub['trial_ends_at']],
        'past_due', 'grace' => empty($sub['grace_ends_at']) ? null : ['kind' => 'grace', 'ends_at' => (string)$sub['grace_ends_at']],
        default             => null,
    };
}

/** The tightest offset whose window we are in (days left <= offset), or null when none is due yet. */
function subscription_reminder_due_offset(int $secondsLeft, array $offsets): ?int {
    if ($secondsLeft <= 0) return null;
    $daysLeft = (int)ceil($secondsLeft / 86400);
    $due = array_values(array_filter($offsets, static fn(int $o): bool => $daysLeft <= $o));
    return $due === [] ? null : min($due);
}

/**
 * One pass. $sendSms / $sendEmail default to the notification center's senders; tests pass their own.
 *
 * @return array{checked:int, sent:int, would_send:list<int>}
 */
function subscription_reminders_run(bool $dryRun = false, ?callable $sendSms = null, ?callable $sendEmail = null, ?int $now = null): array {
    $summary = ['checked' => 0, 'sent' => 0, 'would_send' => []];
    if (!billing_enabled()) return $summary;
    $sendSms ??= 'notification_send_sms';
    $sendEmail ??= 'notification_send_email';
    $db = db();
    $now ??= (int)$db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $offsets = subscription_reminder_offsets();
    if ($offsets === []) return $summary;
    $horizon = max($offsets);

    $st = $db->prepare(
        "SELECT s.id, s.organization_id, s.status, s.current_period_end, s.trial_ends_at, s.grace_ends_at, s.cancel_at_period_end,
                p.name AS plan_name, o.name AS organization_name,
                np.alert_mobile, np.alert_email
         FROM ellsms_subscriptions s
         JOIN ellsms_plans p ON p.id = s.plan_id
         JOIN ellsms_organizations o ON o.id = s.organization_id AND o.status = 'active'
         LEFT JOIN ellsms_organization_notification_preferences np ON np.organization_id = s.organization_id
         WHERE s.status IN ('active','trialing','past_due','grace')
           AND COALESCE(CASE s.status WHEN 'active' THEN s.current_period_end WHEN 'trialing' THEN s.trial_ends_at ELSE s.grace_ends_at END, '1970-01-02')
               BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)"
    );
    $st->execute([$now, $now + $horizon * 86400]);

    foreach ($st->fetchAll() as $sub) {
        $summary['checked']++;
        $target = subscription_reminder_target($sub);
        if ($target === null) continue;
        $endsTs = (int)$db->query('SELECT UNIX_TIMESTAMP(' . $db->quote($target['ends_at']) . ')')->fetchColumn();
        $offset = subscription_reminder_due_offset($endsTs - $now, $offsets);
        if ($offset === null) continue;

        if ($dryRun) {
            $summary['would_send'][] = (int)$sub['organization_id'];
            continue;
        }
        $claim = $db->prepare('INSERT INTO ellsms_subscription_reminders (subscription_id, organization_id, kind, ends_at, offset_days)
                               VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id = id');
        $claim->execute([(int)$sub['id'], (int)$sub['organization_id'], $target['kind'], $target['ends_at'], $offset]);
        if ($claim->rowCount() !== 1) continue;
        $reminderId = (int)$db->lastInsertId();

        $owner = low_credit_alert_owner($db, (int)$sub['organization_id']);
        $personal = $owner !== null ? trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? '')) : '';
        $template = trim((string)setting_fresh('subscription_reminder_template', ''));
        $text = strtr($template !== '' ? $template : SUBSCRIPTION_REMINDER_DEFAULT_TEMPLATE, [
            '{name}' => $personal !== '' ? $personal : ((string)$sub['organization_name'] ?: 'مشترک'),
            '{what}' => ['period' => 'اشتراک', 'trial' => 'دوره‌ی آزمایشی', 'grace' => 'مهلت پرداخت اشتراک'][$target['kind']],
            '{plan}' => (string)$sub['plan_name'],
            '{date}' => jdate($target['ends_at'], false),
            '{days}' => to_persian_digits((string)max(1, (int)ceil(($endsTs - $now) / 86400))),
        ]);

        $smsOk = false;
        $emailOk = false;
        try {
            $mobile = trim((string)($sub['alert_mobile'] ?? '')) !== '' ? (string)$sub['alert_mobile'] : (string)($owner['mobile'] ?? '');
            if ($mobile !== '') $smsOk = (bool)$sendSms($mobile, $text);
            $email = trim((string)($sub['alert_email'] ?? '')) !== '' ? (string)$sub['alert_email'] : (string)($owner['email'] ?? '');
            if ($email !== '') $emailOk = (bool)$sendEmail($email, 'یادآوری تمدید اشتراک', $text);
            if ($owner !== null) {
                notification_insert_panel((int)$owner['id'], (int)$sub['organization_id'], 'subscription.expiring', 'یادآوری تمدید اشتراک', $text, '/billing.php', 'warning');
            }
        } catch (Throwable $t) {
            Logger::error('subscription_reminder.send_failed', ['subscription_id' => (int)$sub['id'], 'exception' => $t]);
        }
        $db->prepare('UPDATE ellsms_subscription_reminders SET sms_sent = ?, email_sent = ? WHERE id = ?')->execute([(int)$smsOk, (int)$emailOk, $reminderId]);
        $summary['sent']++;
        Metrics::increment('subscription_reminder.sent', 1, ['kind' => $target['kind']]);
        Logger::info('subscription_reminder.sent', ['subscription_id' => (int)$sub['id'], 'kind' => $target['kind'], 'offset_days' => $offset, 'sms' => $smsOk, 'email' => $emailOk]);
    }
    return $summary;
}
