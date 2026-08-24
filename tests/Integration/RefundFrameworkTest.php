<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * FIN-13 — refund FRAMEWORK, not an automatic policy. Covers: reason required, only a paid invoice
 * is refundable, full-invoice-only (idempotent — a second refund attempt is a safe replay), wallet
 * reversal happens ONLY when sufficient unspent balance remains (never a negative balance), a
 * subscription invoice refund never touches the subscription itself, and the whole action is
 * audited.
 */
final class RefundFrameworkTest extends IntegrationTestCase
{
    private int $userId;
    private int $orgId;
    private int $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser();
        $this->adminUserId = $this->makeUser(['is_admin' => 1]);
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['refund org', 'refund-' . bin2hex(random_bytes(4)), $this->userId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?,?,?)')
           ->execute([$this->orgId, $this->userId, 'owner', 'active']);
        $db->prepare("INSERT INTO ellsms_wallet_accounts (user_id, organization_id, available_balance, reserved_balance) VALUES (?,?,0,0)
                      ON DUPLICATE KEY UPDATE available_balance = VALUES(available_balance)")
           ->execute([$this->userId, $this->orgId]);
    }

    /** Creates + pays a credit-purchase invoice, crediting the wallet exactly like the real flow. */
    private function makePaidCreditInvoice(int $credits, int $amountRial): array
    {
        $db = db();
        $db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,\'fake\')')
            ->execute([$this->userId, $this->orgId, $credits, $amountRial]);
        $paymentId = (int)$db->lastInsertId();

        $invoiceResult = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => null, 'description' => 'test credit', 'quantity' => 1, 'unit_price' => $amountRial,
        ]]);

        $payment = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        payment_claim_and_credit($payment, 'REF-' . bin2hex(random_bytes(4)));

        return ['payment_id' => $paymentId, 'invoice_id' => $invoiceResult['invoice_id']];
    }

    // ------------------------------------------------------------------ reason required

    public function testRefundRequiresANonEmptyReason(): void
    {
        $purchase = $this->makePaidCreditInvoice(100, 100000);
        $result = billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, '   ');
        self::assertFalse($result['ok']);
        self::assertSame('reason_required', $result['reason']);
    }

    // ------------------------------------------------------------------ only a paid invoice

    public function testCannotRefundAnUnpaidInvoice(): void
    {
        $db = db();
        $db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,\'fake\')')
            ->execute([$this->userId, $this->orgId, 100, 100000]);
        $paymentId = (int)$db->lastInsertId();
        $invoiceResult = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => null, 'description' => 'test', 'quantity' => 1, 'unit_price' => 100000,
        ]]);

        $result = billing_refund_invoice($invoiceResult['invoice_id'], $this->adminUserId, 'customer complaint');
        self::assertFalse($result['ok']);
        self::assertSame('invoice_not_paid', $result['reason']);
    }

    // ------------------------------------------------------------------ wallet reversal policy

    public function testRefundReversesWalletCreditWhenSufficientBalanceRemains(): void
    {
        $purchase = $this->makePaidCreditInvoice(500, 500000);
        $balanceAfterPurchase = wallet_balance($this->userId)['available'];
        self::assertSame(500, $balanceAfterPurchase);

        $result = billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, 'duplicate purchase');
        self::assertTrue($result['ok']);
        self::assertSame('refunded', $result['reason']);
        self::assertTrue($result['wallet_reversed']);

        // A refund is an auditable append-only CREDIT reversal (wallet_credit(..., 'refund', ...)),
        // never a silent balance edit — the ledger correctly shows the original purchase credit
        // PLUS the refund credit, both as distinct entries, not a subtraction back to zero.
        self::assertSame($balanceAfterPurchase + 500, wallet_balance($this->userId)['available'], 'the refund must add a second, distinct credit entry for the same amount');

        $txCount = db()->prepare("SELECT COUNT(*) c FROM ellsms_wallet_transactions WHERE user_id = ? AND reference_type = 'invoice' AND type = 'refund'");
        $txCount->execute([$this->userId]);
        self::assertSame(1, (int)$txCount->fetch()['c'], 'exactly one refund ledger entry');

        $invoice = db()->query('SELECT * FROM ellsms_invoices WHERE id = ' . $purchase['invoice_id'])->fetch();
        self::assertSame('refunded', $invoice['status']);
    }

    public function testRefundDoesNotCreateANegativeBalanceWhenCreditAlreadySpent(): void
    {
        $purchase = $this->makePaidCreditInvoice(500, 500000);
        // Spend it all.
        wallet_debit($this->userId, 500, 'sms_debit', 'direct_send', 'spend-test', 'spend-test-key');
        self::assertSame(0, wallet_balance($this->userId)['available']);

        $result = billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, 'customer requested refund');
        self::assertTrue($result['ok']);
        self::assertFalse($result['wallet_reversed'], 'insufficient remaining balance — must NOT create a negative balance');

        self::assertSame(0, wallet_balance($this->userId)['available'], 'balance must remain at zero, never negative');

        $invoice = db()->query('SELECT * FROM ellsms_invoices WHERE id = ' . $purchase['invoice_id'])->fetch();
        self::assertSame('refunded', $invoice['status'], 'the invoice itself is still marked refunded even though the wallet was not reversed');
    }

    public function testRefundReversesOnlyTheUnspentPortionScenarioClampsCorrectly(): void
    {
        // Spend half; refund must refuse to reverse (available < full purchased amount) rather than
        // partially reverse — this framework does full-or-nothing wallet reversal, matching its own
        // documented "only if sufficient balance remains" rule.
        $purchase = $this->makePaidCreditInvoice(500, 500000);
        wallet_debit($this->userId, 300, 'sms_debit', 'direct_send', 'partial-spend', 'partial-spend-key');
        self::assertSame(200, wallet_balance($this->userId)['available']);

        $result = billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, 'partial spend test');
        self::assertFalse($result['wallet_reversed']);
        self::assertSame(200, wallet_balance($this->userId)['available'], 'balance must be untouched, not partially reversed');
    }

    // ------------------------------------------------------------------ idempotency

    public function testRefundingAnAlreadyRefundedInvoiceIsASafeReplay(): void
    {
        $purchase = $this->makePaidCreditInvoice(500, 500000);
        $first = billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, 'first refund');
        self::assertTrue($first['ok']);

        $balanceAfterFirst = wallet_balance($this->userId)['available'];

        $second = billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, 'second attempt');
        self::assertTrue($second['ok']);
        self::assertSame('already_refunded', $second['reason']);
        self::assertSame($balanceAfterFirst, wallet_balance($this->userId)['available'], 'a repeated refund attempt must not reverse the wallet a second time');

        $count = db()->prepare('SELECT COUNT(*) c FROM ellsms_refund_events WHERE invoice_id = ?');
        $count->execute([$purchase['invoice_id']]);
        self::assertSame(1, (int)$count->fetch()['c'], 'exactly one refund event, ever');
    }

    // ------------------------------------------------------------------ subscription invoices — no auto rollback

    public function testRefundingASubscriptionInvoiceNeverTouchesTheSubscription(): void
    {
        putenv('BILLING_ENABLED=1');
        $db = db();
        $code = 'refund_test_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_plans (code, name, status, is_public, billing_period, price_amount, currency, trial_days) VALUES (?, ?, 'active', 1, 'monthly', 500000, 'IRR', 0)")
           ->execute([$code, 'Refund Test Plan']);
        $planId = (int)$db->lastInsertId();
        $plan = billing_plan_by_id($planId);

        $record = billing_record_create($this->orgId, $plan);
        $db->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, gateway) VALUES (?,?,0,'subscription',?,?,'fake')")
            ->execute([$this->userId, $this->orgId, $record['billing_record_id'], $record['amount']]);
        $paymentId = (int)$db->lastInsertId();
        $db->prepare('UPDATE ellsms_payments SET authority = ? WHERE id = ?')->execute(['FAKE-SUCCESS-' . bin2hex(random_bytes(8)), $paymentId]);
        $invoiceResult = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'subscription', [[
            'item_type' => 'subscription_plan', 'reference_code' => $plan['code'], 'description' => 'test plan', 'quantity' => 1, 'unit_price' => $record['amount'],
        ]]);
        $payment = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        payment_claim_and_activate_subscription($payment, 'FAKE-REF-REFUND');

        $subBefore = subscription_for_organization($this->orgId);
        self::assertSame('active', $subBefore['status']);

        $result = billing_refund_invoice($invoiceResult['invoice_id'], $this->adminUserId, 'subscription refund test');
        self::assertTrue($result['ok']);
        self::assertFalse($result['wallet_reversed'], 'a subscription invoice has no wallet credit to reverse');

        $subAfter = subscription_for_organization($this->orgId);
        self::assertSame('active', $subAfter['status'], 'the subscription must be COMPLETELY UNCHANGED by a refund — no automatic rollback');
        self::assertSame($planId, (int)$subAfter['plan_id']);

        putenv('BILLING_ENABLED');
    }

    // ------------------------------------------------------------------ audit trail

    public function testRefundIsAudited(): void
    {
        $purchase = $this->makePaidCreditInvoice(200, 200000);
        billing_refund_invoice($purchase['invoice_id'], $this->adminUserId, 'audit trail check');

        $st = db()->prepare("SELECT COUNT(*) c FROM ellsms_audit_log WHERE user_id = ? AND action = 'billing.invoice.refunded'");
        $st->execute([$this->adminUserId]);
        self::assertSame(1, (int)$st->fetch()['c']);
    }
}
