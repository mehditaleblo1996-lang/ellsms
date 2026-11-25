<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/DailyReport.php';
require_once __DIR__ . '/../app/Sms/GatewayDeliveryMonitor.php';

$dryRun = in_array('--dry-run', $argv, true);
$chatId = $dryRun ? '' : daily_report_chat_id();
if (!$dryRun && ($chatId === '' || telegram_bot_token() === '')) {
    echo "Telegram is not configured for the gateway delivery monitor.\n";
    exit(0);
}

$until = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$from = $until->modify('-4 hours');
$alerts = gateway_delivery_monitor_alerts($from, $until);
$failed = false;
foreach ($alerts as $alert) {
    $text = gateway_delivery_monitor_message($alert, $from, $until);
    if ($dryRun) {
        echo $text, "\n\n";
        continue;
    }
    [$ok] = telegram_send_message_to($chatId, $text);
    if (!$ok) {
        fwrite(STDERR, "Telegram alert failed for gateway {$alert['code']}.\n");
        $failed = true;
    }
}
echo count($alerts), " gateway(s) below the delivery threshold.\n";
exit($failed ? 1 : 0);
