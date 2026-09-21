<?php
/**
 * ELLSMS — Telegram bot webhook: generate the price-quote/invoice PDF on
 * request, from a private chat with the bot or from a group it's in.
 *
 * Telegram calls this URL directly (no ELLSMS session, no CSRF token) — the
 * only guard is the secret token Telegram echoes back in the
 * X-Telegram-Bot-Api-Secret-Token header, which we set when registering the
 * webhook (see docs/telegram-invoice-bot.md) and store as
 * ellsms_settings.telegram_webhook_secret. A request without a matching
 * header is rejected before any update JSON is even parsed.
 *
 * Beyond that, only chat IDs on the admin's allow-list
 * (telegram_bot_allowed_chats(), app/telegram.php) get a response — anyone
 * else who finds the bot and messages it (or adds it to an unrelated group)
 * is silently ignored, never given a generated document.
 */

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

function telegram_webhook_ack(): never {
    // Always 200 — a non-200 makes Telegram retry the same update repeatedly.
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

$configuredSecret = telegram_webhook_secret();
$givenSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($configuredSecret === '' || !hash_equals($configuredSecret, $givenSecret)) {
    Logger::warning('telegram.webhook.bad_secret', ['has_configured' => $configuredSecret !== '']);
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);
$message = (is_array($update) ? ($update['message'] ?? $update['channel_post'] ?? null) : null);
if (!is_array($message)) telegram_webhook_ack();

$chatId = (string)($message['chat']['id'] ?? '');
$text   = (string)($message['text'] ?? '');

if ($chatId === '' || !in_array($chatId, telegram_bot_allowed_chats(), true)) {
    telegram_webhook_ack();
}

if (!preg_match('#^/invoice(@\w+)?\b#u', trim($text))) {
    telegram_webhook_ack();
}

$baseDoc = price_quote_load();
$doc     = price_quote_parse_telegram_message($text, $baseDoc);
$html    = price_quote_render_html($doc);
$pdf     = price_quote_render_pdf($html);

if ($pdf === null) {
    telegram_send_message_to($chatId, 'ساخت PDF روی سرور ممکن نشد. از مدیر بخواهید نصب wkhtmltopdf را روی سرور بررسی کند.');
    telegram_webhook_ack();
}

$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $doc['invoice_no'] ?: 'ellsms-invoice') . '.pdf';
[$ok, $msg] = telegram_send_document_to($chatId, $filename, $pdf, 'فاکتور ' . $doc['invoice_no']);
if (!$ok) {
    Logger::warning('telegram.webhook.send_failed', ['chat_id' => $chatId, 'message' => $msg]);
}

telegram_webhook_ack();
