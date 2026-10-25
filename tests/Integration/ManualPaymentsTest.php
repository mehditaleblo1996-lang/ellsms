<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/InvoiceAdmin.php';

/**
 * Manual payment methods (app/ManualPayments.php) against real MySQL: which methods a customer is
 * offered and who collects them, the payment + invoice + manual row a report creates, approval
 * crediting the wallet (platform) or moving credit from the reseller's wallet, rejection, and the
 * guards around the invoice's own admin controls.
 */
final class ManualPaymentsTest extends IntegrationTestCase
{
    private const CARD = '6037991234567893';

    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        reseller_panel_available(true);
        manual_payments_available(true);
        putenv('BILLING_TAX_PERCENT=10');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_TAX_PERCENT');
        parent::tearDown();
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $level = 0): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp, expires_on) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $level, 10, 10000, $type === 'reseller' ? '2030-12-30' : null]);
    }

    private function card(int $ownerId, string $holder = 'شرکت نمونه'): int
    {
        $result = manual_payment_destination_save($ownerId, null, ['kind' => 'card', 'bank' => 'melli', 'holder_name' => $holder, 'card_number' => self::CARD, 'active' => '1'], 1);
        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        return $result['id'];
    }

    private function input(int $destinationId, string $document = 'DOC-1'): array
    {
        return ['destination_id' => (string)$destinationId, 'document_number' => $document, 'paid_on' => date('Y-m-d', time() - 3600 * 2), 'paid_time' => '09:15'];
    }

    private function row(string $table, int $id): array
    {
        $st = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch();
    }

    public function testMethodsAreOfferedOnlyWhenEnabledAndPayable(): void
    {
        $customer = $this->makeUser();
        $this->assertSame([], manual_payment_options_for($customer));

        manual_payment_methods_save(0, ['card', 'cash', 'cheque'], 1);
        // card and cheque need somewhere to pay into; cash does not.
        $this->assertSame(['cash'], array_keys(manual_payment_options_for($customer)));

        $this->card(0);
        $options = manual_payment_options_for($customer);
        $this->assertSame(['card', 'cash'], array_keys($options));
        $this->assertSame(0, $options['card']['collector']);
        $this->assertCount(1, $options['card']['destinations']);

        $refused = manual_payment_submit(['id' => $customer, 'organization_id' => null], 'account', 100000, $this->input(1), null);
        $this->assertSame('method_unavailable', $refused['reason']);
    }

    public function testPlatformCardPaymentIsCreditedOnlyAfterApproval(): void
    {
        $customer = $this->makeUser();
        $admin = $this->makeUser(['is_admin' => 1]);
        manual_payment_methods_save(0, ['card'], $admin);
        $card = $this->card(0);

        $submitted = manual_payment_submit(['id' => $customer, 'organization_id' => null], 'card', 100000, $this->input($card), null);
        $this->assertTrue($submitted['ok'], $submitted['reason'] ?? '');
        $this->assertSame(110000, $submitted['total'], 'the customer pays the credit plus 10% VAT');

        $manual = manual_payment_get($submitted['manual_id']);
        $payment = $this->row('ellsms_payments', (int)$manual['payment_id']);
        $invoice = $this->row('ellsms_invoices', $submitted['invoice_id']);
        $this->assertSame('pending', $manual['review_state']);
        $this->assertSame(0, (int)$manual['collector_user_id']);
        $this->assertSame('card', $payment['gateway']);
        $this->assertSame('pending', $payment['status']);
        $this->assertSame(110000, (int)$payment['amount_rial']);
        $this->assertSame('issued', $invoice['status']);
        $this->assertSame(['در انتظار بررسی', 'review'], manual_payment_invoice_status($invoice, $manual));
        $this->assertSame(0, wallet_balance($customer)['available']);

        // The same document reported again is a double submission.
        $this->assertSame('duplicate_document', manual_payment_submit(['id' => $customer, 'organization_id' => null], 'card', 100000, $this->input($card), null)['reason']);
        // The invoice's own "mark paid" does not bypass the review.
        $this->assertSame('manual_review_required', invoice_admin_mark_paid($submitted['invoice_id'], $admin, 'paid')['reason']);
        $this->assertSame('manual_review_required', invoice_admin_set_state($submitted['invoice_id'], 'disabled', $admin, 'x')['reason']);
        // A reseller cannot review a payment made to the platform.
        $this->assertSame('not_found', manual_payment_review($submitted['manual_id'], 'approve', 999, 999)['reason']);

        $this->assertTrue(manual_payment_review($submitted['manual_id'], 'approve', 0, $admin)['ok']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
        $this->assertSame('paid', $this->row('ellsms_payments', (int)$manual['payment_id'])['status']);
        $this->assertSame('paid', $this->row('ellsms_invoices', $submitted['invoice_id'])['status']);
        $this->assertSame('approved', manual_payment_get($submitted['manual_id'])['review_state']);

        // A second approval (double click, second admin) credits nothing more.
        $this->assertSame('already_reviewed', manual_payment_review($submitted['manual_id'], 'approve', 0, $admin)['reason']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
    }

    public function testRejectionCancelsTheInvoiceAndFreesTheDocumentNumber(): void
    {
        $customer = $this->makeUser();
        manual_payment_methods_save(0, ['cash'], 1);
        $input = ['document_number' => 'R-77', 'paid_on' => date('Y-m-d'), 'paid_time' => '00:00', 'description' => 'صندوق'];
        $submitted = manual_payment_submit(['id' => $customer, 'organization_id' => null], 'cash', 200000, $input, null);
        $this->assertTrue($submitted['ok'], $submitted['reason'] ?? '');

        $this->assertSame('note_required', manual_payment_review($submitted['manual_id'], 'reject', 0, 1, ' ')['reason']);
        $this->assertTrue(manual_payment_review($submitted['manual_id'], 'reject', 0, 1, 'رسید یافت نشد')['ok']);

        $manual = manual_payment_get($submitted['manual_id']);
        $this->assertSame('rejected', $manual['review_state']);
        $this->assertSame('رسید یافت نشد', $manual['review_note']);
        $this->assertSame('failed', $this->row('ellsms_payments', (int)$manual['payment_id'])['status']);
        $this->assertSame('cancelled', $this->row('ellsms_invoices', $submitted['invoice_id'])['status']);
        $this->assertSame(0, wallet_balance($customer)['available']);
        $this->assertTrue(manual_payment_submit(['id' => $customer, 'organization_id' => null], 'cash', 200000, $input, null)['ok']);
    }

    public function testAResellersCustomerPaysTheResellerAndApprovalMovesTheResellersCredit(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller');
        $customer = $this->makeUser();
        $this->account($customer, 'normal', $reseller, 1);

        manual_payment_methods_save(0, ['card', 'cash'], 1);
        $this->card(0);
        manual_payment_methods_save($reseller, ['card'], $reseller);
        $resellerCard = $this->card($reseller, 'نماینده');

        $options = manual_payment_options_for($customer);
        $this->assertSame($reseller, $options['card']['collector']);
        $this->assertSame([$resellerCard], array_map('intval', array_column($options['card']['destinations'], 'id')));
        $this->assertSame(0, $options['cash']['collector'], 'a method the reseller does not take falls back to the platform');

        $submitted = manual_payment_submit(['id' => $customer, 'organization_id' => null], 'card', 300000, $this->input($resellerCard), null);
        $this->assertTrue($submitted['ok'], $submitted['reason'] ?? '');
        $this->assertSame(1, manual_payments_pending_count($reseller));
        $this->assertSame('not_found', manual_payment_review($submitted['manual_id'], 'approve', 0, 1)['reason'], 'the platform does not review what the reseller received');

        // The reseller cannot hand out credit it does not have; nothing changes.
        $this->assertSame('insufficient_balance', manual_payment_review($submitted['manual_id'], 'approve', $reseller, $reseller)['reason']);
        $manual = manual_payment_get($submitted['manual_id']);
        $this->assertSame('pending', $manual['review_state']);
        $this->assertSame('pending', $this->row('ellsms_payments', (int)$manual['payment_id'])['status']);
        $this->assertSame('issued', $this->row('ellsms_invoices', $submitted['invoice_id'])['status']);

        wallet_credit($reseller, 500000, 'manual_credit', 'test', 'fund', 'test_fund:' . $reseller);
        $this->assertTrue(manual_payment_review($submitted['manual_id'], 'approve', $reseller, $reseller)['ok']);
        $this->assertSame(200000, wallet_balance($reseller)['available']);
        $this->assertSame(300000, wallet_balance($customer)['available']);
        $this->assertSame('paid', $this->row('ellsms_invoices', $submitted['invoice_id'])['status']);
        $this->assertSame(0, manual_payments_pending_count($reseller));
    }

    public function testDestinationsAreValidatedOwnedAndSoftDeleted(): void
    {
        $this->assertSame('card_number', manual_payment_destination_save(0, null, ['kind' => 'card', 'bank' => 'melli', 'holder_name' => 'الف', 'card_number' => '6037991234567894'], 1)['reason']);
        $this->assertSame('sheba', manual_payment_destination_save(0, null, ['kind' => 'account', 'bank' => 'melli', 'holder_name' => 'الف', 'account_number' => '12345', 'sheba' => 'IR11'], 1)['reason']);
        $account = manual_payment_destination_save(0, null, ['kind' => 'account', 'bank' => 'mellat', 'holder_name' => 'الف', 'account_number' => '0101-2345-6', 'sheba' => 'IR062960000000100324200001', 'active' => '1'], 1);
        $this->assertTrue($account['ok']);

        $this->assertSame('destination_not_found', manual_payment_destination_save(55, $account['id'], ['bank' => 'melli', 'holder_name' => 'ب', 'account_number' => '12345'], 55)['reason']);
        $this->assertFalse(manual_payment_destination_delete(55, $account['id'], 55));
        $this->assertTrue(manual_payment_destination_delete(0, $account['id'], 1));
        $this->assertSame([], manual_payment_destinations(0, 'account'));
        $this->assertSame(1, (int)manual_payment_destination($account['id'])['deleted']);
    }
}
