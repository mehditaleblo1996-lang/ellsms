<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;

require_once dirname(__DIR__, 2) . '/app/Sms/GatewayDeliveryMonitor.php';

final class GatewayDeliveryMonitorTest extends IntegrationTestCase
{
    public function testCountsFinalReportsAcrossBothSourcesAndUsesStrictThreshold(): void
    {
        $db = db();
        $gateway = $db->prepare('INSERT INTO ellsms_sms_gateways (code, name) VALUES (?, ?)');
        $gateway->execute(['monitor_low', 'Low delivery']);
        $low = (int)$db->lastInsertId();
        $gateway->execute(['monitor_boundary', 'At threshold']);
        $boundary = (int)$db->lastInsertId();
        $user = $this->makeUser();
        $attempt = $db->prepare(
            "INSERT INTO ellsms_message_attempts
                (user_id, reference_type, reference_id, status, gateway_id, destination, delivery_status, delivery_finalized_at)
             VALUES (?, 'direct_send', ?, 'accepted', ?, '989121234567', ?, ?)"
        );
        foreach ([
            [$low, 'delivered', '2001-11-26 10:00:00'],
            [$low, 'failed', '2001-11-26 08:00:00'],
            [$low, 'delivered', '2001-11-26 07:59:59'],
            [$low, 'delivered', '2001-11-26 12:00:00'],
            [$low, 'sent', null],
            [$boundary, 'delivered', '2001-11-26 10:00:00'],
            [$boundary, 'delivered', '2001-11-26 10:00:00'],
            [$boundary, 'delivered', '2001-11-26 10:00:00'],
            [$boundary, 'undelivered', '2001-11-26 10:00:00'],
            [$boundary, 'expired', '2001-11-26 10:00:00'],
        ] as $index => [$gatewayId, $status, $time]) {
            $attempt->execute([$user, 'monitor-' . $index, $gatewayId, $status, $time]);
        }

        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows)
                      VALUES (?, 'p2p', 'monitor test', '5000', 'done', 1)")->execute([$user]);
        $job = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, delivery_status)
                      VALUES (?, '989121234567', 'x', 'sent', ?, 'sent')")->execute([$job, $low]);
        $item = (int)$db->lastInsertId();

        // Use the real final-state writer and verify a duplicate report leaves receipt time intact.
        self::assertTrue(\gateway_status_record('bulk_item', $item, 'sent', 'undelivered', null));
        $getReceipt = $db->prepare('SELECT delivery_finalized_at FROM ellsms_bulk_items WHERE id = ?');
        $getReceipt->execute([$item]);
        self::assertIsString($getReceipt->fetchColumn());
        $db->prepare("UPDATE ellsms_bulk_items SET delivery_finalized_at = '2001-11-26 11:00:00' WHERE id = ?")
            ->execute([$item]);
        self::assertFalse(\gateway_status_record('bulk_item', $item, 'undelivered', 'undelivered', null));
        $getReceipt->execute([$item]);
        self::assertSame('2001-11-26 11:00:00', $getReceipt->fetchColumn());

        $from = new DateTimeImmutable('2001-11-26 11:30:00+03:30');
        $until = new DateTimeImmutable('2001-11-26 15:30:00+03:30');
        $alerts = \gateway_delivery_monitor_alerts($from, $until);
        self::assertSame(['monitor_low'], array_column($alerts, 'code'));
        self::assertSame(1, (int)$alerts[0]['delivered']);
        self::assertSame(3, (int)$alerts[0]['receipts']);
        self::assertStringContainsString('33.33٪', \gateway_delivery_monitor_message($alerts[0], $from, $until));
    }
}
