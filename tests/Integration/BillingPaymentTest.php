<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 13 (STEP 31/32/34/53) — subscription payment integrity against real MySQL: the amount is
 * always server-derived, a duplicate callback activates once, and two genuinely concurrent
 * activations of the same payment produce exactly one subscription extension.
 *
 * Does NOT extend IntegrationTestCase — the concurrency test needs committed data visible to
 * separate subprocesses (same reasoning as WalletConcurrencyTest).
 */
final class BillingPaymentTest extends TestCase
{
    private ?\PDO $db = null;
    private array $createdUserIds = [];
    private ?int $organizationId = null;
    private ?int $planId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
        putenv('BILLING_ENABLED=1');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        try {
            if ($this->organizationId !== null) {
                $orgId = $this->organizationId;
                $this->db?->prepare('DELETE FROM ellsms_payments WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_billing_records WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscription_events WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscriptions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
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
                $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
            }
        }
    }

    /** @return array{organization_id:int, owner_id:int, plan:array} */
    private function makeCommittedOrgAndPaidPlan(int $price = 5000000): array
    {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['billpay_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $this->createdUserIds[] = $userId;

        $org = create_organization($userId, 'Billing Payment Org');
        $this->organizationId = (int)$org['organization_id'];

        $code = 'pay_' . bin2hex(random_bytes(4));
        $this->db->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?,?, 'active', 0, 1, 'monthly', ?, 'IRR', 0)"
        )->execute([$code, $code, $price]);
        $this->planId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)')
            ->execute([$this->planId, \Entitlements::PUBLIC_API]);

        return ['organization_id' => $this->organizationId, 'owner_id' => $userId, 'plan' => billing_plan_by_id($this->planId)];
    }

    /** Creates a committed pending subscription payment + its billing record, the way public/billing.php does. */
    private function makePendingSubscriptionPayment(array $ctx): array
    {
        $record = billing_record_create($ctx['organization_id'], $ctx['plan']);
        $this->db->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, authority) VALUES (?,?,0,'subscription',?,?,?)")
            ->execute([$ctx['owner_id'], $ctx['organization_id'], $record['billing_record_id'], $record['amount'], 'AUTH' . bin2hex(random_bytes(6))]);
        $paymentId = (int)$this->db->lastInsertId();
        $this->db->prepare('UPDATE ellsms_billing_records SET payment_id = ? WHERE id = ?')->execute([$paymentId, $record['billing_record_id']]);
        return ['payment_id' => $paymentId, 'billing_record_id' => $record['billing_record_id'], 'amount' => $record['amount']];
    }

    /* ================= Amount snapshot (STEP 31) ================= */

    public function testBillingRecordAmountComesFromThePlanNotFromInput(): void
    {
        $ctx = $this->makeCommittedOrgAndPaidPlan(7777000);
        $record = billing_record_create($ctx['organization_id'], $ctx['plan']);
        $this->assertSame(7777000, $record['amount'], 'the amount must be read from the plan row');

        $row = $this->db->query('SELECT * FROM ellsms_billing_records WHERE id = ' . (int)$record['billing_record_id'])->fetch();
        $this->assertSame('7777000', (string)$row['amount']);
        $this->assertSame($ctx['plan']['code'], $row['plan_code'], 'the plan code is snapshotted for historical accuracy');
        $this->assertSame('monthly', $row['billing_period']);
    }

    public function testHistoricalAmountSurvivesAPlanPriceChange(): void
    {
        // STEP 31: "do not calculate historical payments using current plan price."
        $ctx = $this->makeCommittedOrgAndPaidPlan(1000000);
        $record = billing_record_create($ctx['organization_id'], $ctx['plan']);

        $this->db->prepare('UPDATE ellsms_plans SET price_amount = 9999999 WHERE id = ?')->execute([$this->planId]);

        $row = $this->db->query('SELECT amount FROM ellsms_billing_records WHERE id = ' . (int)$record['billing_record_id'])->fetch();
        $this->assertSame('1000000', (string)$row['amount'], 'the historical charge must not follow the plan\'s new price');
    }

    /* ================= Activation + duplicate callback (STEP 32/34) ================= */

    public function testActivationCreatesASubscriptionAndMarksTheRecordPaid(): void
    {
        $ctx = $this->makeCommittedOrgAndPaidPlan();
        $payment = $this->makePendingSubscriptionPayment($ctx);

        $row = $this->db->query('SELECT * FROM ellsms_payments WHERE id = ' . $payment['payment_id'])->fetch();
        $result = payment_claim_and_activate_subscription($row, 'REF123');

        $this->assertTrue($result['claimed']);
        $this->assertTrue($result['activated']);

        $sub = subscription_for_organization($ctx['organization_id']);
        $this->assertNotNull($sub);
        $this->assertSame('active', $sub['status']);
        $this->assertSame($this->planId, (int)$sub['plan_id']);

        $record = $this->db->query('SELECT * FROM ellsms_billing_records WHERE id = ' . $payment['billing_record_id'])->fetch();
        $this->assertSame('paid', $record['status']);
        $this->assertNotNull($record['subscription_id']);
        $this->assertNotNull($record['paid_at']);
    }

    public function testDuplicateCallbackDoesNotActivateTwiceOrExtendThePeriodAgain(): void
    {
        $ctx = $this->makeCommittedOrgAndPaidPlan();
        $payment = $this->makePendingSubscriptionPayment($ctx);
        $row = $this->db->query('SELECT * FROM ellsms_payments WHERE id = ' . $payment['payment_id'])->fetch();

        $first = payment_claim_and_activate_subscription($row, 'REF123');
        $this->assertTrue($first['claimed']);
        $periodEndAfterFirst = subscription_for_organization($ctx['organization_id'])['current_period_end'];

        // Re-read the row (it is now 'paid') and replay the callback, exactly as a ZarinPal retry
        // or a refreshed browser tab would.
        $rowAgain = $this->db->query('SELECT * FROM ellsms_payments WHERE id = ' . $payment['payment_id'])->fetch();
        $second = payment_claim_and_activate_subscription($rowAgain, 'REF123');

        $this->assertFalse($second['claimed'], 'a duplicate callback must not re-claim the payment');
        $this->assertFalse($second['activated']);

        $this->assertSame($periodEndAfterFirst, subscription_for_organization($ctx['organization_id'])['current_period_end'],
            'the period must not be extended a second time');
        $this->assertSame(1, (int)$this->db->query(
            "SELECT COUNT(*) c FROM ellsms_subscription_events WHERE organization_id = {$ctx['organization_id']} AND event_type = 'activated_by_payment'"
        )->fetch()['c'], 'exactly one activation event');
    }

    public function testSubscriptionPaymentNeverCreditsTheWallet(): void
    {
        // STEP 33 — the two payment purposes must never both fire for one payment.
        $ctx = $this->makeCommittedOrgAndPaidPlan();
        $payment = $this->makePendingSubscriptionPayment($ctx);
        $balanceBefore = wallet_balance($ctx['owner_id'])['available'];

        $row = $this->db->query('SELECT * FROM ellsms_payments WHERE id = ' . $payment['payment_id'])->fetch();
        payment_claim_and_activate_subscription($row, 'REF123');

        $this->assertSame($balanceBefore, wallet_balance($ctx['owner_id'])['available'],
            'a subscription charge must not credit SMS wallet balance');
    }

    public function testActivationRefusesWhenTheBillingRecordBelongsToAnotherOrganization(): void
    {
        // STEP 50/51 — ownership is re-verified inside the transaction, so a mismatched pairing
        // cannot activate a subscription paid for by someone else's transaction. Uses a genuinely
        // EXISTING second organization: the FK already makes a wholly-fabricated organization_id
        // impossible (verified while writing this test), so the realistic residual risk is a
        // mismatch between two real tenants, which is what this actually exercises.
        $ctx = $this->makeCommittedOrgAndPaidPlan();
        $payment = $this->makePendingSubscriptionPayment($ctx);

        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['billpay_other_' . bin2hex(random_bytes(4))]);
        $otherUserId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$otherUserId, '']);
        $this->createdUserIds[] = $otherUserId;
        $otherOrg = create_organization($otherUserId, 'Billing Payment Other Org');
        $otherOrgId = (int)$otherOrg['organization_id'];

        try {
            $this->db->prepare('UPDATE ellsms_billing_records SET organization_id = ? WHERE id = ?')
                ->execute([$otherOrgId, $payment['billing_record_id']]);

            $row = $this->db->query('SELECT * FROM ellsms_payments WHERE id = ' . $payment['payment_id'])->fetch();
            $result = payment_claim_and_activate_subscription($row, 'REF123');

            $this->assertTrue($result['claimed'], 'the payment itself is still claimed (money did move)');
            $this->assertFalse($result['activated'], 'but no subscription may be activated on a mismatched record');
            $this->assertSame('organization_mismatch', $result['reason']);
            $this->assertNull(subscription_for_organization($ctx['organization_id']));
            $this->assertNull(subscription_for_organization($otherOrgId), 'and certainly not for the OTHER organization either');
        } finally {
            // Restore the record to its own organization so tearDown's org-scoped cleanup finds it,
            // then remove the second organization this test created.
            $this->db->prepare('UPDATE ellsms_billing_records SET organization_id = ? WHERE id = ?')
                ->execute([$ctx['organization_id'], $payment['billing_record_id']]);
            $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$otherOrgId]);
            $this->db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$otherOrgId]);
            $this->db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$otherOrgId]);
        }
    }

    /* ================= Concurrent activation (STEP 53) ================= */

    public function testConcurrentActivationsOfTheSamePaymentActivateExactlyOnce(): void
    {
        $ctx = $this->makeCommittedOrgAndPaidPlan();
        $payment = $this->makePendingSubscriptionPayment($ctx);

        $spawn = function () use ($payment) {
            $cmd = [
                PHP_BINARY, __DIR__ . '/../fixtures/subscription_activation_worker.php',
                (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
                (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
                (string)$payment['payment_id'], 'REF-CONCURRENT',
            ];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($proc);
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
            $this->assertIsArray($decoded, "no JSON from subprocess (stderr: {$stderr}, stdout: {$stdout})");
            return $decoded;
        };

        $a = $spawn();
        $b = $spawn();
        $resultA = $collect($a);
        $resultB = $collect($b);

        $claimed = array_filter([$resultA, $resultB], static fn($r) => ($r['claimed'] ?? false) === true);
        $this->assertCount(1, $claimed, 'exactly one of two concurrent activations may claim the payment');

        $this->assertSame(1, (int)$this->db->query(
            "SELECT COUNT(*) c FROM ellsms_subscriptions WHERE organization_id = {$ctx['organization_id']}"
        )->fetch()['c'], 'exactly one subscription row');
        $this->assertSame(1, (int)$this->db->query(
            "SELECT COUNT(*) c FROM ellsms_subscription_events WHERE organization_id = {$ctx['organization_id']} AND event_type = 'activated_by_payment'"
        )->fetch()['c'], 'exactly one activation event — no duplicate period extension');
        $this->assertSame(1, (int)$this->db->query(
            "SELECT COUNT(*) c FROM ellsms_billing_records WHERE organization_id = {$ctx['organization_id']} AND status = 'paid'"
        )->fetch()['c'], 'exactly one paid billing record');
    }
}
