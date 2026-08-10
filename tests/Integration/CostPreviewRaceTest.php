<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Cost preview — STEP 23/24's hard race criteria: a preview that said "sufficient" must NEVER be
 * able to force a send through once the balance or quota is actually gone.
 *
 * This is the whole reason a preview is deliberately NOT a reservation (Invariant G): it observes,
 * it does not hold. The protection lives entirely in the send path, which re-checks atomically
 * under a row lock — these tests prove that protection survives a preview.
 */
final class CostPreviewRaceTest extends IntegrationTestCase
{
    private int $ownerId;
    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->makeUser(['originator' => '5000']);
        $result = create_organization($this->ownerId, 'Race Org ' . bin2hex(random_bytes(3)));
        $this->organizationId = (int)$result['organization_id'];
    }

    private function actor(): array
    {
        return ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => $this->organizationId, 'originator' => '5000'];
    }

    /* ================= STEP 23 — BALANCE RACE ================= */

    public function testASendIsRefusedWhenTheBalanceIsSpentAfterAFavourablePreview(): void
    {
        // Balance 100. Preview an 80-credit send -> "sufficient". Something else then spends 50.
        // Confirming must fail safely, with no negative balance and no partial charge.
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 100 WHERE user_id = ?')->execute([$this->ownerId]);

        $content = str_repeat('س', 60);              // 1 unicode segment
        $recipients = [];
        for ($i = 0; $i < 80; $i++) {                 // 80 recipients x 1 segment = 80 credits
            $recipients[] = '98914' . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
        }

        $preview = estimate_message_cost($this->actor(), '5000', $recipients, $content);
        $this->assertTrue($preview['ok']);
        $this->assertSame(80, $preview['pricing']['estimated_cost']);
        $this->assertTrue($preview['wallet']['sufficient'], 'at preview time the balance genuinely was enough');

        // A concurrent operation spends 50 credits.
        $spend = wallet_debit($this->ownerId, 50, 'sms_debit', 'test_race', 'race-1', 'race-debit-1');
        $this->assertTrue($spend['ok']);
        $this->assertSame(50, wallet_balance($this->ownerId)['available']);

        // Confirmation. dispatch_message() re-reserves under a row lock and must refuse.
        [$ok, $info] = dispatch_message($this->actor(), '5000', $recipients, $content);

        $this->assertFalse($ok, 'the send must fail — the preview was an estimate, not a reservation');
        $this->assertStringContainsString('اعتبار', $info, 'and must say so as an insufficient-credit failure');

        $balance = wallet_balance($this->ownerId);
        $this->assertSame(50, $balance['available'], 'balance must be untouched by the refused send');
        $this->assertGreaterThanOrEqual(0, $balance['available'], 'the wallet must never go negative');
        $this->assertSame(0, $balance['reserved'], 'no reservation may be left dangling');
    }

    public function testNoPartialChargeOccursWhenTheBalanceRaceIsLost(): void
    {
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 100 WHERE user_id = ?')->execute([$this->ownerId]);
        $recipients = [];
        for ($i = 0; $i < 80; $i++) {
            $recipients[] = '98915' . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
        }
        wallet_debit($this->ownerId, 60, 'sms_debit', 'test_race', 'race-2', 'race-debit-2');
        $ledgerBefore = (int)db()->query('SELECT COUNT(*) c FROM ellsms_wallet_transactions')->fetch()['c'];

        dispatch_message($this->actor(), '5000', $recipients, str_repeat('س', 60));

        $ledgerAfter = (int)db()->query('SELECT COUNT(*) c FROM ellsms_wallet_transactions')->fetch()['c'];
        $this->assertSame($ledgerBefore, $ledgerAfter, 'a refused send must write no ledger entry at all — not even a partial one');
    }

    /* ================= STEP 24 — QUOTA RACE ================= */

    public function testASendIsRefusedWhenQuotaIsConsumedAfterAFavourablePreview(): void
    {
        putenv('BILLING_ENABLED=1');
        try {
            // Allowance 100. Preview an 80-message send -> "sufficient". Something else consumes 50.
            $planId = $this->makePlanWithMessageLimit(100);
            subscription_create($this->organizationId, $planId, 'active', $this->ownerId);
            db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 100000 WHERE user_id = ?')->execute([$this->ownerId]);

            $recipients = [];
            for ($i = 0; $i < 80; $i++) {
                $recipients[] = '98916' . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
            }

            $preview = estimate_message_cost($this->actor(), '5000', $recipients, 'کوتاه');
            $this->assertTrue($preview['ok']);
            $this->assertSame(80, $preview['quota']['estimated_usage']);
            $this->assertTrue($preview['quota']['sufficient'], 'at preview time the quota genuinely was enough');

            // A concurrent operation consumes 50 messages of the allowance.
            $consume = usage_reserve_messages($this->organizationId, 50, 'test_race', 'quota-race-1');
            $this->assertTrue($consume['ok']);
            usage_commit_messages('test_race', 'quota-race-1', 50);
            $this->assertSame(50, organization_remaining_quota($this->organizationId, \Limits::MONTHLY_MESSAGES));

            // Confirmation — dispatch_message() reserves quota atomically and must refuse before
            // reserving any money or dispatching anything.
            [$ok, $info] = dispatch_message($this->actor(), '5000', $recipients, 'کوتاه');

            $this->assertFalse($ok, 'the send must fail — 80 no longer fits in the remaining 50');
            $this->assertStringContainsString('سقف', $info, 'and must report it as a quota exhaustion');

            $usage = organization_usage($this->organizationId, \Limits::MONTHLY_MESSAGES);
            $this->assertSame(50, $usage['used'], 'only the concurrent operation\'s usage may be recorded');
            $this->assertSame(0, $usage['reserved'], 'the refused send must leave no reservation behind');
            $this->assertSame(100000, wallet_balance($this->ownerId)['available'], 'and must not have charged anything');
        } finally {
            putenv('BILLING_ENABLED');
        }
    }

    public function testAnOverQuotaBulkJobIsRefusedAndCommitsNoQuotaOrItems(): void
    {
        // The job-row ROLLBACK itself is asserted in CostPreviewBulkRollbackTest, which runs
        // without this class's ambient test transaction — db_transaction() deliberately JOINS an
        // already-open transaction rather than nesting (see its docblock), so an inner rollback
        // cannot fire while IntegrationTestCase holds one open. What IS provable here, and is the
        // part that matters for the race, is that the call is refused, no items are written, and
        // no quota or money is consumed.
        putenv('BILLING_ENABLED=1');
        try {
            $planId = $this->makePlanWithMessageLimit(10);
            subscription_create($this->organizationId, $planId, 'active', $this->ownerId);
            db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 100000 WHERE user_id = ?')->execute([$this->ownerId]);

            $items = [];
            for ($i = 0; $i < 20; $i++) {
                $items[] = ['mobile' => '98917' . str_pad((string)$i, 7, '0', STR_PAD_LEFT), 'content' => 'کوتاه'];
            }
            $itemsBefore = (int)db()->query('SELECT COUNT(*) c FROM ellsms_bulk_items')->fetch()['c'];

            [$ok, , $jobId, $reason] = bulk_queue_job($this->actor(), 'p2p', 'Race Job', '5000', null, $items);

            $this->assertFalse($ok, '20 messages must not fit in a 10-message allowance');
            $this->assertSame('quota_exceeded', $reason);
            $this->assertNull($jobId, 'no usable job id may be handed back');
            $this->assertSame($itemsBefore, (int)db()->query('SELECT COUNT(*) c FROM ellsms_bulk_items')->fetch()['c'],
                'not a single bulk item may be written for a refused job');

            $usage = organization_usage($this->organizationId, \Limits::MONTHLY_MESSAGES);
            $this->assertSame(0, $usage['used'], 'a refused job must consume no quota');
            $this->assertSame(0, $usage['reserved']);
            $this->assertSame(100000, wallet_balance($this->ownerId)['available'], 'and no money');
        } finally {
            putenv('BILLING_ENABLED');
        }
    }

    /* ================= Preview does not authorize anything ================= */

    public function testAPreviewGrantsNoStandingPermissionToSend(): void
    {
        // Previewing an unaffordable send is fine; confirming it must still fail. A preview must
        // never function as an approval token (Invariant K/G).
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 1 WHERE user_id = ?')->execute([$this->ownerId]);
        $recipients = ['989180000001', '989180000002'];
        $content = str_repeat('س', 150);

        $preview = estimate_message_cost($this->actor(), '5000', $recipients, $content);
        $this->assertTrue($preview['ok']);
        $this->assertFalse($preview['wallet']['sufficient']);

        [$ok] = dispatch_message($this->actor(), '5000', $recipients, $content);
        $this->assertFalse($ok);
        $this->assertSame(1, wallet_balance($this->ownerId)['available']);
    }

    private function makePlanWithMessageLimit(int $limit): int
    {
        $code = 'race_' . bin2hex(random_bytes(4));
        db()->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days) VALUES (?,?, 'active',0,1,'none',0,'IRR',0)")
            ->execute([$code, $code]);
        $planId = (int)db()->lastInsertId();
        $ent = db()->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)');
        foreach (\Entitlements::all() as $key) {
            $ent->execute([$planId, $key]);
        }
        $lim = db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,?,\'hard\')');
        $lim->execute([$planId, \Limits::MONTHLY_MESSAGES, $limit, 'monthly']);
        $lim->execute([$planId, \Limits::DAILY_MESSAGES, $limit, 'daily']);
        return $planId;
    }
}
