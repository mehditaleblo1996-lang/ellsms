<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/Reports/SentMessages.php';

/**
 * The message drawer's cost (sent_report_find()['cost_breakdown']) is read from the stored records:
 * the price frozen on a bulk row and its own ledger charge, a direct send's snapshot and charged_credits,
 * and the row's delivery refund — never re-priced.
 */
final class MessageCostBreakdownTest extends IntegrationTestCase
{
    private int $owner = 0;
    private int $jobId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser();
        wallet_ensure_account(db(), $this->owner);
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status) VALUES (?, 'p2p', 'cost', '3000555', 'done')")
            ->execute([$this->owner]);
        $this->jobId = (int)db()->lastInsertId();
    }

    private function bulkItem(string $status, ?string $delivery = null): int
    {
        db()->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, parts, unit_price_millicredits, price_cost_credits)
                       VALUES (?, ?, ?, ?, ?, 2, 1200000, 2400)')
            ->execute([$this->jobId, '989121000001', 'سلام', $status, $delivery]);
        return (int)db()->lastInsertId();
    }

    private function charge(int $itemId, int $amount): void
    {
        db()->prepare("INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key)
                       VALUES (?, 'sms_debit', ?, 0, 0, 'bulk_job', ?, ?)")
            ->execute([$this->owner, -$amount, (string)$this->jobId, 'commit:bulk_item:' . $itemId]);
    }

    private function refund(string $source, int $id, int $amount, int $percent): void
    {
        db()->prepare("INSERT INTO ellsms_delivery_refunds (user_id, source, source_id, period_id, delivery_status, charged_amount, refund_percent, refund_amount)
                       VALUES (?, ?, ?, 0, 'failed', ?, ?, ?)")
            ->execute([$this->owner, $source, $id, $amount, $percent, $amount * $percent / 100]);
    }

    private function cost(string $ref): array
    {
        $m = sent_report_find($ref, [$this->owner], true, false);
        $this->assertNotNull($m);
        return $m['cost_breakdown'];
    }

    public function testABulkMessageIsChargedByItsOwnLedgerRowAtItsFrozenPrice(): void
    {
        $id = $this->bulkItem('sent', 'delivered');
        $this->charge($id, 2400);
        $cost = $this->cost('B' . $id);
        $this->assertSame('charged', $cost['state']);
        $this->assertSame(2400, $cost['charged']);
        $this->assertSame('۲ پارت × ۱,۲۰۰ ریال = ۲,۴۰۰ ریال', sent_report_cost_formula($cost));
    }

    public function testARefundedBulkMessage(): void
    {
        $id = $this->bulkItem('sent', 'failed');
        $this->charge($id, 2400);
        $this->refund('bulk_item', $id, 2400, 100);
        $cost = $this->cost('B' . $id);
        $this->assertSame('refunded', $cost['state']);
        $this->assertSame(2400, $cost['refunded']);
        $this->assertSame(0, $cost['net']);
        $this->assertNotNull($cost['refunded_at']);
    }

    public function testAFailedOrUnchargedBulkMessage(): void
    {
        $this->assertSame('not_charged', $this->cost('B' . $this->bulkItem('failed'))['state']);
        $uncharged = $this->cost('B' . $this->bulkItem('sent'));
        $this->assertSame(0, $uncharged['charged'], 'no ledger row: nothing was taken');
        $this->assertSame('بدون هزینه', $uncharged['label']);
        $this->assertSame('reserved', $this->cost('B' . $this->bulkItem('pending'))['state']);
    }

    public function testADirectSendTakesItsUnitPriceFromTheSnapshotAndItsChargeFromTheAttempt(): void
    {
        $mci = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
        $ref = 'cost-' . bin2hex(random_bytes(4));
        db()->prepare("INSERT INTO ellsms_sms_price_snapshots (user_id, reference_type, reference_id, group_key, operator_id, unit_price_millicredits,
                              recipient_count, segment_count, total_cost_credits, committed_cost_credits, status, priced_at)
                       VALUES (?, 'direct_send', ?, 'g1', ?, 1150000, 2, 4, 4600, 4600, 'settled', NOW())")
            ->execute([$this->owner, $ref, $mci]);
        $attempt = db()->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, destination, operator_id, content, parts, charged_credits)
                                  VALUES (?, 'direct_send', ?, 'accepted', ?, ?, 'سلام', 2, ?)");
        $attempt->execute([$this->owner, $ref, '989121000002', $mci, 2300]);
        $id = (int)db()->lastInsertId();
        $attempt->execute([$this->owner, $ref, '989121000003', $mci, null]);
        $unknown = (int)db()->lastInsertId();

        $cost = $this->cost('D' . $id);
        $this->assertSame('charged', $cost['state']);
        $this->assertSame(2300, $cost['charged']);
        $this->assertSame('۲ پارت × ۱,۱۵۰ ریال = ۲,۳۰۰ ریال', sent_report_cost_formula($cost));

        $this->refund('attempt', $id, 2300, 50);
        $this->assertSame('partly_refunded', $this->cost('D' . $id)['state']);
        $this->assertSame(1150, $this->cost('D' . $id)['net']);

        // A two-recipient send whose per-recipient charge was not written: not guessed.
        $this->assertSame('unknown', $this->cost('D' . $unknown)['state']);
    }
}
