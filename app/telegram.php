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

/** Relay a plain-text message to the configured chat. Returns [ok, message]. */
function telegram_send_message(string $text): array {
    $token  = telegram_bot_token();
    $chatId = telegram_chat_id();
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
