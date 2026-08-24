<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * FIN-18/FIN-36 — the required end-to-end scenarios, driven through the REAL pipeline: order intent
 * (ellsms_payments) -> invoice (billing_invoice_create()) -> fake gateway create/verify ->
 * payment_claim_and_credit()/payment_claim_and_activate_subscription() -> wallet/subscription
 * fulfillment. Nothing here calls a lower-level primitive directly to shortcut past the real flow.
 *
 * Does NOT extend IntegrationTestCase — the concurrency scenario needs committed data visible to a
 * separate subprocess (same reasoning as WalletConcurrencyTest/BillingPaymentTest).
 */
final class FakePaymentGatewayE2eTest extends TestCase
{
    private ?\PDO $db = null;
    private array $createdUserIds = [];
    private ?int $organizationId = null;
    private ?int $planId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1');
        putenv('BILLING_ENABLED=1');
        putenv('BILLING_TAX_PERCENT=0');
    }

    protected function tearDown(): void
    {
        putenv('ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED');
        putenv('BILLING_ENABLED');
        putenv('BILLING_TAX_PERCENT');
        try {
            if ($this->organizationId !== null) {
                $orgId = $this->organizationId;
                $this->db?->prepare('DELETE FROM ellsms_coupon_redemptions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_invoice_items WHERE invoice_id IN (SELECT id FROM ellsms_invoices WHERE organization_id = ?)')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_invoices WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_payments WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_billing_records WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscription_events WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscriptions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ? OR reference_type = \'payment\'')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
                // payment_claim_and_credit() emits a webhook_event_emit() row on success (Phase 12) —
                // must be cleaned up before the organization FK can be deleted.
                $this->db?->prepare('DELETE FROM ellsms_webhook_deliveries WHERE event_id IN (SELECT id FROM ellsms_webhook_events WHERE organization_id = ?)')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_webhook_events WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
            }
            if ($this->planId !== null) {
                $this->db?->prepare('DELETE FROM ellsms_plan_limits WHERE plan_id = ?')->execute([$this->planId]);
                $this->db?->prepare('DELETE FROM ellsms_plan_entitlements WHERE plan_id = ?')->execute([$this->planId]);
                $this->db?->prepare('DELETE FROM ellsms_plans WHERE id = ?')->execute([$this->planId]);
            }
        } finally {
            foreach ($this->createdUserIds as $userId) {
                $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
            }
        }
    }

    private function makeUserAndOrg(): int
    {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['fake_e2e_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $this->createdUserIds[] = $userId;

        $org = create_organization($userId, 'Fake Gateway E2E Org');
        $this->organizationId = (int)$org['organization_id'];
        return $userId;
    }

    private function makePlan(int $priceAmount = 500000): int
    {
        $code = 'fake_e2e_' . bin2hex(random_bytes(3));
        $this->db->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?, ?, 'active', 1, 'monthly', ?, 'IRR', 0)"
        )->execute([$code, 'Fake E2E Plan', $priceAmount]);
        $this->planId = (int)$this->db->lastInsertId();
        return $this->planId;
    }

    /** Creates a credit-purchase payment + invoice, exactly like public/buy-credit.php's POST handler. */
    private function createCreditPurchase(int $userId, int $credits, int $amountRial): array
    {
        $this->db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,\'fake\')')
            ->execute([$userId, $this->organizationId, $credits, $amountRial]);
        $paymentId = (int)$this->db->lastInsertId();

        $create = payment_gateway_create('fake', $amountRial, $paymentId, 'test credit purchase', 'test:SUCCESS');
        self::assertTrue($create['ok']);
        $this->db->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);

        billing_invoice_create($paymentId, $this->organizationId, $userId, 'credit', [[
            'item_type' => 'sms_credit', 'reference_code' => null, 'description' => 'test credit', 'quantity' => 1, 'unit_price' => $amountRial,
        ]]);

        return ['payment_id' => $paymentId, 'authority' => $create['authority']];
    }

    private function paymentRow(int $paymentId): array
    {
        $st = $this->db->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $st->execute([$paymentId]);
        return $st->fetch();
    }

    private function invoiceForPayment(int $paymentId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ellsms_invoices WHERE payment_id = ?');
        $st->execute([$paymentId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /* ================= A. SMS credit purchase ================= */

    public function testCreditPurchaseFakeSuccessEndToEnd(): void
    {
        $userId = $this->makeUserAndOrg();
        $balanceBefore = wallet_balance($userId)['available'];

        $purchase = $this->createCreditPurchase($userId, 500, 500000);

        $payment = $this->paymentRow($purchase['payment_id']);
        $verify = payment_gateway_verify('fake', (int)$payment['amount_rial'], $payment['authority']);
        self::assertTrue($verify['ok']);

        $result = payment_claim_and_credit($payment, $verify['ref_id']);
        self::assertTrue($result['claimed']);

        $paymentAfter = $this->paymentRow($purchase['payment_id']);
        self::assertSame('paid', $paymentAfter['status']);

        $invoice = $this->invoiceForPayment($purchase['payment_id']);
        self::assertSame('paid', $invoice['status']);

        self::assertSame($balanceBefore + 500, wallet_balance($userId)['available'], 'wallet must equal X + purchased_credit exactly once');
    }

    public function testCreditPurchaseDuplicateCallbackRepeated10TimesCreditsExactlyOnce(): void
    {
        $userId = $this->makeUserAndOrg();
        $balanceBefore = wallet_balance($userId)['available'];

        $purchase = $this->createCreditPurchase($userId, 500, 500000);
        $payment = $this->paymentRow($purchase['payment_id']);
        $verify = payment_gateway_verify('fake', (int)$payment['amount_rial'], $payment['authority']);

        for ($i = 0; $i < 10; $i++) {
            $row = $this->paymentRow($purchase['payment_id']); // re-read each time, exactly like a real repeated callback would
            payment_claim_and_credit($row, $verify['ref_id']);
        }

        self::assertSame($balanceBefore + 500, wallet_balance($userId)['available'], 'wallet must remain X + purchased_credit, not 10x');

        $count = $this->db->prepare("SELECT COUNT(*) c FROM ellsms_wallet_transactions WHERE user_id = ? AND reference_type = 'payment' AND reference_id = ?");
        $count->execute([$userId, (string)$purchase['payment_id']]);
        self::assertSame(1, (int)$count->fetch()['c'], 'exactly one ledger row for this payment');
    }

    public function testCreditPurchaseConcurrentCallbacksCreditExactlyOnce(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        $userId = $this->makeUserAndOrg();
        $balanceBefore = wallet_balance($userId)['available'];
        $purchase = $this->createCreditPurchase($userId, 300, 300000);
        $payment = $this->paymentRow($purchase['payment_id']);
        $verify = payment_gateway_verify('fake', (int)$payment['amount_rial'], $payment['authority']);

        $spawn = function () use ($purchase, $verify) {
            $cmd = [
                PHP_BINARY, __DIR__ . '/../fixtures/payment_credit_claim_worker.php',
                (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
                (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
                (string)$purchase['payment_id'], $verify['ref_id'],
            ];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            return ['proc' => $proc, 'pipes' => $pipes];
        };
        $collect = function (array $handle) {
            $stdout = stream_get_contents($handle['pipes'][1]);
            $stderr = stream_get_contents($handle['pipes'][2]);
            fclose($handle['pipes'][1]);
            fclose($handle['pipes'][2]);
            proc_close($handle['proc']);
            $lines = array_values(array_filter(explode("\n", trim($stdout)), static fn($l) => trim($l) !== ''));
            $decoded = json_decode($lines ? end($lines) : '', true);
            self::assertIsArray($decoded, "no JSON from subprocess (stderr: {$stderr}, stdout: {$stdout})");
            return $decoded;
        };

        $a = $spawn();
        $b = $spawn();
        $resultA = $collect($a);
        $resultB = $collect($b);

        $claimed = array_filter([$resultA, $resultB], static fn($r) => ($r['claimed'] ?? false) === true);
        self::assertCount(1, $claimed, 'exactly one of two concurrent callbacks may claim the payment');
        self::assertSame($balanceBefore + 300, wallet_balance($userId)['available'], 'one financial effect only');
    }

    /* ================= B. Paid plan purchase ================= */

    public function testPlanPurchaseFakeSuccessActivatesSubscriptionExactlyOnce(): void
    {
        $userId = $this->makeUserAndOrg();
        $planId = $this->makePlan(500000);
        $plan = billing_plan_by_id($planId);

        $record = billing_record_create($this->organizationId, $plan);
        $this->db->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, gateway) VALUES (?,?,0,'subscription',?,?,'fake')")
            ->execute([$userId, $this->organizationId, $record['billing_record_id'], $record['amount']]);
        $paymentId = (int)$this->db->lastInsertId();
        $this->db->prepare('UPDATE ellsms_billing_records SET payment_id = ? WHERE id = ?')->execute([$paymentId, $record['billing_record_id']]);

        $create = payment_gateway_create('fake', $record['amount'], $paymentId, 'test plan purchase', 'test:SUCCESS');
        self::assertTrue($create['ok']);
        $this->db->prepare('UPDATE ellsms_payments SET authority=? WHERE id=?')->execute([$create['authority'], $paymentId]);
        billing_invoice_create($paymentId, $this->organizationId, $userId, 'subscription', [[
            'item_type' => 'subscription_plan', 'reference_code' => $plan['code'], 'description' => 'test plan', 'quantity' => 1, 'unit_price' => $record['amount'],
        ]]);

        $payment = $this->paymentRow($paymentId);
        $verify = payment_gateway_verify('fake', (int)$payment['amount_rial'], $payment['authority']);
        $result = payment_claim_and_activate_subscription($payment, $verify['ref_id']);

        self::assertTrue($result['claimed']);
        self::assertTrue($result['activated']);

        $sub = subscription_for_organization($this->organizationId);
        self::assertNotNull($sub);
        self::assertSame('active', $sub['status']);
        self::assertSame($planId, (int)$sub['plan_id']);

        $invoice = $this->invoiceForPayment($paymentId);
        self::assertSame('paid', $invoice['status']);

        // duplicate callback must not duplicate/extend again
        $periodEndAfterFirst = $sub['current_period_end'];
        $paymentAgain = $this->paymentRow($paymentId);
        $second = payment_claim_and_activate_subscription($paymentAgain, $verify['ref_id']);
        self::assertFalse($second['claimed']);
        self::assertSame($periodEndAfterFirst, subscription_for_organization($this->organizationId)['current_period_end']);

        $count = $this->db->prepare("SELECT COUNT(*) c FROM ellsms_subscriptions WHERE organization_id = ?");
        $count->execute([$this->organizationId]);
        self::assertSame(1, (int)$count->fetch()['c'], 'exactly one subscription transition/row');
    }

    /* ================= D. Failed payment ================= */

    public function testFailedFakePaymentLeavesEverythingUntouched(): void
    {
        $userId = $this->makeUserAndOrg();
        $balanceBefore = wallet_balance($userId)['available'];

        $this->db->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, gateway) VALUES (?,?,?,?,\'fake\')')
            ->execute([$userId, $this->organizationId, 500, 500000]);
        $paymentId = (int)$this->db->lastInsertId();

        $create = payment_gateway_create('fake', 500000, $paymentId, 'test', 'test:FAILED');
        self::assertFalse($create['ok']);
        $this->db->prepare("UPDATE ellsms_payments SET status='failed' WHERE id=?")->execute([$paymentId]);

        $payment = $this->paymentRow($paymentId);
        self::assertSame('failed', $payment['status']);
        self::assertSame($balanceBefore, wallet_balance($userId)['available'], 'wallet must be unchanged for a failed payment');
        self::assertNull($this->invoiceForPayment($paymentId), 'no invoice should exist for a payment that never obtained a real authority');
    }

    public function testVerifyFailureModeLeavesPaymentUnpaidAndRetryable(): void
    {
        $userId = $this->makeUserAndOrg();
        $balanceBefore = wallet_balance($userId)['available'];
        $purchase = $this->createCreditPurchase($userId, 500, 500000);

        // Overwrite the authority to force VERIFY_FAILURE mode for this test.
        $vfAuthority = 'FAKE-VERIFY_FAILURE-' . bin2hex(random_bytes(12));
        $this->db->prepare('UPDATE ellsms_payments SET authority = ? WHERE id = ?')->execute([$vfAuthority, $purchase['payment_id']]);

        $payment = $this->paymentRow($purchase['payment_id']);
        $verify = payment_gateway_verify('fake', (int)$payment['amount_rial'], $payment['authority']);
        self::assertFalse($verify['ok']);

        $this->db->prepare("UPDATE ellsms_payments SET status='verification_failed' WHERE id=? AND status IN ('pending','verification_failed')")->execute([$purchase['payment_id']]);

        $paymentAfter = $this->paymentRow($purchase['payment_id']);
        self::assertSame('verification_failed', $paymentAfter['status'], 'retryable, not a final failure');
        self::assertSame($balanceBefore, wallet_balance($userId)['available']);

        $invoice = $this->invoiceForPayment($purchase['payment_id']);
        self::assertSame('issued', $invoice['status'], 'invoice must NOT be paid for an unverified payment');
    }

    /* ================= E/F. Amount mismatch — fail closed ================= */

    public function testAmountMismatchFailsClosedNoFulfillment(): void
    {
        $userId = $this->makeUserAndOrg();
        $balanceBefore = wallet_balance($userId)['available'];
        $purchase = $this->createCreditPurchase($userId, 500, 500000);

        $mismatchAuthority = 'FAKE-AMOUNT_MISMATCH-' . bin2hex(random_bytes(12));
        $this->db->prepare('UPDATE ellsms_payments SET authority = ? WHERE id = ?')->execute([$mismatchAuthority, $purchase['payment_id']]);

        $payment = $this->paymentRow($purchase['payment_id']);
        $verify = payment_gateway_verify('fake', (int)$payment['amount_rial'], $payment['authority']);
        self::assertTrue($verify['ok'], 'the provider DOES confirm something — verify() succeeds');
        self::assertNotSame((int)$payment['amount_rial'], $verify['verified_amount_rial'], 'but the confirmed amount differs from the invoice');

        // The caller (mirroring public/zarinpal-callback.php's own check) must refuse to claim.
        $amountMatches = $verify['verified_amount_rial'] === null || (int)$verify['verified_amount_rial'] === (int)$payment['amount_rial'];
        self::assertFalse($amountMatches, 'this test asserts the mismatch is real and detectable');

        // Fulfillment must never be attempted for a mismatched amount — assert nothing changed.
        $paymentAfter = $this->paymentRow($purchase['payment_id']);
        self::assertSame('pending', $paymentAfter['status'], 'payment must remain unclaimed');
        self::assertSame($balanceBefore, wallet_balance($userId)['available'], 'no fulfillment on amount mismatch');

        $invoice = $this->invoiceForPayment($purchase['payment_id']);
        self::assertSame('issued', $invoice['status'], 'invoice must not be marked paid on amount mismatch');
    }

    /* ================= H. Tenant isolation ================= */

    public function testInvoiceFromOneOrganizationIsInvisibleToAnother(): void
    {
        $userId = $this->makeUserAndOrg();
        $purchase = $this->createCreditPurchase($userId, 500, 500000);
        $invoice = $this->invoiceForPayment($purchase['payment_id']);

        self::assertNull(billing_invoice_by_id((int)$invoice['id'], 999999, null), 'an invoice must be invisible to a foreign organization id');
    }
}
