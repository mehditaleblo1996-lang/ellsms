<?php
/** Phase 7 — centralized notification center (panel + optional SMS/email/Telegram). */
declare(strict_types=1);

const NOTIFICATION_EVENTS = [
    'registration.new'              => 'ثبت‌نام جدید',
    'registration.approved'         => 'تأیید ثبت‌نام',
    'registration.rejected'         => 'رد ثبت‌نام',
    'registration.account_created'  => 'فعال‌سازی حساب',
    'kyc.submitted'                 => 'ارسال احراز هویت',
    'kyc.approved'                  => 'تأیید احراز هویت',
    'kyc.rejected'                  => 'رد احراز هویت',
    'kyc.needs_correction'          => 'اصلاح احراز هویت',
    'payment.success'               => 'پرداخت موفق',
    'payment.failed'                => 'پرداخت ناموفق',
    'credit.low'                    => 'اعتبار کم',
    'bulk.completed'                => 'پایان ارسال انبوه',
    'bulk.failed'                   => 'خطای ارسال انبوه',
    'import.started'                => 'شروع واردسازی',
    'import.ready'                  => 'آماده‌شدن واردسازی',
    'import.failed'                 => 'خطای واردسازی',
    'gateway.failed'                => 'خطای درگاه پیامک',
];

const NOTIFICATION_CHANNELS = ['panel', 'sms', 'email', 'telegram'];

function notification_event_label(string $event): string {
    return NOTIFICATION_EVENTS[$event] ?? $event;
}

function notification_channel_setting_key(string $event, string $channel): string {
    return 'notification.' . $event . '.' . $channel;
}

function notification_channel_default(string $event, string $channel): bool {
    if ($channel === 'panel') return true;
    if ($channel === 'sms') {
        return in_array($event, [
            'registration.new', 'registration.approved', 'registration.rejected',
            'registration.account_created', 'kyc.approved', 'kyc.rejected', 'kyc.needs_correction',
        ], true);
    }
    return false;
}

function notification_channel_enabled(string $event, string $channel): bool {
    if (!isset(NOTIFICATION_EVENTS[$event]) || !in_array($channel, NOTIFICATION_CHANNELS, true)) return false;
    $default = notification_channel_default($event, $channel) ? '1' : '0';
    return setting(notification_channel_setting_key($event, $channel), $default) === '1';
}

function notification_set_channel(string $event, string $channel, bool $enabled, int $actorUserId): void {
    if (!isset(NOTIFICATION_EVENTS[$event]) || !in_array($channel, NOTIFICATION_CHANNELS, true)) return;
    set_setting(notification_channel_setting_key($event, $channel), $enabled ? '1' : '0');
    audit($actorUserId, 'notifications.channel_configured', "event={$event} channel={$channel} enabled=" . ($enabled ? '1' : '0'));
}

function notification_insert_panel(int $userId, ?int $organizationId, string $event, string $title, string $body, string $actionUrl = '', string $severity = 'info'): void {
    if ($userId <= 0 || !notification_channel_enabled($event, 'panel')) return;
    $severity = in_array($severity, ['info','success','warning','error'], true) ? $severity : 'info';
    db()->prepare(
        'INSERT INTO ellsms_notifications (user_id,organization_id,event_key,title,body,action_url,severity) VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $userId,
        $organizationId && $organizationId > 0 ? $organizationId : null,
        $event,
        mb_substr($title, 0, 190, 'UTF-8'),
        mb_substr($body, 0, 1000, 'UTF-8'),
        mb_substr($actionUrl, 0, 500, 'UTF-8'),
        $severity,
    ]);
}

function notification_user_contact(int $userId): ?array {
    if ($userId <= 0 || !function_exists('backend_find_user_by_id')) return null;
    $user = backend_find_user_by_id($userId);
    if (!$user || empty($user['active']) || !empty($user['deleted'])) return null;
    return $user;
}

function notification_send_sms(string $mobile, string $text): bool {
    $mobile = normalize_msisdn($mobile) ?? '';
    if ($mobile === '') return false;
    $originator = normalize_originator((string)setting('default_originator', '')) ?? '';
    if ($originator === '') return false;
    require_once __DIR__ . '/backend.php';
    $senderUserId = max(1, (int)setting('registration_sms_sender_user_id', '1'));
    [$ok, , $rows] = backend_api_send($senderUserId, $originator, [$mobile], $text);
    if (!$ok || !is_array($rows)) return false;
    foreach ($rows as $row) if (is_array($row) && (($row['status'] ?? '') === 'sent')) return true;
    return false;
}

function notification_send_email(string $email, string $title, string $body): bool {
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;
    $from = trim((string)setting('notification_email_from', ''));
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) $headers[] = 'From: ELLSMS <' . $from . '>';
    return @mail($email, '=?UTF-8?B?' . base64_encode($title) . '?=', $body, implode("\r\n", $headers));
}

function notification_send_telegram(string $title, string $body): bool {
    $token = trim((string)setting('telegram_bot_token', ''));
    $chatId = trim((string)setting('telegram_chat_id', ''));
    if ($token === '' || $chatId === '' || !function_exists('curl_init')) return false;
    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $title . "\n" . $body]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 2500,
    ]);
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $result !== false && $code >= 200 && $code < 300;
}

function notification_dispatch_user(int $userId, ?int $organizationId, string $event, string $title, string $body, string $actionUrl = '', string $severity = 'info'): void {
    if (!isset(NOTIFICATION_EVENTS[$event])) return;
    try {
        notification_insert_panel($userId, $organizationId, $event, $title, $body, $actionUrl, $severity);
        $contact = notification_user_contact($userId);
        if ($contact) {
            if (notification_channel_enabled($event, 'sms')) notification_send_sms((string)($contact['mobile'] ?? ''), $title . "\n" . $body);
            if (notification_channel_enabled($event, 'email')) notification_send_email((string)($contact['email'] ?? ''), $title, $body);
        }
        if (notification_channel_enabled($event, 'telegram')) notification_send_telegram($title, $body);
    } catch (Throwable $e) {
        Logger::error('notifications.dispatch_failed', ['event' => $event, 'user_id' => $userId, 'exception' => $e]);
    }
}

/** Notify every active platform admin; panel is one row per admin, Telegram only once. */
function notification_dispatch_admins(string $event, string $title, string $body, string $actionUrl = '', string $severity = 'info'): void {
    if (!isset(NOTIFICATION_EVENTS[$event])) return;
    try {
        $ids = array_map('intval', db()->query('SELECT user_id FROM ellsms_meta WHERE panel_access=1 AND is_admin=1 ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
        $telegramSent = false;
        foreach ($ids as $id) {
            $contact = notification_user_contact($id);
            if (!$contact) continue;
            notification_insert_panel($id, null, $event, $title, $body, $actionUrl, $severity);
            if (notification_channel_enabled($event, 'sms')) notification_send_sms((string)($contact['mobile'] ?? ''), $title . "\n" . $body);
            if (notification_channel_enabled($event, 'email')) notification_send_email((string)($contact['email'] ?? ''), $title, $body);
            if (!$telegramSent && notification_channel_enabled($event, 'telegram')) {
                $telegramSent = notification_send_telegram($title, $body);
            }
        }
    } catch (Throwable $e) {
        Logger::error('notifications.admin_dispatch_failed', ['event' => $event, 'exception' => $e]);
    }
}

function notification_unread_count(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM ellsms_notifications WHERE user_id=? AND read_at IS NULL');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable) { return 0; }
}

function notification_list(int $userId, int $beforeId = 0, int $limit = 51): array {
    $limit = max(1, min(101, $limit));
    $sql = 'SELECT id,event_key,title,body,action_url,severity,read_at,created_at FROM ellsms_notifications WHERE user_id=?';
    $params = [$userId];
    if ($beforeId > 0) { $sql .= ' AND id < ?'; $params[] = $beforeId; }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}

function notification_mark_read(int $userId, int $notificationId): void {
    db()->prepare('UPDATE ellsms_notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$notificationId, $userId]);
}

function notification_mark_all_read(int $userId): void {
    db()->prepare('UPDATE ellsms_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$userId]);
}
