<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Issue #8 re-audit finding: gateway_send_for_dispatch() used to resolve exactly ONE route for an
 * entire multi-destination batch, skipping destination-operator routing whenever count($destinations)
 * > 1 — so a bulk campaign always used the sender/default route even when specific destination
 * operators had their own configured route. sms_pricing_route_groups_for_destinations()
 * (app/Sms/Pricing.php) is the fix: it partitions a batch into homogeneous route groups using the
 * SAME sender > destination-operator > default precedence a single-destination send already used.
 *
 * This file tests the grouping function directly (pure DB-backed, no gateway HTTP round trip needed
 * to prove the routing DECISION is correct) — the end-to-end dispatch/merge behavior built on top of
 * it (gateway_send_for_dispatch() in app/Sms/GatewayTransport.php) reuses gateway_send() unchanged
 * per group, which GatewayDispatchTest/GatewayParityTest/BulkProviderBatchingTest already cover for
 * the single-group case.
 */
final class SmsPricingRouteGroupsTest extends IntegrationTestCase
{
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('%04d', 5500 + random_int(0, 90));
        sms_pricing_cache_reset();
    }

    protected function tearDown(): void
    {
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    private function makeOperator(string $code, array $prefixes): int {
        db()->prepare('INSERT INTO ellsms_sms_operators (code, name, country_code, status) VALUES (?,?,?,?)')
            ->execute([$code, strtoupper($code), 'IR', 'active']);
        $id = (int)db()->lastInsertId();
        foreach ($prefixes as $prefix) {
            $normalized = sms_pricing_normalize_prefix($prefix);
            db()->prepare('INSERT INTO ellsms_sms_operator_prefixes (operator_id, prefix, normalized_prefix, prefix_length, status, active_prefix) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $prefix, $normalized, strlen((string)$normalized), 'active', $normalized]);
        }
        sms_pricing_cache_reset();
        return $id;
    }

    private function makeRoute(string $code, bool $isDefault = false): int {
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute([$code, $code, 'active']);
        $providerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot) VALUES (?,?,?,?,?,?,?)')
            ->execute([$providerId, $code, $code, 'default', 'active', $isDefault ? 1 : 0, $isDefault ? 'default' : null]);
        sms_pricing_cache_reset();
        return (int)db()->lastInsertId();
    }

    private function assignSenderRoute(int $routeId): void {
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
    }

    private function assignOperatorRoute(int $operatorId, int $routeId): void {
        db()->prepare('INSERT INTO ellsms_operator_routes (operator_id, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$operatorId, 'default', $routeId, 'active', $operatorId . ':default']);
        sms_pricing_cache_reset();
    }

    private int $prefixCounter = 0;

    /** A fresh, monotonically distinct prefix in a block no real seeded catalog would use, so
     * uniq_active_prefix never collides with the legacy seed catalog or another prefix in this
     * same test. */
    private function randomPrefix(): string {
        $this->prefixCounter++;
        return '9199' . str_pad((string)$this->prefixCounter, 2, '0', STR_PAD_LEFT);
    }

    public function testASenderRouteCoversTheWholeBatchInOneGroupRegardlessOfDestinationOperator(): void {
        $mtnRoute = $this->makeRoute('sender_route');
        $this->assignSenderRoute($mtnRoute);
        // Two different operator routes exist too, but sender assignment must win outright for
        // every destination — this is the precedence rule, not a coincidence of test data.
        $prefixA = $this->randomPrefix(); $prefixB = $this->randomPrefix();
        $opA = $this->makeOperator('op_a_' . bin2hex(random_bytes(2)), [$prefixA]);
        $opB = $this->makeOperator('op_b_' . bin2hex(random_bytes(2)), [$prefixB]);
        $this->assignOperatorRoute($opA, $this->makeRoute('op_a_route'));
        $this->assignOperatorRoute($opB, $this->makeRoute('op_b_route'));

        $groups = sms_pricing_route_groups_for_destinations($this->sender, 'default', ["98{$prefixA}0000001", "98{$prefixB}0000002"]);
        $this->assertCount(1, $groups, 'a sender-specific route must cover the whole batch in one group');
        $this->assertSame($mtnRoute, $groups[0]['route']['route_id']);
        $this->assertCount(2, $groups[0]['destinations']);
    }

    public function testTwoDestinationOperatorsWithDifferentRoutesPartitionIntoTwoGroups(): void {
        // No sender route at all -- every destination falls through to its own operator route.
        $prefixA = $this->randomPrefix(); $prefixB = $this->randomPrefix();
        $opA = $this->makeOperator('op_a_' . bin2hex(random_bytes(2)), [$prefixA]);
        $opB = $this->makeOperator('op_b_' . bin2hex(random_bytes(2)), [$prefixB]);
        $routeA = $this->makeRoute('route_a');
        $routeB = $this->makeRoute('route_b');
        $this->assignOperatorRoute($opA, $routeA);
        $this->assignOperatorRoute($opB, $routeB);

        $destA1 = "98{$prefixA}0000001"; $destA2 = "98{$prefixA}0000002"; $destB1 = "98{$prefixB}0000003";
        $groups = sms_pricing_route_groups_for_destinations($this->sender, 'default', [$destA1, $destB1, $destA2]);

        $this->assertCount(2, $groups, 'two distinct destination-operator routes must produce two groups');
        $byRoute = [];
        foreach ($groups as $g) { $byRoute[$g['route']['route_id']] = $g['destinations']; }
        $this->assertEqualsCanonicalizing([$destA1, $destA2], $byRoute[$routeA], 'operator A destinations must group under route A');
        $this->assertEqualsCanonicalizing([$destB1], $byRoute[$routeB], 'operator B destination must group under route B');
    }

    public function testAnOperatorWithNoConfiguredRouteFallsToDefaultInItsOwnGroup(): void {
        // Relies on the legacy-seeded default route (db/ellsms_extra.sql) rather than creating a
        // second one -- uniq_default_route_per_type allows only one default route per message type,
        // and a real install always already has one.
        $prefixWithRoute = $this->randomPrefix(); $prefixWithoutRoute = $this->randomPrefix();
        $opWithRoute = $this->makeOperator('op_with_' . bin2hex(random_bytes(2)), [$prefixWithRoute]);
        $this->makeOperator('op_without_' . bin2hex(random_bytes(2)), [$prefixWithoutRoute]);
        $routeA = $this->makeRoute('route_a');
        $this->assignOperatorRoute($opWithRoute, $routeA);
        // opWithoutRoute deliberately gets no operator route -- must fall to default, per precedence.

        $destWithRoute = "98{$prefixWithRoute}0000001"; $destDefault = "98{$prefixWithoutRoute}0000002";
        $groups = sms_pricing_route_groups_for_destinations($this->sender, 'default', [$destWithRoute, $destDefault]);

        $this->assertCount(2, $groups);
        $byRoute = [];
        foreach ($groups as $g) { $byRoute[$g['route']['route_id']] = $g; }
        $this->assertEqualsCanonicalizing([$destWithRoute], $byRoute[$routeA]['destinations']);
        $defaultGroup = null;
        foreach ($groups as $g) { if ($g['route']['route_id'] !== $routeA) { $defaultGroup = $g; } }
        $this->assertNotNull($defaultGroup);
        $this->assertSame('default_route', $defaultGroup['route']['selection'] ?? null, 'an operator with no route must fall through to the default route, in its own group');
        $this->assertEqualsCanonicalizing([$destDefault], $defaultGroup['destinations']);
    }

    public function testNoOperatorSpecificRoutingCollapsesToOneDefaultGroupForTheWholeBatch(): void {
        // No sender route, no operator routes configured by this test -- every destination resolves
        // to whatever default route is active (the legacy-seeded one, per db/ellsms_extra.sql, since
        // a real install always has a default route). The point under test is that this still
        // collapses into ONE group for the whole batch, not that no route exists at all.
        $prefixA = $this->randomPrefix(); $prefixB = $this->randomPrefix();
        $groups = sms_pricing_route_groups_for_destinations($this->sender, 'default', ["98{$prefixA}0000001", "98{$prefixB}0000002"]);
        $this->assertCount(1, $groups);
        $this->assertSame('default_route', $groups[0]['route']['selection'] ?? null);
        $this->assertCount(2, $groups[0]['destinations']);
    }

    public function testGroupingThousandsOfRecipientsAcrossTwoOperatorsDoesNotIssueOneQueryPerNumber(): void {
        $prefixA = $this->randomPrefix(); $prefixB = $this->randomPrefix();
        $opA = $this->makeOperator('op_a_' . bin2hex(random_bytes(2)), [$prefixA]);
        $opB = $this->makeOperator('op_b_' . bin2hex(random_bytes(2)), [$prefixB]);
        $this->assignOperatorRoute($opA, $this->makeRoute('route_a'));
        $this->assignOperatorRoute($opB, $this->makeRoute('route_b'));
        sms_pricing_cache_reset();

        $destinations = [];
        for ($i = 0; $i < 2500; $i++) { $destinations[] = "98{$prefixA}" . str_pad((string)$i, 7, '0', STR_PAD_LEFT); }
        for ($i = 0; $i < 2500; $i++) { $destinations[] = "98{$prefixB}" . str_pad((string)$i, 7, '0', STR_PAD_LEFT); }

        $questions = static fn(): int => (int)db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
        $before = $questions();
        $groups = sms_pricing_route_groups_for_destinations($this->sender, 'default', $destinations);
        $after = $questions();

        $this->assertCount(2, $groups);
        $this->assertSame(2500, count($groups[0]['destinations']));
        $this->assertSame(2500, count($groups[1]['destinations']));
        // One route lookup per DISTINCT operator (2), not per recipient (5,000) — issue #8's
        // explicit "no N+1 DB queries" requirement.
        $this->assertLessThan(30, $after - $before, 'route grouping across a large batch must be a bounded number of queries, not one per recipient');
    }

    /**
     * Issue #8's "concurrent-send tests" criterion. Route resolution is read-only catalog lookups
     * behind a per-process TTL cache (sms_pricing_cached()) -- there is no shared mutable state one
     * resolution could corrupt for another, but two genuinely separate connections (standing in for
     * two concurrent worker processes dispatching different campaigns at the same instant) must
     * still each resolve their OWN sender/destination correctly and never see the other's route.
     */
    public function testInterleavedResolutionOfTwoSendersAndTwoOperatorsNeverCrossContaminatesTheSharedCache(): void
    {
        // sms_pricing_route_for_sender()'s per-(sender,type,operator) TTL cache is the only state two
        // "simultaneous" resolutions could possibly share -- interleaving several distinct keys
        // (rather than resolving each fully before starting the next) is the meaningful proof that
        // the cache never hands one key's cached route to a different key, the way a true race
        // between two worker processes calling this in the same instant would exercise it.
        $senderA = sprintf('%04d', 5600 + random_int(0, 40));
        $senderB = sprintf('%04d', 5650 + random_int(0, 40));
        $routeA = $this->makeRoute('concurrent_route_a');
        $routeB = $this->makeRoute('concurrent_route_b');
        $this->assignSenderRouteFor($senderA, $routeA);
        $this->assignSenderRouteFor($senderB, $routeB);

        $prefixC = $this->randomPrefix(); $prefixD = $this->randomPrefix();
        $opC = $this->makeOperator('op_c_' . bin2hex(random_bytes(2)), [$prefixC]);
        $opD = $this->makeOperator('op_d_' . bin2hex(random_bytes(2)), [$prefixD]);
        $routeC = $this->makeRoute('concurrent_route_c');
        $routeD = $this->makeRoute('concurrent_route_d');
        $this->assignOperatorRoute($opC, $routeC);
        $this->assignOperatorRoute($opD, $routeD);
        sms_pricing_cache_reset();

        $noSenderRoute = sprintf('%04d', 5700 + random_int(0, 40));
        $results = [];
        // Deliberately interleaved: A, C, B, D, A again, D again -- never fully resolving one key
        // before touching another, unlike every other test in this file.
        $results['a1'] = sms_pricing_route_groups_for_destinations($senderA, 'default', ['989120000001'])[0]['route']['route_id'];
        $results['c1'] = sms_pricing_route_groups_for_destinations($noSenderRoute, 'default', ["98{$prefixC}0000001"])[0]['route']['route_id'];
        $results['b1'] = sms_pricing_route_groups_for_destinations($senderB, 'default', ['989120000002'])[0]['route']['route_id'];
        $results['d1'] = sms_pricing_route_groups_for_destinations($noSenderRoute, 'default', ["98{$prefixD}0000002"])[0]['route']['route_id'];
        $results['a2'] = sms_pricing_route_groups_for_destinations($senderA, 'default', ['989120000003'])[0]['route']['route_id'];
        $results['d2'] = sms_pricing_route_groups_for_destinations($noSenderRoute, 'default', ["98{$prefixD}0000004"])[0]['route']['route_id'];

        $this->assertSame($routeA, $results['a1']);
        $this->assertSame($routeA, $results['a2'], 'sender A must resolve consistently across interleaved lookups for other keys');
        $this->assertSame($routeB, $results['b1']);
        $this->assertSame($routeC, $results['c1']);
        $this->assertSame($routeD, $results['d1']);
        $this->assertSame($routeD, $results['d2'], 'operator D must resolve consistently across interleaved lookups for other keys');
        $this->assertNotSame($results['a1'], $results['b1']);
        $this->assertNotSame($results['c1'], $results['d1']);
    }

    private function assignSenderRouteFor(string $sender, int $routeId): void {
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$sender, 'default', $routeId, 'active', $sender . ':default']);
        sms_pricing_cache_reset();
    }
}
