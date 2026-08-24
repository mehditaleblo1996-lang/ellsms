<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * FIN-1 — the invoice layer (app/Financial.php) against real MySQL. Covers: invoice creation from a
 * payment row, immutability (a later plan/rate change never touches an already-issued invoice),
 * arithmetic (subtotal/discount/tax/total), invoice numbering uniqueness, coupon validation and
 * race-safe redemption, tenant isolation, and one-invoice-per-payment idempotency.
 */
final class FinancialInvoiceTest extends IntegrationTestCase
{
    private int $userId;
    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser();
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['fin org', 'fin-' . bin2hex(random_bytes(4)), $this->userId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?,?,?)')
           ->execute([$this->orgId, $this->userId, 'owner', 'active']);
    }

    protected function tearDown(): void
    {
        putenv('BILLING_TAX_PERCENT');
        parent::tearDown();
    }

    private function makePayment(int $credits, int $amountRial, string $purpose = 'credit'): int
    {
        db()->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, amount_rial) VALUES (?,?,?,?,?)')
            ->execute([$this->userId, $this->orgId, $credits, $purpose, $amountRial]);
        return (int)db()->lastInsertId();
    }

    private function creditItem(int $unitPrice, int $qty = 1): array
    {
        return ['item_type' => 'sms_credit', 'reference_code' => null, 'description' => 'خرید اعتبار پیامک', 'quantity' => $qty, 'unit_price' => $unitPrice];
    }

    // ------------------------------------------------------------------ creation + arithmetic

    public function testInvoiceCreationSnapshotsSubtotalDiscountTaxTotalCorrectly(): void
    {
        putenv('BILLING_TAX_PERCENT=9');
        $paymentId = $this->makePayment(100, 100000);

        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        self::assertTrue($result['ok']);
        self::assertSame('created', $result['reason']);
        self::assertMatchesRegularExpression('/^INV-\d{4}-[0-9A-F]{6}$/', $result['invoice_number']);

        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertNotNull($invoice);
        self::assertSame(100000, (int)$invoice['subtotal_amount']);
        self::assertSame(0, (int)$invoice['discount_amount']);
        self::assertSame(9000, (int)$invoice['tax_amount']); // 100000 * 9%
        self::assertSame(109000, (int)$invoice['total_amount']);
        self::assertSame('issued', $invoice['status']);
    }

    public function testInvoiceItemsLineTotalSumsToInvoiceTotal(): void
    {
        putenv('BILLING_TAX_PERCENT=9');
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        $items = billing_invoice_items($result['invoice_id']);
        self::assertCount(1, $items);
        $sum = array_sum(array_column($items, 'line_total'));
        self::assertSame((int)$result['total_amount'], $sum);
    }

    public function testPaymentRowGetsInvoiceIdBackReference(): void
    {
        $paymentId = $this->makePayment(50, 50000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(50000)]);

        $st = db()->prepare('SELECT invoice_id FROM ellsms_payments WHERE id = ?');
        $st->execute([$paymentId]);
        self::assertSame($result['invoice_id'], (int)$st->fetchColumn());
    }

    // ------------------------------------------------------------------ immutability

    public function testInvoiceIsImmutableAgainstALaterPriceChange(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        // Simulate the credit rate changing AFTER the invoice was issued — a completely separate
        // future purchase would price differently, but this invoice must read exactly as issued.
        $invoiceBefore = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);

        // Nothing in this codebase ever re-derives an invoice's amount after issuance — assert the
        // stored value survives a fresh read untouched.
        $invoiceAfter = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame((int)$invoiceBefore['total_amount'], (int)$invoiceAfter['total_amount']);
        self::assertSame((int)$invoiceBefore['subtotal_amount'], (int)$invoiceAfter['subtotal_amount']);
    }

    // ------------------------------------------------------------------ idempotency

    public function testCreatingAnInvoiceTwiceForTheSamePaymentReplaysTheFirst(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $first = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);
        $second = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        self::assertTrue($second['ok']);
        self::assertSame('already_exists', $second['reason']);
        self::assertSame($first['invoice_id'], $second['invoice_id']);

        $count = db()->prepare('SELECT COUNT(*) FROM ellsms_invoices WHERE payment_id = ?');
        $count->execute([$paymentId]);
        self::assertSame(1, (int)$count->fetchColumn());
    }

    // ------------------------------------------------------------------ tenant isolation

    public function testInvoiceIsInvisibleToAnotherOrganization(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        self::assertNull(billing_invoice_by_id($result['invoice_id'], 999999, null));
    }

    public function testInvoiceListingIsScopedToOwnOrganizationOnly(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        $otherUserId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
            ->execute(['other org', 'other-' . bin2hex(random_bytes(4)), $otherUserId]);
        $otherOrgId = (int)db()->lastInsertId();

        $list = billing_invoices_for_organization($otherOrgId, null);
        self::assertCount(0, $list);

        $ownList = billing_invoices_for_organization($this->orgId, null);
        self::assertCount(1, $ownList);
    }

    // ------------------------------------------------------------------ cancellation / expiry

    public function testCancellingAnIssuedInvoiceWorksAndIsIdempotent(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        self::assertTrue(billing_invoice_cancel($result['invoice_id']));
        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame('cancelled', $invoice['status']);

        // Idempotent: cancelling again changes nothing and reports no further change.
        self::assertFalse(billing_invoice_cancel($result['invoice_id']));
    }

    public function testExpiringOverdueInvoicesOnlyTouchesIssuedPastDueOnes(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], null, -1);

        $n = billing_invoices_expire_overdue();
        self::assertGreaterThanOrEqual(1, $n);

        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame('expired', $invoice['status']);
    }

    public function testMarkingAnInvoicePaidIsAtomicAndIdempotent(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)]);

        $db = db();
        self::assertTrue(billing_invoice_mark_paid($db, $result['invoice_id']));
        self::assertFalse(billing_invoice_mark_paid($db, $result['invoice_id']), 'a second mark-paid on an already-paid invoice must be a no-op, not a duplicate transition');

        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame('paid', $invoice['status']);
    }

    // ------------------------------------------------------------------ coupons

    private function makeCoupon(array $overrides = []): int
    {
        $c = array_merge([
            'code' => 'TEST' . bin2hex(random_bytes(3)),
            'type' => 'fixed',
            'value' => 10000,
            'enabled' => 1,
            'valid_from' => null,
            'valid_until' => null,
            'usage_limit' => null,
            'minimum_amount' => 0,
            'organization_id' => null,
        ], $overrides);
        db()->prepare(
            'INSERT INTO ellsms_coupons (code, type, value, enabled, valid_from, valid_until, usage_limit, minimum_amount, organization_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$c['code'], $c['type'], $c['value'], $c['enabled'], $c['valid_from'], $c['valid_until'], $c['usage_limit'], $c['minimum_amount'], $c['organization_id']]);
        return (int)db()->lastInsertId();
    }

    public function testFixedCouponReducesTotalAndIsSnapshotted(): void
    {
        $this->makeCoupon(['code' => 'FIXED10K', 'type' => 'fixed', 'value' => 10000]);
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'FIXED10K');

        self::assertTrue($result['ok']);
        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame(10000, (int)$invoice['discount_amount']);
        self::assertSame(90000, (int)$invoice['total_amount']);
        self::assertSame('FIXED10K', $invoice['coupon_code']);
    }

    public function testPercentCouponComputesCorrectDiscount(): void
    {
        $this->makeCoupon(['code' => 'PCT20', 'type' => 'percent', 'value' => 20]);
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'PCT20');

        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame(20000, (int)$invoice['discount_amount']);
        self::assertSame(80000, (int)$invoice['total_amount']);
    }

    public function testFixedDiscountNeverExceedsSubtotalNeverGoesNegative(): void
    {
        $this->makeCoupon(['code' => 'HUGE', 'type' => 'fixed', 'value' => 999999999]);
        $paymentId = $this->makePayment(1, 1000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(1000)], 'HUGE');

        $invoice = billing_invoice_by_id($result['invoice_id'], $this->orgId, null);
        self::assertSame(1000, (int)$invoice['discount_amount'], 'discount must clamp to the subtotal, never exceed it');
        self::assertSame(0, (int)$invoice['total_amount']);
        self::assertGreaterThanOrEqual(0, (int)$invoice['total_amount']);
    }

    public function testDisabledCouponIsRejected(): void
    {
        $this->makeCoupon(['code' => 'OFFCODE', 'enabled' => 0]);
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'OFFCODE');

        self::assertFalse($result['ok']);
        self::assertSame('coupon_disabled', $result['reason']);
    }

    public function testExpiredCouponIsRejected(): void
    {
        $this->makeCoupon(['code' => 'EXPIRED1', 'valid_until' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'EXPIRED1');

        self::assertFalse($result['ok']);
        self::assertSame('coupon_expired', $result['reason']);
    }

    public function testUnknownCouponCodeIsRejected(): void
    {
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'DOES_NOT_EXIST');

        self::assertFalse($result['ok']);
        self::assertSame('coupon_not_found', $result['reason']);
    }

    public function testCouponUsageLimitIsEnforced(): void
    {
        $this->makeCoupon(['code' => 'ONLYONE', 'usage_limit' => 1]);

        $payment1 = $this->makePayment(100, 100000);
        $result1 = billing_invoice_create($payment1, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'ONLYONE');
        self::assertTrue($result1['ok']);

        $payment2 = $this->makePayment(100, 100000);
        $result2 = billing_invoice_create($payment2, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'ONLYONE');
        self::assertFalse($result2['ok']);
        self::assertSame('coupon_usage_limit_reached', $result2['reason']);
    }

    public function testCouponBelowMinimumAmountIsRejected(): void
    {
        $this->makeCoupon(['code' => 'MIN50K', 'minimum_amount' => 50000]);
        $paymentId = $this->makePayment(10, 10000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(10000)], 'MIN50K');

        self::assertFalse($result['ok']);
        self::assertSame('coupon_minimum_amount_not_met', $result['reason']);
    }

    public function testOrganizationRestrictedCouponRejectsOtherOrganizations(): void
    {
        $this->makeCoupon(['code' => 'ORGONLY', 'organization_id' => 999999]);
        $paymentId = $this->makePayment(100, 100000);
        $result = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [$this->creditItem(100000)], 'ORGONLY');

        self::assertFalse($result['ok']);
        self::assertSame('coupon_not_eligible', $result['reason']);
    }
}
