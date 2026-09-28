<?php
/**
 * #35 — low-credit alerts.
 *
 * An organization can ask (Profile → اعلان‌ها, ellsms_organization_notification_preferences) to be
 * told when its credit drops below a threshold, by SMS and/or email. Until now that choice was
 * stored and shown but nothing ever sent the alert. This pass sends it.
 *
 * Rules (same shape as Vesal's LowCreditNotificationJob):
 *  - balance = the organization's wallet accounts' available_balance (what it can still spend);
 *  - at most one alert per LOW_CREDIT_ALERT_PERIOD_HOURS (default 24) and at most
 *    LOW_CREDIT_ALERT_MAX_COUNT (default 3) alerts while the balance stays low;
 *  - once the balance is back at or above the threshold both counters reset, so the next drop
 *    alerts again.
 *
 * The claim is one conditional UPDATE, so two workers (or a worker and a manual run) can never both
 * send the same alert.
 */

declare(strict_types=1);

function low_credit_alert_period_hours(): int {
    return max(1, (int)env('LOW_CREDIT_ALERT_PERIOD_HOURS', '24'));
}

function low_credit_alert_max_count(): int {
    return max(1, (int)env('LOW_CREDIT_ALERT_MAX_COUNT', '3'));
}

/** Seconds between two passes inside the long-running worker; 0 turns the pass off there. */
function low_credit_alert_check_seconds(): int {
    return max(0, (int)env('LOW_CREDIT_ALERT_CHECK_SECONDS', '300'));
}

const LOW_CREDIT_ALERT_DEFAULT_TEMPLATE =
    '{name} گرامی، اعتبار حساب شما به {credit} رسیده و کمتر از حد هشدار ({threshold}) است. برای جلوگیری از توقف ارسال، حساب خود را شارژ کنید.';

function low_credit_alert_text(string $name, int $credit, int $threshold): string {
    $template = trim((string)setting('low_credit_alert_template', ''));
    if ($template === '') {
        $template = LOW_CREDIT_ALERT_DEFAULT_TEMPLATE;
    }
    return strtr($template, [
        '{name}'      => $name,
        '{credit}'    => to_persian_digits(number_format($credit)),
        '{threshold}' => to_persian_digits(number_format($threshold)),
    ]);
}

/** Organizations with the alert switched on, with their current spendable balance. */
function low_credit_alert_candidates(PDO $db): array {
    return $db->query(
        "SELECT p.organization_id, p.low_credit_threshold, p.sms_alert_enabled, p.email_alert_enabled,
                p.alert_mobile, p.alert_email, p.low_credit_alert_count, p.last_low_credit_alert_at,
                o.name AS organization_name,
                COALESCE((SELECT SUM(w.available_balance) FROM ellsms_wallet_accounts w
                          WHERE w.organization_id = p.organization_id), 0) AS balance
         FROM ellsms_organization_notification_preferences p
         JOIN ellsms_organizations o ON o.id = p.organization_id AND o.status = 'active'
         WHERE p.low_credit_alert_enabled = 1 AND p.low_credit_threshold > 0
         ORDER BY p.organization_id"
    )->fetchAll();
}

function low_credit_alert_owner(PDO $db, int $organizationId): ?array {
    $st = $db->prepare(
        "SELECT user_id FROM ellsms_organization_memberships
         WHERE organization_id = ? AND role = 'owner' AND status = 'active' ORDER BY id LIMIT 1"
    );
    $st->execute([$organizationId]);
    $userId = $st->fetchColumn();
    if ($userId === false) {
        return null;
    }
    return backend_find_user_by_id((int)$userId);
}

/**
 * Atomically takes the right to send one alert now. False when another process just sent one, the
 * period has not passed yet, or the cap is reached.
 */
function low_credit_alert_claim(PDO $db, int $organizationId): bool {
    $st = $db->prepare(
        'UPDATE ellsms_organization_notification_preferences
         SET last_low_credit_alert_at = CURRENT_TIMESTAMP, low_credit_alert_count = low_credit_alert_count + 1
         WHERE organization_id = ? AND low_credit_alert_enabled = 1
           AND low_credit_alert_count < ?
           AND (last_low_credit_alert_at IS NULL OR last_low_credit_alert_at <= CURRENT_TIMESTAMP - INTERVAL ? HOUR)'
    );
    $st->execute([$organizationId, low_credit_alert_max_count(), low_credit_alert_period_hours()]);
    return $st->rowCount() === 1;
}

/**
 * One pass. $sendSms / $sendEmail default to the notification center's senders; tests pass their own.
 *
 * @return array{checked:int, low:int, sent:int, reset:int, would_send:list<int>}
 */
function low_credit_alerts_run(bool $dryRun = false, ?callable $sendSms = null, ?callable $sendEmail = null): array {
    $db = db();
    $sendSms ??= 'notification_send_sms';
    $sendEmail ??= 'notification_send_email';
    $summary = ['checked' => 0, 'low' => 0, 'sent' => 0, 'reset' => 0, 'would_send' => []];

    foreach (low_credit_alert_candidates($db) as $row) {
        $summary['checked']++;
        $organizationId = (int)$row['organization_id'];
        $threshold = (int)$row['low_credit_threshold'];
        $balance = (int)$row['balance'];

        if ($balance >= $threshold) {
            // Topped up (or never low): re-arm the alert for the next drop.
            if (!$dryRun && ((int)$row['low_credit_alert_count'] > 0 || $row['last_low_credit_alert_at'] !== null)) {
                $db->prepare('UPDATE ellsms_organization_notification_preferences
                              SET low_credit_alert_count = 0, last_low_credit_alert_at = NULL WHERE organization_id = ?')
                   ->execute([$organizationId]);
                $summary['reset']++;
            }
            continue;
        }
        $summary['low']++;

        if ($dryRun) {
            $lastAt = $row['last_low_credit_alert_at'] !== null ? strtotime((string)$row['last_low_credit_alert_at']) : false;
            $due = (int)$row['low_credit_alert_count'] < low_credit_alert_max_count()
                && ($lastAt === false || $lastAt <= time() - low_credit_alert_period_hours() * 3600);
            if ($due) {
                $summary['would_send'][] = $organizationId;
            }
            continue;
        }

        if (!low_credit_alert_claim($db, $organizationId)) {
            continue;
        }

        $owner = low_credit_alert_owner($db, $organizationId);
        $name = trim((string)($row['organization_name'] ?? ''));
        if ($owner !== null) {
            $personal = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
            $name = $personal !== '' ? $personal : $name;
        }
        $text = low_credit_alert_text($name !== '' ? $name : 'مشترک', $balance, $threshold);
        $title = 'اعتبار کم';

        $smsOk = null;
        $emailOk = null;
        try {
            if ((int)$row['sms_alert_enabled'] === 1) {
                $mobile = trim((string)$row['alert_mobile']) !== '' ? (string)$row['alert_mobile'] : (string)($owner['mobile'] ?? '');
                $smsOk = $mobile !== '' && (bool)$sendSms($mobile, $text);
            }
            if ((int)$row['email_alert_enabled'] === 1) {
                $email = trim((string)$row['alert_email']) !== '' ? (string)$row['alert_email'] : (string)($owner['email'] ?? '');
                $emailOk = $email !== '' && (bool)$sendEmail($email, $title, $text);
            }
            if ($owner !== null) {
                notification_insert_panel((int)$owner['id'], $organizationId, 'credit.low', $title, $text, '/buy-credit.php', 'warning');
            }
            if (setting('low_credit_alert_notify_admins', '0') === '1') {
                notification_dispatch_admins('credit.low', $title, ($row['organization_name'] ?? '') . ' — ' . $text, '', 'warning');
            }
        } catch (Throwable $e) {
            Logger::error('low_credit_alert.send_failed', ['organization_id' => $organizationId, 'exception' => $e]);
        }

        $summary['sent']++;
        Metrics::increment('low_credit_alert.sent');
        Logger::info('low_credit_alert.sent', [
            'organization_id' => $organizationId, 'balance' => $balance, 'threshold' => $threshold,
            'sms' => $smsOk, 'email' => $emailOk,
        ]);
        audit($owner !== null ? (int)$owner['id'] : 0, 'credit.low_alert_sent',
            "org=#{$organizationId} balance={$balance} threshold={$threshold} sms=" . var_export($smsOk, true) . ' email=' . var_export($emailOk, true));
    }

    return $summary;
}
