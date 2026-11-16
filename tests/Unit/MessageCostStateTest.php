<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/SentMessages.php';

/**
 * What the message drawer says a message cost (sent_report_cost_state(), sent_report_cost_formula()):
 * «X پارت × قیمت = مبلغ» and whether it was charged, refunded, reserved or free.
 */
final class MessageCostStateTest extends TestCase
{
    public function testAChargedMessageShowsItsFormula(): void
    {
        $cost = sent_report_cost_state('bulk', 'sent', 2, 1200000, 2400);
        $this->assertSame('charged', $cost['state']);
        $this->assertSame('کسر شد', $cost['label']);
        $this->assertSame(2400, $cost['formula_amount']);
        $this->assertSame(2400, $cost['net']);
        $this->assertSame('۲ پارت × ۱,۲۰۰ ریال = ۲,۴۰۰ ریال', sent_report_cost_formula($cost));
    }

    public function testAFractionalUnitPriceRoundsTheMessageUpOnce(): void
    {
        $cost = sent_report_cost_state('attempt', 'accepted', 3, 850500, 2552);
        $this->assertSame(2552, $cost['formula_amount'], 'ceil(3 × 850.5)');
        $this->assertSame('۳ پارت × ۸۵۰.۵ ریال = ۲,۵۵۲ ریال', sent_report_cost_formula($cost));
    }

    public function testRefunds(): void
    {
        $full = sent_report_cost_state('attempt', 'accepted', 1, 1200000, 1200, 1200, 100, '2026-11-01 10:00:00');
        $this->assertSame('refunded', $full['state']);
        $this->assertSame('برگشت شد', $full['label']);
        $this->assertSame(0, $full['net']);

        $part = sent_report_cost_state('bulk', 'sent', 1, 1200000, 1200, 600, 50);
        $this->assertSame('partly_refunded', $part['state']);
        $this->assertSame(600, $part['net']);
        $this->assertStringContainsString('۵۰٪', $part['note']);

        $due = sent_report_cost_state('bulk', 'sent', 1, 1200000, 1200, 0, null, null, true);
        $this->assertSame('refund_pending', $due['state']);
    }

    public function testMessagesThatCostNothingOrAreStillReserved(): void
    {
        $this->assertSame('not_charged', sent_report_cost_state('bulk', 'failed', 2, 1200000, null)['state']);
        $this->assertSame(0, sent_report_cost_state('bulk', 'failed', 2, 1200000, null)['charged']);
        $this->assertSame('not_charged', sent_report_cost_state('attempt', 'failed', 1, null, null)['state']);
        $this->assertSame('not_charged', sent_report_cost_state('bulk', 'cancelled', 1, 1200000, null)['state']);
        $this->assertSame('بدون هزینه', sent_report_cost_state('bulk', 'sent', 1, 1200000, 0)['label'], 'an admin\'s own send');
        $reserved = sent_report_cost_state('bulk', 'pending', 2, 1200000, null);
        $this->assertSame('reserved', $reserved['state']);
        $this->assertSame(2400, $reserved['formula_amount']);
    }

    public function testUnknownChargesAndLegacySendsSaySoInsteadOfGuessing(): void
    {
        $this->assertSame('unknown', sent_report_cost_state('attempt', 'accepted', 1, 1200000, null)['state']);
        $legacy = sent_report_cost_state('legacy', 'sent', 1, null, null);
        $this->assertSame('unknown', $legacy['state']);
        $this->assertStringContainsString('تاریخچه مالی', $legacy['note']);
        $this->assertSame('', sent_report_cost_formula($legacy), 'no unit price, no formula');
    }

    public function testTheSnapshotGroupOfARecipient(): void
    {
        $groups = [['operator_id' => 1, 'route_id' => 5, 'unit_price_millicredits' => 1], ['operator_id' => 2, 'route_id' => 5, 'unit_price_millicredits' => 2]];
        $this->assertSame(2, sent_report_snapshot_group($groups, 2, 5)['unit_price_millicredits']);
        $this->assertNull(sent_report_snapshot_group($groups, 3, 5));
        $this->assertSame(1, sent_report_snapshot_group([$groups[0]], 3, null)['unit_price_millicredits'], 'the only group');
        $this->assertSame(['operator_id' => null, 'route_id' => null], sent_report_snapshot_group([['operator_id' => null, 'route_id' => null]], null, 9));
    }
}
