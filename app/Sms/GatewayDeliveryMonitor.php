<?php

declare(strict_types=1);

require_once __DIR__ . '/GatewayConnector.php';

/** Gateways with fewer than 60% delivered among final reports recorded in [from, until). */
function gateway_delivery_monitor_alerts(DateTimeImmutable $from, DateTimeImmutable $until): array {
    $utc = new DateTimeZone('UTC');
    $bounds = [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $until->setTimezone($utc)->format('Y-m-d H:i:s')];
    $terminal = GATEWAY_TERMINAL_STORED_SQL;
    $query = db()->prepare(
        "SELECT g.name, g.code, SUM(r.delivered) AS delivered, SUM(r.receipts) AS receipts
           FROM (
                SELECT gateway_id, SUM(delivery_status = 'delivered') AS delivered, COUNT(*) AS receipts
                  FROM ellsms_bulk_items
                 WHERE delivery_finalized_at >= ? AND delivery_finalized_at < ?
                   AND gateway_id IS NOT NULL AND delivery_status IN ({$terminal})
                 GROUP BY gateway_id
                UNION ALL
                SELECT gateway_id, SUM(delivery_status = 'delivered') AS delivered, COUNT(*) AS receipts
                  FROM ellsms_message_attempts
                 WHERE delivery_finalized_at >= ? AND delivery_finalized_at < ?
                   AND gateway_id IS NOT NULL AND delivery_status IN ({$terminal})
                 GROUP BY gateway_id
           ) r
           JOIN ellsms_sms_gateways g ON g.id = r.gateway_id
          GROUP BY g.id, g.name, g.code
         HAVING SUM(r.delivered) * 100 < SUM(r.receipts) * 60
          ORDER BY g.code"
    );
    $query->execute([...$bounds, ...$bounds]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function gateway_delivery_monitor_message(array $alert, DateTimeImmutable $from, DateTimeImmutable $until): string {
    $tehran = new DateTimeZone('Asia/Tehran');
    $start = $from->setTimezone($tehran)->format('Y-m-d H:i');
    $end = $until->setTimezone($tehran)->format('Y-m-d H:i');
    $percent = number_format($alert['delivered'] * 100 / $alert['receipts'], 2, '.', '');
    return "⚠️ افت نرخ دلیوری پیامک\n"
        . "درگاه: {$alert['name']} ({$alert['code']})\n"
        . "نرخ دلیوری: {$percent}٪ (آستانه: ۶۰٪)\n"
        . "تحویل‌شده: {$alert['delivered']} از {$alert['receipts']} گزارش نهایی\n"
        . "بازه به وقت تهران: {$start} تا {$end}";
}
