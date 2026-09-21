<?php
/**
 * ELLSMS — Telegram Bot API relay for the "تماس با ما" contact form.
 *
 * A visitor's ticket is forwarded as a plain chat message to a Telegram
 * bot/chat the admin configures — there's no ticket table, Telegram
 * itself is the inbox. Uses the bot's own sendMessage endpoint
 * (core.telegram.org/bots/api#sendmessage).
 */

require_once __DIR__ . '/bootstrap.php';

function telegram_bot_token(): string {
    return (string)(setting('telegram_bot_token', env('TELEGRAM_BOT_TOKEN', '')) ?? '');
}

function telegram_chat_id(): string {
    return (string)(setting('telegram_chat_id', env('TELEGRAM_CHAT_ID', '')) ?? '');
}

function telegram_configured(): bool {
    return telegram_bot_token() !== '' && telegram_chat_id() !== '';
}

/** Relay a plain-text message to the configured contact-form chat. Returns [ok, message]. */
function telegram_send_message(string $text): array {
    if (telegram_chat_id() === '') {
        return [false, 'ربات تلگرام هنوز تنظیم نشده — از مدیر بخواهید Token و Chat ID را در تنظیمات وارد کند.'];
    }
    return telegram_send_message_to(telegram_chat_id(), $text);
}

/** Same as telegram_send_message() but to an arbitrary chat (e.g. whichever chat an incoming bot command came from). */
function telegram_send_message_to(string $chatId, string $text): array {
    $token = telegram_bot_token();
    if ($token === '' || $chatId === '') {
        return [false, 'ربات تلگرام هنوز تنظیم نشده — از مدیر بخواهید Token و Chat ID را در تنظیمات وارد کند.'];
    }

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $chatId, 'text' => $text]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [false, 'اتصال به تلگرام برقرار نشد: ' . $err];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        $desc = is_array($decoded) ? ($decoded['description'] ?? 'خطای نامشخص') : 'پاسخ نامعتبر از تلگرام';
        return [false, 'تلگرام درخواست را رد کرد: ' . $desc];
    }
    return [true, 'ok'];
}

/**
 * Sends a binary file (e.g. a generated PDF) to a chat via Telegram's
 * multipart sendDocument endpoint. Returns [ok, message].
 */
function telegram_send_document_to(string $chatId, string $filename, string $binaryContent, string $caption = ''): array {
    $token = telegram_bot_token();
    if ($token === '' || $chatId === '') {
        return [false, 'ربات تلگرام هنوز تنظیم نشده.'];
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'ellsms_tg_');
    if ($tmpFile === false) {
        return [false, 'ساخت فایل موقت برای ارسال ممکن نشد.'];
    }
    file_put_contents($tmpFile, $binaryContent);

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chat_id'  => $chatId,
            'caption'  => $caption,
            'document' => new CURLFile($tmpFile, 'application/pdf', $filename),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    @unlink($tmpFile);

    if ($body === false) {
        return [false, 'اتصال به تلگرام برقرار نشد: ' . $err];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        $desc = is_array($decoded) ? ($decoded['description'] ?? 'خطای نامشخص') : 'پاسخ نامعتبر از تلگرام';
        return [false, 'تلگرام درخواست را رد کرد: ' . $desc];
    }
    return [true, 'ok'];
}

/**
 * Chat IDs allowed to trigger bot commands (e.g. /invoice) via the webhook —
 * separate from telegram_chat_id() (the fixed outbound contact-form inbox),
 * since a command may come from a group the bot was added to. Admin-editable,
 * comma/space/newline separated; falls back to telegram_chat_id() alone so a
 * fresh install with only the contact-form chat configured still works.
 */
function telegram_bot_allowed_chats(): array {
    $raw = (string)(setting('telegram_bot_allowed_chats', '') ?? '');
    $ids = array_filter(array_map('trim', preg_split('/[,\s]+/', $raw) ?: []));
    if (!$ids && telegram_chat_id() !== '') {
        $ids = [telegram_chat_id()];
    }
    return array_values(array_unique($ids));
}

function telegram_webhook_secret(): string {
    return (string)(setting('telegram_webhook_secret', '') ?? '');
}
