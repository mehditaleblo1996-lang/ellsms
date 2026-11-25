<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Number shop (app/NumberShop.php, db/migrations/2026_11_28_number_shop.sql): listing, wallet and
 * online purchase, approval, rejection with refund, a failed / lapsed online payment, and renewal.
 */
final class NumberShopTest extends IntegrationTestCase
{
    private int $adminId;
    private int $buyerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->makeUser(['is_admin' => 1]);
        $this->buyerId = $this->makeUser();
        putenv('BILLING_TAX_PERCENT=10');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_TAX_PERCENT');
        parent::tearDown();
    }

    private function listing(string $number, int $price = 1_000_000, int $renewal = 200_000): int
    {
        $v = number_shop_validate_listing([
            'price' => number_format($price), 'renewal_price' => (string)$renewal, 'category' => 'رند', 'title' => 'خط تست',
            'description' => 'یک شماره‌ی رند برای تست فروشگاه', 'features' => "- ارسال به همه‌ی اپراتورها\n\n• دریافت پیامک", 'featured' => '1',
        ]);
        $this->assertTrue($v['ok'], $v['error'] ?? '');
        $r = number_shop_list_numbers([$number], $v['data'], $this->adminId);
        $this->assertSame([$number], $r['listed']);
        $st = db()->prepare('SELECT l.id FROM ellsms_number_listings l JOIN ellsms_numbers n ON n.id = l.number_id WHERE n.number = ?');
        $st->execute([$number]);
        return (int)$st->fetchColumn();
    }

    private function buyer(): array
    {
        return ['id' => $this->buyerId, 'organization_id' => null];
    }

    private function fund(int $amount): void
    {
        wallet_credit($this->buyerId, $amount, 'manual_credit', 'test', 'fund', 'test_fund:' . bin2hex(random_bytes(6)));
    }

    private function line(string $number): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE number = ?');
        $st->execute([$number]);
        return $st->fetch();
    }

    private function order(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_number_orders WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function invoice(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_invoices WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    public function testValidationRequiresPricesAndADescription(): void
    {
        $this->assertFalse(number_shop_validate_listing(['price' => '', 'renewal_price' => '5000', 'description' => 'توضیحات کامل شماره'])['ok']);
        $this->assertFalse(number_shop_validate_listing(['price' => '5000', 'renewal_price' => '', 'description' => 'توضیحات کامل شماره'])['ok']);
        $this->assertFalse(number_shop_validate_listing(['price' => '5000', 'renewal_price' => '5000', 'description' => 'کوتاه'])['ok']);
        $ok = number_shop_validate_listing(['price' => '۵۰٬۰۰۰', 'renewal_price' => '5,000', 'description' => 'توضیحات کامل شماره', 'features' => "✓ یک\n\n- دو"]);
        $this->assertTrue($ok['ok']);
        $this->assertSame(50000, $ok['data']['price'], 'Persian digits and separators are accepted');
        $this->assertSame("یک\nدو", $ok['data']['features'], 'bullets and empty lines are dropped');
        $this->assertSame('9000 1234 56', number_shop_pretty('9000123456'));
    }

    public function testAnAssignedLineIsNotListedAndAListedLineIsNotListedTwice(): void
    {
        $number = '9100' . random_int(100000, 999999);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$number, '', $this->buyerId]);
        $data = number_shop_validate_listing(['price' => '10000', 'renewal_price' => '5000', 'description' => 'توضیحات کامل شماره'])['data'];
        $r = number_shop_list_numbers([$number], $data, $this->adminId);
        $this->assertSame([], $r['listed']);
        $this->assertCount(1, $r['skipped']);

        $free = '9101' . random_int(100000, 999999);
        $this->listing($free);
        $again = number_shop_list_numbers([$free], $data, $this->adminId);
        $this->assertSame([], $again['listed'], 'already on sale');
        $this->assertNull($this->line($free)['assigned_user_id'], 'a listed line belongs to nobody');
    }

    public function testWalletPurchaseWaitsForApprovalThenAssignsTheLineForAYear(): void
    {
        $number = '9200' . random_int(100000, 999999);
        $listingId = $this->listing($number);

        $poor = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'wallet');
        $this->assertSame(['ok' => false, 'reason' => 'insufficient_balance'], $poor);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_number_orders WHERE listing_id = ' . $listingId)->fetchColumn(), 'a refused checkout leaves nothing behind');
        $this->assertSame('on_sale', number_shop_listing($listingId)['state']);

        $this->fund(1_100_000);
        $r = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'wallet');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['paid']);
        $this->assertSame(1_100_000, $r['total'], 'price + 10% VAT');
        $this->assertSame(0, wallet_balance($this->buyerId)['available']);
        $order = $this->order($r['order_id']);
        $this->assertSame('awaiting_approval', $order['status']);
        $invoice = $this->invoice((int)$order['invoice_id']);
        $this->assertSame('number', $invoice['purpose']);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame(100_000, (int)$invoice['tax_amount']);
        $this->assertSame('reserved', number_shop_listing($listingId)['state']);
        $this->assertNull($this->line($number)['assigned_user_id'], 'not assigned before approval');
        $this->assertSame(1, number_shop_pending_approval_count() >= 1 ? 1 : 0);

        $other = $this->makeUser();
        wallet_credit($other, 5_000_000, 'manual_credit', 'test', 'fund', 'test_fund_other:' . $other);
        $this->assertSame('unavailable', number_shop_checkout(['id' => $other], $listingId, 'purchase', 'wallet')['reason'], 'a reserved line cannot be bought again');

        $approve = number_shop_approve($r['order_id'], $this->adminId, 'ok');
        $this->assertTrue($approve['ok'], $approve['error'] ?? '');
        $line = $this->line($number);
        $this->assertSame($this->buyerId, (int)$line['assigned_user_id']);
        $this->assertSame('active', $line['status']);
        $this->assertSame(number_add_jalali_year(number_today()), $line['expires_on']);
        $this->assertSame('sold', number_shop_listing($listingId)['state']);
        $this->assertSame('completed', $this->order($r['order_id'])['status']);
        $this->assertFalse(number_shop_approve($r['order_id'], $this->adminId)['ok'], 'a decided order cannot be decided again');
    }

    public function testRejectionRefundsTheWalletAndPutsTheLineBackOnSale(): void
    {
        $number = '9300' . random_int(100000, 999999);
        $listingId = $this->listing($number);
        $this->fund(1_100_000);
        $r = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'wallet');
        $this->assertFalse(number_shop_reject($r['order_id'], $this->adminId, '  ')['ok'], 'a reason is required');

        $reject = number_shop_reject($r['order_id'], $this->adminId, 'مدارک ناقص');
        $this->assertTrue($reject['ok']);
        $this->assertSame(1_100_000, wallet_balance($this->buyerId)['available'], 'the whole total comes back');
        $order = $this->order($r['order_id']);
        $this->assertSame('rejected', $order['status']);
        $this->assertSame('refunded', $this->invoice((int)$order['invoice_id'])['status']);
        $this->assertSame('on_sale', number_shop_listing($listingId)['state']);
        $this->assertNull($this->line($number)['assigned_user_id']);
        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE user_id = ? AND type = 'number_refund'");
        $st->execute([$this->buyerId]);
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    public function testOnlinePaymentIsCreditedThenSpentOnTheOrderInTheClaim(): void
    {
        $number = '9400' . random_int(100000, 999999);
        $listingId = $this->listing($number);
        $r = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'online');
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['paid']);
        $listing = number_shop_listing($listingId);
        $this->assertSame('reserved', $listing['state']);
        $this->assertNotNull($listing['reserved_until'], 'held only for the payment window');

        $st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $st->execute([$r['payment_id']]);
        $payment = $st->fetch();
        $this->assertSame(1_100_000, (int)$payment['amount_rial'], 'the gateway charges the invoice total');
        $this->assertSame((int)$r['order_id'], (int)$payment['number_order_id']);

        $claim = payment_claim_and_credit($payment, 'REF-' . $r['payment_id']);
        $this->assertTrue($claim['claimed']);
        $this->assertSame(0, wallet_balance($this->buyerId)['available'], 'credited then debited for the order');
        $this->assertSame('awaiting_approval', $this->order($r['order_id'])['status']);
        $this->assertNull(number_shop_listing($listingId)['reserved_until'], 'paid: no longer lapses');
        $this->assertSame('paid', $this->invoice((int)$this->order($r['order_id'])['invoice_id'])['status']);
        $this->assertFalse(payment_claim_and_credit($payment, 'REF-again')['claimed'], 'a replayed callback does nothing');
    }

    public function testAFailedOrLapsedOnlinePaymentFreesTheLineAndALatePaymentStaysInTheWallet(): void
    {
        $number = '9500' . random_int(100000, 999999);
        $listingId = $this->listing($number);
        $first = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'online');
        number_shop_fail_for_payment($first['payment_id']);
        $this->assertSame('failed', $this->order($first['order_id'])['status']);
        $this->assertSame('on_sale', number_shop_listing($listingId)['state']);

        $second = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'online');
        db()->prepare('UPDATE ellsms_number_listings SET reserved_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$listingId]);
        number_shop_release_stale();
        $this->assertSame('expired', $this->order($second['order_id'])['status']);
        $this->assertSame('on_sale', number_shop_listing($listingId)['state']);

        // Someone else buys it with their wallet, then the first buyer's late payment arrives.
        $other = $this->makeUser();
        wallet_credit($other, 2_000_000, 'manual_credit', 'test', 'fund', 'test_fund_late:' . $other);
        $this->assertTrue(number_shop_checkout(['id' => $other], $listingId, 'purchase', 'wallet')['ok']);
        $st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $st->execute([$second['payment_id']]);
        $claim = payment_claim_and_credit($st->fetch(), 'REF-late');
        $this->assertTrue($claim['claimed']);
        $this->assertSame('refunded', $this->order($second['order_id'])['status']);
        $this->assertSame(1_100_000, wallet_balance($this->buyerId)['available'], 'the money is not lost: it stays in the wallet');
    }

    public function testRenewalExtendsTheExpiryByAYearAndOnlyForTheOwner(): void
    {
        $number = '9600' . random_int(100000, 999999);
        $listingId = $this->listing($number, 1_000_000, 300_000);
        $this->fund(1_100_000 + 330_000);
        $buy = number_shop_checkout($this->buyer(), $listingId, 'purchase', 'wallet');
        number_shop_approve($buy['order_id'], $this->adminId);
        $expires = $this->line($number)['expires_on'];

        $stranger = $this->makeUser();
        $this->assertSame('not_owner', number_shop_checkout(['id' => $stranger], $listingId, 'renewal', 'wallet')['reason']);

        $renew = number_shop_checkout($this->buyer(), $listingId, 'renewal', 'wallet');
        $this->assertTrue($renew['ok']);
        $this->assertSame(330_000, $renew['total']);
        $this->assertSame(number_add_jalali_year($expires), $this->line($number)['expires_on']);
        $this->assertSame('completed', $this->order($renew['order_id'])['status']);
        $this->assertSame(0, wallet_balance($this->buyerId)['available']);

        // An expired line renews from today, not from the old date.
        db()->prepare("UPDATE ellsms_numbers SET expires_on = '2020-01-01' WHERE number = ?")->execute([$number]);
        $this->fund(330_000);
        number_shop_checkout($this->buyer(), $listingId, 'renewal', 'wallet');
        $this->assertSame(number_add_jalali_year(number_today()), $this->line($number)['expires_on']);
    }

    public function testTheStorefrontShowsOnlyLinesOnSaleWithFilters(): void
    {
        $a = '9700' . random_int(100000, 999999);
        $this->listing($a, 2_000_000);
        $b = '9701' . random_int(100000, 999999);
        $bId = $this->listing($b, 9_000_000);
        number_shop_listing_set_on_sale($bId, false, $this->adminId);

        $rows = number_shop_storefront(['q' => substr($a, 0, 6)], 50, 0)['rows'];
        $this->assertContains($a, array_column($rows, 'number'));
        $this->assertNotContains($b, array_column(number_shop_storefront(['q' => substr($b, 0, 6)], 50, 0)['rows'], 'number'), 'withdrawn lines are hidden');
        $this->assertNotContains($a, array_column(number_shop_storefront(['q' => substr($a, 0, 6), 'max' => 1_000_000], 50, 0)['rows'], 'number'));
        $this->assertArrayHasKey('رند', number_shop_categories());
    }
}
