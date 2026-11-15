<?php
/**
 * The `undelivered` delivery state, unmapped provider codes and «failed messages are free» —
 * db/migrations/2026_11_19_undelivered_state.sql, docs/delivery-states.md. Pure functions only.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Api/Handlers/DeliveryState.php';
require_once dirname(__DIR__, 2) . '/app/Reports/StatusReport.php';

final class UndeliveredStateTest extends TestCase
{
    public function testUndeliveredIsAMappableFinalState(): void
    {
        self::assertContains('undelivered', GATEWAY_DELIVERY_STATES);
        self::assertTrue(gateway_state_is_terminal('undelivered'));
        self::assertSame('undelivered', gateway_state_storable('undelivered'), 'stored as itself, not as failed');
        self::assertStringContainsString("'undelivered'", GATEWAY_TERMINAL_STORED_SQL, 'the poller stops asking about it');
        self::assertFalse(gateway_status_may_transition('undelivered', 'delivered'), 'final: never changed afterwards');

        $map = gateway_status_mapping_compile(['4' => 'undelivered', '10' => 'delivered']);
        self::assertSame('undelivered', gateway_status_map($map, '4'));
    }

    public function testUndeliveredIsNeverRefunded(): void
    {
        self::assertNotContains('undelivered', DELIVERY_REFUND_STATES);
        self::assertSame(['failed', 'rejected', 'expired'], DELIVERY_REFUND_STATES);
    }

    public function testAnUnmappedCodeIsFailedOnlyWhenTheGatewayHasAMapping(): void
    {
        $map = gateway_status_mapping_compile(['0' => 'queued', '1' => 'sent', '10' => 'delivered']);

        self::assertSame('failed', gateway_status_map($map, '77'));
        self::assertTrue(gateway_status_is_unmapped($map, '77'));
        self::assertSame('sent', gateway_status_map($map, '1'));
        self::assertFalse(gateway_status_is_unmapped($map, '1'));

        self::assertSame('unknown', gateway_status_map($map, ''), 'no code at all: keep polling');
        self::assertSame('unknown', gateway_status_map($map, null));
        self::assertSame('unknown', gateway_status_map([], '77'), 'a gateway without a mapping is unchanged');
        self::assertFalse(gateway_status_is_unmapped([], '77'));
    }

    public function testACallbackTokenFollowsTheSameRule(): void
    {
        $connector = ['status' => ['statuses' => gateway_status_mapping_compile(['10' => 'delivered', '4' => 'undelivered'])]];

        self::assertSame('undelivered', sms_callback_status_canonical($connector, '4'));
        self::assertSame('failed', sms_callback_status_canonical($connector, '55'), 'unmapped on a gateway with a mapping');
        self::assertSame('delivered', sms_callback_status_canonical($connector, 'DELIVRD'), 'built-in tokens still understood');
        self::assertSame('unknown', sms_callback_status_canonical(null, '55'), 'no mapping: unchanged');
    }

    public function testTheFreeRuleAppliesOnlyFromTheCutoff(): void
    {
        self::assertFalse(delivery_failed_is_free(null, '2026-11-20 10:00:00'), 'before the migration');
        self::assertTrue(delivery_failed_is_free('2026-11-19 12:00:00', '2026-11-19 12:00:00'));
        self::assertTrue(delivery_failed_is_free('2026-11-19 12:00:00', '2026-11-20 08:00:00'));
        self::assertFalse(delivery_failed_is_free('2026-11-19 12:00:00', '2026-11-19 11:59:59'), 'old messages keep the old rule');
    }

    public function testReportsApiAndIntegrationsCountUndeliveredAsNotDelivered(): void
    {
        self::assertSame('undelivered', api_message_recipient_state('accepted', 'undelivered'));
        self::assertSame('failed', report_delivery_status_class('undelivered'));
        self::assertSame('تحویل داده‌نشده', report_delivery_status_label('undelivered'));
        self::assertContains('undelivered', SENT_REPORT_UNDELIVERED);
        self::assertContains('undelivered', DASHBOARD_UNDELIVERED);
        self::assertSame(['not_delivered', true], remote_db_target_state('queued', 'sent', 'undelivered'));
        self::assertSame('sms_failed', webengage_dsn_status_for_state('undelivered')[0]);
        self::assertSame(3, NUMBER_FORWARD_VESAL_STATUS['undelivered']);
        self::assertSame('not_delivered', status_report_column('undelivered', true));
    }
}
