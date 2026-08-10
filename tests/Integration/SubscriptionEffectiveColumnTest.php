<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TD-070 — `ellsms_subscriptions.effective_organization_id` after its conversion from a STORED
 * GENERATED column to an ordinary application-maintained one
 * (docs/td-070-restore-safety-closure.md).
 *
 * The lifecycle SEMANTICS are unchanged and are already covered by SubscriptionLifecycleTest. What
 * is new — and what this class exists for — is that the value is now written by application code
 * instead of computed by the database. Two things therefore need proving that never needed proving
 * before:
 *
 *   1. the DATABASE still refuses a second effective subscription for one organization, including
 *      through raw SQL that bypasses every helper in app/Billing.php (STEP 6), and
 *   2. EVERY lifecycle transition leaves the column consistent with the row's status (STEP 7) —
 *      a missed call site would be invisible until an organization silently lost its subscription.
 *
 * The restore half of TD-070 lives in RestoreDisasterRecoveryTest, which is where the real
 * backup/DROP/restore cycle already runs.
 */
final class SubscriptionEffectiveColumnTest extends IntegrationTestCase
{
    private int $planId;
    private int $trialPlanId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('BILLING_ENABLED=1');
        $this->planId      = $this->makePlan('td070_' . bin2hex(random_bytes(3)), 'monthly', 1000, 0);
        $this->trialPlanId = $this->makePlan('td070t_' . bin2hex(random_bytes(3)), 'monthly', 1000, 14);
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        parent::tearDown();
    }

    private function makePlan(string $code, string $period, int $price, int $trialDays): int
    {
        db()->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?,?, 'active', 0, 1, ?, ?, 'IRR', ?)"
        )->execute([$code, $code, $period, $price, $trialDays]);
        return (int)db()->lastInsertId();
    }

    /** @return array{organization_id:int, owner_id:int} */
    private function makeOrganization(): array
    {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, 'TD070 Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    private function row(int $subscriptionId): array
    {
        $st = db()->prepare('SELECT id, organization_id, status, effective_organization_id FROM ellsms_subscriptions WHERE id = ?');
        $st->execute([$subscriptionId]);
        $row = $st->fetch();
        $this->assertIsArray($row, "subscription {$subscriptionId} should exist");
        return $row;
    }

    /** The single assertion this whole file is about: the slot always equals its derived value. */
    private function assertSlotConsistent(int $subscriptionId, string $context): void
    {
        $row = $this->row($subscriptionId);
        $expected = billing_effective_organization_id((int)$row['organization_id'], (string)$row['status']);
        $actual = $row['effective_organization_id'] === null ? null : (int)$row['effective_organization_id'];
        $this->assertSame(
            $expected,
            $actual,
            "{$context}: status '{$row['status']}' should imply effective_organization_id " . var_export($expected, true)
        );
    }

    /* ================= The schema itself (STEP 15) ================= */

    public function testTheColumnIsNoLongerAGeneratedColumn(): void
    {
        // The regression guard for TD-070 itself. A generated column here is what made mysqldump
        // produce backups MySQL refuses to reload; if a future schema change reintroduces one, this
        // fails immediately rather than months later during a real recovery.
        $extra = (string)db()->query(
            "SELECT extra FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'ellsms_subscriptions'
               AND column_name = 'effective_organization_id'"
        )->fetchColumn();
        $this->assertStringNotContainsStringIgnoringCase('GENERATED', $extra,
            'effective_organization_id must be an ordinary column — see docs/td-070-restore-safety-closure.md');
    }

    public function testTheUniqueIndexThatIsTheActualGuaranteeStillExists(): void
    {
        $nonUnique = db()->query(
            "SELECT non_unique FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'ellsms_subscriptions'
               AND index_name = 'uniq_effective_subscription'"
        )->fetchColumn();
        $this->assertNotFalse($nonUnique, 'uniq_effective_subscription must exist');
        $this->assertSame(0, (int)$nonUnique, 'uniq_effective_subscription must still be UNIQUE');
    }

    /* ================= Database-level guarantee (STEP 6) ================= */

    public function testRawSqlCannotCreateASecondEffectiveSubscriptionForOneOrganization(): void
    {
        // Deliberately bypasses subscription_create() entirely — a hand-written INSERT of the kind a
        // support script or a migration might contain. The DATABASE must refuse it; nothing in
        // app/Billing.php is involved in this rejection.
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($created['ok']);

        $this->expectException(\PDOException::class);
        db()->prepare(
            "INSERT INTO ellsms_subscriptions (organization_id, plan_id, status, effective_organization_id)
             VALUES (?,?, 'active', ?)"
        )->execute([$org['organization_id'], $this->planId, $org['organization_id']]);
    }

    public function testRawSqlCannotPromoteASecondRowIntoTheEffectiveSlotEither(): void
    {
        // The UPDATE shape of the same attack: an ended subscription being re-marked effective while
        // a live one already holds the slot.
        $org = $this->makeOrganization();
        $first = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($first['ok']);
        subscription_transition($org['organization_id'], 'cancelled', 'cancelled_immediate', $org['owner_id']);
        $second = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($second['ok'], 'a new subscription may be created once the old one ended');

        $this->expectException(\PDOException::class);
        db()->prepare("UPDATE ellsms_subscriptions SET status = 'active', effective_organization_id = ? WHERE id = ?")
            ->execute([$org['organization_id'], (int)$first['subscription_id']]);
    }

    public function testARawInsertThatOmitsTheSlotProducesANonEffectiveRowAndTheIntegrityCheckFlagsIt(): void
    {
        // The honest residual difference from the generated-column era, asserted rather than glossed
        // over: a raw INSERT that omits the column is NOT rejected — it simply creates a row the
        // schema does not consider effective. No lookup returns it, so it cannot silently take over
        // an organization's subscription; it is detectable drift, and the integrity check is what
        // detects it. (A trigger would have closed this too, but creating one requires SUPER while
        // binary logging is on — see docs/td-070-restore-safety-closure.md §Residual.)
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($created['ok']);

        db()->prepare("INSERT INTO ellsms_subscriptions (organization_id, plan_id, status) VALUES (?,?, 'active')")
            ->execute([$org['organization_id'], $this->planId]);
        $smuggledId = (int)db()->lastInsertId();

        $smuggled = $this->row($smuggledId);
        $this->assertNull($smuggled['effective_organization_id'], 'an omitted slot stays NULL — the row is not effective');
        $this->assertSame(
            (int)$created['subscription_id'],
            (int)subscription_for_organization($org['organization_id'])['id'],
            'the effective-subscription lookup must still return the genuine subscription, not the smuggled row'
        );

        // And it is exactly the drift the integrity check exists to surface.
        $expected = billing_effective_organization_id((int)$smuggled['organization_id'], (string)$smuggled['status']);
        $this->assertSame($org['organization_id'], $expected);
        $this->assertNull($smuggled['effective_organization_id'],
            'expected != actual — cron/subscription-integrity-check.php reports this as CRITICAL effective_slot_missing');
    }

    /* ================= Every lifecycle transition (STEP 7) ================= */

    public function testCreateActiveSetsTheSlot(): void
    {
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertSame($org['organization_id'], (int)$this->row((int)$created['subscription_id'])['effective_organization_id']);
        $this->assertSlotConsistent((int)$created['subscription_id'], 'create active');
    }

    public function testTrialToActiveKeepsTheSlot(): void
    {
        $org = $this->makeOrganization();
        $created = subscription_start_trial($org['organization_id'], $this->trialPlanId, $org['owner_id']);
        $this->assertTrue($created['ok']);
        $id = (int)$created['subscription_id'];
        $this->assertSlotConsistent($id, 'trialing');
        $this->assertSame($org['organization_id'], (int)$this->row($id)['effective_organization_id']);

        subscription_transition($org['organization_id'], 'active', 'activated', $org['owner_id']);
        $this->assertSame('active', $this->row($id)['status']);
        $this->assertSlotConsistent($id, 'trialing -> active');
    }

    /**
     * Walks a subscription through every transition the state machine permits, asserting the slot
     * after each one. Table-driven so a newly-permitted transition can be added in one line.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function transitionChains(): iterable
    {
        yield 'active -> past_due -> grace -> active'   => [['past_due', 'grace', 'active']];
        yield 'active -> past_due -> grace -> suspended' => [['past_due', 'grace', 'suspended']];
        yield 'suspended -> active (reactivation)'       => [['past_due', 'grace', 'suspended', 'active']];
        yield 'active -> cancelled -> active (resubscribe)' => [['cancelled', 'active']];
        yield 'active -> expired -> active (resubscribe)'   => [['expired', 'active']];
        yield 'active -> suspended -> cancelled'         => [['suspended', 'cancelled']];
    }

    #[DataProvider('transitionChains')]
    public function testEveryPermittedTransitionLeavesTheSlotConsistent(array $chain): void
    {
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($created['ok']);
        $id = (int)$created['subscription_id'];
        $this->assertSlotConsistent($id, 'initial active');

        foreach ($chain as $step => $toStatus) {
            $result = subscription_transition($org['organization_id'], $toStatus, 'test_' . $toStatus, $org['owner_id'], 'td070:' . $id . ':' . $step . ':' . $toStatus);
            $this->assertTrue($result['ok'], "transition to {$toStatus} should succeed: " . ($result['reason'] ?? ''));
            $this->assertSame($toStatus, $this->row($id)['status']);
            $this->assertSlotConsistent($id, "after -> {$toStatus}");
        }
    }

    public function testAnEndedSubscriptionReleasesTheSlotSoTheOrganizationCanSubscribeAgain(): void
    {
        // This is the behavior the slot exists to produce, expressed as a user-visible outcome
        // rather than as a column value.
        $org = $this->makeOrganization();
        $first = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($first['ok']);

        $blocked = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('already_subscribed', $blocked['reason']);

        subscription_transition($org['organization_id'], 'cancelled', 'cancelled_immediate', $org['owner_id']);
        $this->assertNull($this->row((int)$first['subscription_id'])['effective_organization_id']);

        $again = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertTrue($again['ok'], 'once the slot is released a new subscription may be created');
        $this->assertSlotConsistent((int)$again['subscription_id'], 'resubscribed');
    }

    public function testAnImmediateUpgradeKeepsTheSlotOnTheSameRow(): void
    {
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $id = (int)$created['subscription_id'];
        $upgradePlan = $this->makePlan('td070up_' . bin2hex(random_bytes(3)), 'yearly', 5000, 0);

        $result = subscription_change_plan($org['organization_id'], $upgradePlan, $org['owner_id'], 'immediate');
        $this->assertTrue($result['ok']);
        $this->assertSame($upgradePlan, (int)db()->query("SELECT plan_id FROM ellsms_subscriptions WHERE id = {$id}")->fetchColumn());
        $this->assertSlotConsistent($id, 'immediate upgrade');
        $this->assertSame($org['organization_id'], (int)$this->row($id)['effective_organization_id']);
    }

    public function testAScheduledDowngradeDoesNotTouchTheSlot(): void
    {
        // A downgrade only records pending_plan_id — the status does not change, so neither may the
        // slot. Asserted because the UPDATE for this path deliberately omits the column.
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $id = (int)$created['subscription_id'];
        $before = $this->row($id)['effective_organization_id'];

        $cheaper = $this->makePlan('td070down_' . bin2hex(random_bytes(3)), 'monthly', 100, 0);
        $this->assertTrue(subscription_change_plan($org['organization_id'], $cheaper, $org['owner_id'], 'scheduled')['ok']);

        $this->assertSame($before, $this->row($id)['effective_organization_id']);
        $this->assertSlotConsistent($id, 'scheduled downgrade');
    }

    public function testCancelAtPeriodEndDoesNotReleaseTheSlotEarly(): void
    {
        // The customer keeps what they paid for until the period ends, so the subscription is still
        // effective and must still hold the slot — releasing it here would let a second subscription
        // be created alongside a live one.
        $org = $this->makeOrganization();
        $created = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $id = (int)$created['subscription_id'];

        $this->assertTrue(subscription_cancel($org['organization_id'], $org['owner_id'], false)['ok']);
        $this->assertSame($org['organization_id'], (int)$this->row($id)['effective_organization_id']);
        $this->assertSlotConsistent($id, 'cancel scheduled');

        $blocked = subscription_create($org['organization_id'], $this->planId, 'active', $org['owner_id']);
        $this->assertFalse($blocked['ok'], 'a cancel-at-period-end subscription is still effective');
    }

    /* ================= The derivation helper itself (STEP 3) ================= */

    public function testTheDerivationHelperFailsClosedOnAnUnknownStatus(): void
    {
        // The ENUM makes this unreachable through normal writes; reaching it would mean a widened
        // schema or an invented status, and guessing would silently disable enforcement for that row.
        $this->expectException(\InvalidArgumentException::class);
        billing_effective_organization_id(1, 'gratis');
    }

    public function testTheDerivationHelperAgreesWithTheEffectiveStatusList(): void
    {
        foreach (BILLING_ALL_STATUSES as $status) {
            $expected = in_array($status, BILLING_EFFECTIVE_STATUSES, true) ? 42 : null;
            $this->assertSame($expected, billing_effective_organization_id(42, $status), "status {$status}");
        }
    }

    public function testTheStatusListMatchesTheDatabaseEnumExactly(): void
    {
        // BILLING_ALL_STATUSES is the list the derivation validates against; if the ENUM ever gains a
        // value this constant does not know, every write with that status would throw instead of
        // silently mis-deriving — but it is far better to find out here.
        $columnType = (string)db()->query(
            "SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'ellsms_subscriptions' AND column_name = 'status'"
        )->fetchColumn();
        preg_match_all("/'([^']+)'/", $columnType, $matches);
        sort($matches[1]);
        $known = BILLING_ALL_STATUSES;
        sort($known);
        $this->assertSame($known, $matches[1], 'BILLING_ALL_STATUSES must mirror the status ENUM');
    }
}
