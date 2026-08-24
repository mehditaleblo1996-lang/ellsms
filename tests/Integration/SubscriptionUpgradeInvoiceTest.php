<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * FIN-8 — plan upgrade (an organization that already has an active subscription on one plan pays
 * for a DIFFERENT plan). Uses the EXISTING, unchanged subscription_change_plan()/
 * payment_claim_and_activate_subscription() 'new'/'upgrade'-type activation path — STEP 27's
 * documented rule applies unmodified: immediate, no proration, full period price, no reset of usage
 * counters. This file only proves the invoice layer (FIN-1/FIN-4) integrates correctly with that
 * EXISTING, already-tested upgrade behavior — it does not re-test the upgrade semantics themselves
 * (see tests/Integration/BillingPaymentTest.php for that).
 */
final class SubscriptionUpgradeInvoiceTest extends IntegrationTestCase
{
    private int $userId;
    private int $orgId;
    private int $planLowId;
    private int $planHighId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser();
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['upgrade org', 'upgrade-' . bin2hex(random_bytes(4)), $this->userId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?,?,?)')
           ->execute([$this->orgId, $this->userId, 'owner', 'active']);

        $lowCode = 'upgrade_low_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_plans (code, name, status, is_public, billing_period, price_amount, currency, trial_days) VALUES (?, ?, 'active', 1, 'monthly', 300000, 'IRR', 0)")
           ->execute([$lowCode, 'Upgrade Low Plan']);
        $this->planLowId = (int)$db->lastInsertId();

        $highCode = 'upgrade_high_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_plans (code, name, status, is_public, billing_period, price_amount, currency, trial_days) VALUES (?, ?, 'active', 1, 'monthly', 900000, 'IRR', 0)")
           ->execute([$highCode, 'Upgrade High Plan']);
        $this->planHighId = (int)$db->lastInsertId();

        putenv('BILLING_ENABLED=1');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        parent::tearDown();
    }

    public function testUpgradePaymentCreatesAnInvoiceAndActivatesTheNewPlan(): void
    {
        $created = subscription_create($this->orgId, $this->planLowId, 'active', null, 'self_service', 1);
        self::assertTrue($created['ok']);

        $highPlan = billing_plan_by_id($this->planHighId);
        $record = billing_record_create($this->orgId, $highPlan); // purchase_type defaults to 'new' — matches existing pre-FIN-7 upgrade path exactly

        db()->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, gateway) VALUES (?,?,0,'subscription',?,?,'fake')")
            ->execute([$this->userId, $this->orgId, $record['billing_record_id'], $record['amount']]);
        $paymentId = (int)db()->lastInsertId();
        db()->prepare('UPDATE ellsms_payments SET authority = ? WHERE id = ?')->execute(['FAKE-SUCCESS-' . bin2hex(random_bytes(8)), $paymentId]);

        $invoiceResult = billing_invoice_create($paymentId, $this->orgId, $this->userId, 'subscription', [[
            'item_type' => 'subscription_plan', 'reference_code' => $highPlan['code'],
            'description' => 'ارتقا به پلن بالاتر', 'quantity' => 1, 'unit_price' => $record['amount'],
        ]]);
        self::assertTrue($invoiceResult['ok']);

        $payment = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        $result = payment_claim_and_activate_subscription($payment, 'FAKE-REF-UPGRADE');

        self::assertTrue($result['claimed']);
        self::assertTrue($result['activated']);

        $sub = subscription_for_organization($this->orgId);
        self::assertSame($this->planHighId, (int)$sub['plan_id'], 'the subscription must now be on the HIGHER plan');

        $invoice = db()->query('SELECT * FROM ellsms_invoices WHERE id = ' . $invoiceResult['invoice_id'])->fetch();
        self::assertSame('paid', $invoice['status']);
        self::assertSame($record['amount'], (int)$invoice['total_amount'], 'the invoice charges the full new-plan price, no proration, matching STEP 27');
    }

    public function testUpgradeDuplicateCallbackDoesNotDoubleActivate(): void
    {
        subscription_create($this->orgId, $this->planLowId, 'active', null, 'self_service', 1);
        $highPlan = billing_plan_by_id($this->planHighId);
        $record = billing_record_create($this->orgId, $highPlan);

        db()->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, gateway) VALUES (?,?,0,'subscription',?,?,'fake')")
            ->execute([$this->userId, $this->orgId, $record['billing_record_id'], $record['amount']]);
        $paymentId = (int)db()->lastInsertId();
        db()->prepare('UPDATE ellsms_payments SET authority = ? WHERE id = ?')->execute(['FAKE-SUCCESS-' . bin2hex(random_bytes(8)), $paymentId]);

        $payment = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        $first = payment_claim_and_activate_subscription($payment, 'FAKE-REF-UP2');
        self::assertTrue($first['claimed']);

        $paymentAgain = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        $second = payment_claim_and_activate_subscription($paymentAgain, 'FAKE-REF-UP2');
        self::assertFalse($second['claimed']);

        $count = db()->prepare('SELECT COUNT(*) c FROM ellsms_subscriptions WHERE organization_id = ?');
        $count->execute([$this->orgId]);
        self::assertSame(1, (int)$count->fetch()['c']);
    }
}
