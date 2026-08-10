<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Cost preview against real MySQL — the criteria that only a real database can prove:
 * ZERO mutation of any financial/quota/queue state, preview↔actual parity, recipient eligibility
 * against a real blacklist, wallet and quota preview, and cross-tenant rejection.
 *
 * The zero-mutation test (STEP 35) is the hard acceptance criterion of this feature: it snapshots
 * every table a send would touch, runs every preview surface, and asserts the snapshot is
 * count-for-count and value-for-value identical afterwards.
 */
final class CostPreviewTest extends IntegrationTestCase
{
    private int $ownerId;
    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->makeUser(['originator' => '5000']);
        $result = create_organization($this->ownerId, 'Cost Preview Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 10000 WHERE user_id = ?')->execute([$this->ownerId]);
    }

    private function actor(): array
    {
        return ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => $this->organizationId, 'originator' => '5000'];
    }

    /** Every counter a real send would move. Compared whole, so an unexpected write anywhere fails the test. */
    private function mutationSnapshot(): array
    {
        $q = static fn(string $sql): string => (string)db()->query($sql)->fetch()['v'];
        return [
            'wallet_balance'   => $q('SELECT COALESCE(available_balance,0) v FROM ellsms_wallet_accounts WHERE user_id = ' . $this->ownerId),
            'wallet_reserved'  => $q('SELECT COALESCE(reserved_balance,0) v FROM ellsms_wallet_accounts WHERE user_id = ' . $this->ownerId),
            'ledger_rows'      => $q('SELECT COUNT(*) v FROM ellsms_wallet_transactions'),
            'wallet_res_rows'  => $q('SELECT COUNT(*) v FROM ellsms_wallet_reservations'),
            'usage_counters'   => $q('SELECT COUNT(*) v FROM ellsms_usage_counters'),
            'usage_totals'     => $q('SELECT COALESCE(SUM(used + reserved),0) v FROM ellsms_usage_counters'),
            'usage_res_rows'   => $q('SELECT COUNT(*) v FROM ellsms_usage_reservations'),
            'bulk_jobs'        => $q('SELECT COUNT(*) v FROM ellsms_bulk_jobs'),
            'bulk_items'       => $q('SELECT COUNT(*) v FROM ellsms_bulk_items'),
            'schedules'        => $q('SELECT COUNT(*) v FROM ellsms_schedule'),
            'message_attempts' => $q('SELECT COUNT(*) v FROM ellsms_message_attempts'),
            'api_messages'     => $q('SELECT COUNT(*) v FROM ellsms_api_messages'),
            'campaigns'        => $q('SELECT COUNT(*) v FROM ellsms_campaigns'),
            'audit_rows'       => $q('SELECT COUNT(*) v FROM ellsms_audit_log'),
        ];
    }

    /* ================= STEP 35 — HARD ZERO-MUTATION CRITERION ================= */

    public function testPreviewMutatesAbsolutelyNothing(): void
    {
        putenv('BILLING_ENABLED=1');
        try {
            $before = $this->mutationSnapshot();

            // Every preview surface, including ones that fail.
            estimate_message_cost($this->actor(), '5000', ['989121110001', '989121110002'], str_repeat('س', 150));
            estimate_bulk_cost($this->actor(), '5000', [
                ['mobile' => '989121110003', 'content' => str_repeat('س', 80)],
                ['mobile' => '989121110004', 'content' => 'کوتاه'],
            ]);
            estimate_message_cost($this->actor(), '5000', ['not-a-number'], 'x');           // fails: no eligible
            estimate_message_cost($this->actor(), '9999', ['989121110005'], 'x');           // fails: sender denied
            estimate_message_cost($this->actor(), '5000', ['989121110006'], '');            // fails: empty content
            estimate_campaign_cost($this->actor(), 999999, ['989121110007']);               // fails: no campaign

            $after = $this->mutationSnapshot();
            $this->assertSame($before, $after, 'a cost preview must not change ANY financial, quota, queue, or audit state');
        } finally {
            putenv('BILLING_ENABLED');
        }
    }

    public function testPreviewCreatesNoWalletLedgerEntryEvenWhenCostIsLarge(): void
    {
        $before = $this->mutationSnapshot();
        $recipients = [];
        for ($i = 0; $i < 200; $i++) {
            $recipients[] = '9891222' . str_pad((string)$i, 5, '0', STR_PAD_LEFT);
        }
        $estimate = estimate_message_cost($this->actor(), '5000', $recipients, str_repeat('س', 200));
        $this->assertTrue($estimate['ok']);
        $this->assertGreaterThan(500, $estimate['pricing']['estimated_cost'], 'this should be an expensive estimate');

        $this->assertSame($before, $this->mutationSnapshot());
    }

    /* ================= STEP 36 — PREVIEW vs ACTUAL PARITY ================= */

    public function testPreviewSegmentsAndCostMatchWhatTheSendPathActuallyCharges(): void
    {
        $recipients = ['989123330001', '989123330002', '989123330003'];
        $content = str_repeat('س', 150); // 3 unicode segments

        $estimate = estimate_message_cost($this->actor(), '5000', $recipients, $content);
        $this->assertTrue($estimate['ok']);

        // The send path's own arithmetic, verbatim from dispatch_message():
        //   worstCaseCost = sms_parts(content) * count(destinations)
        $sendPathCost = sms_parts($content) * count($recipients);

        $this->assertSame($sendPathCost, $estimate['pricing']['estimated_cost'],
            'the previewed cost must equal exactly what dispatch_message() will reserve');
        $this->assertSame(sms_parts($content), $estimate['segments']['per_recipient']);
        $this->assertSame($sendPathCost, $estimate['segments']['total']);
    }

    public function testBulkPreviewCostMatchesBulkQueueJobArithmetic(): void
    {
        $items = [
            ['mobile' => '989124440001', 'content' => str_repeat('س', 30)],   // 1 segment
            ['mobile' => '989124440002', 'content' => str_repeat('س', 100)],  // 2 segments
            ['mobile' => '989124440003', 'content' => str_repeat('س', 200)],  // 3 segments
        ];
        $estimate = estimate_bulk_cost($this->actor(), '5000', $items);
        $this->assertTrue($estimate['ok']);

        // bulk_queue_job()'s own loop: $totalCost += sms_parts($it['content'])
        $sendPathCost = 0;
        foreach ($items as $item) {
            $sendPathCost += sms_parts($item['content']);
        }

        $this->assertSame($sendPathCost, $estimate['pricing']['estimated_cost'],
            'the previewed bulk cost must equal exactly what bulk_queue_job() will reserve');
        $this->assertSame(['1' => 1, '2' => 1, '3' => 1], $estimate['segments']['distribution']);
        $this->assertSame(6, $estimate['segments']['total']);
    }

    public function testPreviewAndActualAgreeAcrossAControlledHundredRecipientDataset(): void
    {
        // STEP 36's controlled dataset: 100 personalized recipients, known pricing.
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = [
                'mobile'  => '9891255' . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
                'content' => render_bulk_template('سلام {name}، سفارش {id} شما ثبت شد.', ['name' => str_repeat('ا', $i % 40), 'id' => (string)$i]),
            ];
        }
        $estimate = estimate_bulk_cost($this->actor(), '5000', $items);
        $this->assertTrue($estimate['ok']);
        $this->assertSame(100, $estimate['recipients']['eligible_count']);
        $this->assertTrue($estimate['segments']['exact'], '100 recipients is well under the exact-calculation ceiling');

        $expected = 0;
        foreach ($items as $item) {
            $expected += sms_parts($item['content']);
        }
        $this->assertSame($expected, $estimate['segments']['total']);
        $this->assertSame($expected, $estimate['pricing']['estimated_cost']);
        // The distribution must account for every single recipient, with nothing lost or invented.
        $this->assertSame(100, array_sum($estimate['segments']['distribution']));
    }

    /* ================= Recipient eligibility (STEP 8) ================= */

    public function testEligibilityReportsInvalidDuplicateAndBlacklistedSeparately(): void
    {
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?,?)')->execute([$this->ownerId, '989126660009']);

        $estimate = estimate_message_cost($this->actor(), '5000',
            ['989126660001', '989126660001', 'garbage', '989126660009', '989126660002'], 'test');

        $this->assertTrue($estimate['ok']);
        $this->assertSame(5, $estimate['recipients']['input_count']);
        $this->assertSame(1, $estimate['recipients']['invalid_count']);
        $this->assertSame(1, $estimate['recipients']['duplicate_count']);
        $this->assertSame(1, $estimate['recipients']['blacklisted_count']);
        $this->assertSame(2, $estimate['recipients']['eligible_count']);
    }

    public function testAllRecipientsSuppressedIsReportedNotPriced(): void
    {
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?,?)')->execute([$this->ownerId, '989127770001']);
        $estimate = estimate_message_cost($this->actor(), '5000', ['989127770001'], 'test');
        $this->assertFalse($estimate['ok']);
        $this->assertSame('no_eligible_recipients', $estimate['reason']);
    }

    public function testARawTextareaStringIsParsedIdenticallyToTheSendForm(): void
    {
        $raw = "989128880001\n989128880002, 989128880001; garbage";
        $estimate = estimate_message_cost($this->actor(), '5000', $raw, 'test');
        $this->assertTrue($estimate['ok']);
        $this->assertSame(2, $estimate['recipients']['eligible_count']);
        // parse_destinations() is what the send form uses — same result, proving one parsing path.
        $this->assertSame(count(parse_destinations($raw)), $estimate['recipients']['eligible_count']);
    }

    /* ================= Wallet preview (STEP 10) ================= */

    public function testWalletPreviewUsesTheLedgerAndComputesRemaining(): void
    {
        $estimate = estimate_message_cost($this->actor(), '5000', ['989129990001', '989129990002'], str_repeat('س', 150));
        $this->assertTrue($estimate['ok']);

        $cost = $estimate['pricing']['estimated_cost'];
        $this->assertSame(10000, $estimate['wallet']['balance'], 'balance comes from wallet_balance(), not currentcredit');
        $this->assertSame(10000 - $cost, $estimate['wallet']['estimated_remaining']);
        $this->assertTrue($estimate['wallet']['sufficient']);
    }

    public function testInsufficientBalanceIsReportedButStillPriced(): void
    {
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 1 WHERE user_id = ?')->execute([$this->ownerId]);
        $estimate = estimate_message_cost($this->actor(), '5000', ['989130000001', '989130000002'], str_repeat('س', 150));

        $this->assertTrue($estimate['ok'], 'an unaffordable send still produces a valid estimate — that is the point of a preview');
        $this->assertFalse($estimate['wallet']['sufficient']);
        $this->assertLessThan(0, $estimate['wallet']['estimated_remaining']);
    }

    /* ================= Quota preview (STEP 11) ================= */

    public function testQuotaPreviewReportsRemainingWithoutConsumingIt(): void
    {
        putenv('BILLING_ENABLED=1');
        try {
            $planId = $this->makePlanWithMessageLimit(100);
            subscription_create($this->organizationId, $planId, 'active', $this->ownerId);

            $usageBefore = organization_usage($this->organizationId, \Limits::MONTHLY_MESSAGES);
            $estimate = estimate_message_cost($this->actor(), '5000', ['989131110001', '989131110002'], 'test');

            $this->assertTrue($estimate['quota']['enforced']);
            $this->assertSame(2, $estimate['quota']['estimated_usage']);
            $this->assertSame(100, $estimate['quota']['monthly']['remaining']);
            $this->assertTrue($estimate['quota']['sufficient']);

            $usageAfter = organization_usage($this->organizationId, \Limits::MONTHLY_MESSAGES);
            $this->assertSame($usageBefore, $usageAfter, 'previewing must not consume a single unit of quota');
        } finally {
            putenv('BILLING_ENABLED');
        }
    }

    public function testQuotaInsufficiencyIsReported(): void
    {
        putenv('BILLING_ENABLED=1');
        try {
            $planId = $this->makePlanWithMessageLimit(1);
            subscription_create($this->organizationId, $planId, 'active', $this->ownerId);

            $estimate = estimate_message_cost($this->actor(), '5000', ['989132220001', '989132220002', '989132220003'], 'test');
            $this->assertTrue($estimate['ok']);
            $this->assertFalse($estimate['quota']['sufficient'], '3 recipients against a 1-message allowance is not sufficient');
        } finally {
            putenv('BILLING_ENABLED');
        }
    }

    public function testQuotaIsReportedUnenforcedWhenBillingIsDisabled(): void
    {
        putenv('BILLING_ENABLED=0');
        $estimate = estimate_message_cost($this->actor(), '5000', ['989133330001'], 'test');
        $this->assertFalse($estimate['quota']['enforced']);
        $this->assertTrue($estimate['quota']['sufficient']);
    }

    private function makePlanWithMessageLimit(int $limit): int
    {
        $code = 'cp_' . bin2hex(random_bytes(4));
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

    /* ================= Sender validation & cross-tenant (STEP 12/31) ================= */

    public function testAForeignSenderCannotBePriced(): void
    {
        // A number assigned to a DIFFERENT organization must not be usable for a preview — the
        // estimator defers to can_use_originator(), the same rule the send path enforces.
        $otherOwner = $this->makeUser();
        $otherOrg = create_organization($otherOwner, 'Other Org ' . bin2hex(random_bytes(3)));
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id, organization_id) VALUES (?,?,?,?)')
            ->execute(['77771', 'foreign', $otherOwner, (int)$otherOrg['organization_id']]);

        $estimate = estimate_message_cost($this->actor(), '77771', ['989134440001'], 'test');
        $this->assertFalse($estimate['ok']);
        $this->assertSame('sender_not_allowed', $estimate['reason']);
    }

    public function testAForeignCampaignCannotBePreviewed(): void
    {
        $otherOwner = $this->makeUser();
        $otherOrg = create_organization($otherOwner, 'Other Campaign Org ' . bin2hex(random_bytes(3)));
        db()->prepare('INSERT INTO ellsms_campaigns (user_id, organization_id, name, originator, content) VALUES (?,?,?,?,?)')
            ->execute([$otherOwner, (int)$otherOrg['organization_id'], 'Foreign', '5000', 'secret body']);
        $foreignCampaignId = (int)db()->lastInsertId();

        $estimate = estimate_campaign_cost($this->actor(), $foreignCampaignId, ['989135550001']);
        $this->assertFalse($estimate['ok']);
        $this->assertSame('campaign_not_found', $estimate['reason'], 'a foreign campaign must be indistinguishable from a missing one');
    }

    public function testOwnCampaignCanBePreviewedAndUsesItsStoredBody(): void
    {
        $body = str_repeat('س', 100); // 2 segments
        db()->prepare('INSERT INTO ellsms_campaigns (user_id, organization_id, name, originator, content) VALUES (?,?,?,?,?)')
            ->execute([$this->ownerId, $this->organizationId, 'Mine', '5000', $body]);
        $campaignId = (int)db()->lastInsertId();

        $estimate = estimate_campaign_cost($this->actor(), $campaignId, ['989136660001', '989136660002']);
        $this->assertTrue($estimate['ok']);
        $this->assertSame('campaign', $estimate['kind']);
        $this->assertSame($campaignId, $estimate['campaign']['id']);
        $this->assertSame(sms_parts($body) * 2, $estimate['pricing']['estimated_cost']);
    }

    public function testWalletPreviewIsScopedToTheActingUser(): void
    {
        // Another user's balance must never leak into this organization's estimate.
        $otherOwner = $this->makeUser();
        create_organization($otherOwner, 'Balance Org ' . bin2hex(random_bytes(3)));
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 999999 WHERE user_id = ?')->execute([$otherOwner]);

        $estimate = estimate_message_cost($this->actor(), '5000', ['989137770001'], 'test');
        $this->assertSame(10000, $estimate['wallet']['balance'], 'the estimate must report THIS actor\'s balance, not another\'s');
    }

    /* ================= Admin exemption ================= */

    public function testAdminPreviewShowsZeroCost(): void
    {
        $admin = ['id' => $this->ownerId, 'role' => 'admin', 'organization_id' => $this->organizationId, 'originator' => '5000'];
        $estimate = estimate_message_cost($admin, '5000', ['989138880001', '989138880002'], str_repeat('س', 150));
        $this->assertTrue($estimate['ok']);
        $this->assertSame(0, $estimate['pricing']['estimated_cost'], 'dispatch_message() does not charge an admin, so the preview must not either');
        $this->assertGreaterThan(0, $estimate['segments']['total'], 'segments are still counted and shown');
    }
}
