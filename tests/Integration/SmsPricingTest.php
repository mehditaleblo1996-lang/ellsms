<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Admin-managed operator/provider/route pricing against REAL MySQL — the half that only a real
 * database can prove: catalog CRUD and its uniqueness guarantees, longest-prefix resolution over
 * real rows, effective-dated price selection, LEGACY PARITY (the hard backward-compatibility
 * criterion), immutable price snapshots, and historical costs surviving a rate change.
 *
 * The pure rules (prefix normalization, matching, period selection, money arithmetic) are proven
 * without a database in tests/Unit/SmsPricingTest.php; nothing is duplicated here.
 *
 * Every test builds its OWN provider/route/prices with random codes rather than reusing the seeded
 * legacy catalog, except the legacy-parity tests, which are specifically about the seed.
 */
final class SmsPricingTest extends IntegrationTestCase
{
    private int $ownerId;
    private int $organizationId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = '5000';
        $this->ownerId = $this->makeUser(['originator' => $this->sender]);
        $result = create_organization($this->ownerId, 'Pricing Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 100000 WHERE user_id = ?')->execute([$this->ownerId]);
        // The engine caches catalog reads for a few seconds; each test builds its own catalog inside
        // its own transaction, so the cache must not carry a previous test's view across.
        sms_pricing_cache_reset();
    }

    protected function tearDown(): void
    {
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    private function actor(bool $admin = false): array
    {
        return ['id' => $this->ownerId, 'role' => $admin ? 'admin' : 'user', 'organization_id' => $this->organizationId, 'originator' => $this->sender];
    }

    /* ================= Catalog builders ================= */

    private function makeOperator(string $code, array $prefixes, string $status = 'active'): int
    {
        db()->prepare('INSERT INTO ellsms_sms_operators (code, name, country_code, status) VALUES (?,?,?,?)')
            ->execute([$code, strtoupper($code), 'IR', $status]);
        $id = (int)db()->lastInsertId();
        foreach ($prefixes as $prefix) {
            $normalized = sms_pricing_normalize_prefix($prefix);
            db()->prepare('INSERT INTO ellsms_sms_operator_prefixes (operator_id, prefix, normalized_prefix, prefix_length, status, active_prefix) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $prefix, $normalized, strlen((string)$normalized), 'active', $status === 'active' ? $normalized : null]);
        }
        sms_pricing_cache_reset();
        return $id;
    }

    private function makeProvider(string $code, string $status = 'active'): int
    {
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute([$code, $code, $status]);
        sms_pricing_cache_reset();
        return (int)db()->lastInsertId();
    }

    private function makeRoute(int $providerId, string $code, string $messageType = 'default', bool $isDefault = false, string $status = 'active'): int
    {
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot) VALUES (?,?,?,?,?,?,?)')
            ->execute([$providerId, $code, $code, $messageType, $status, $isDefault ? 1 : 0, ($isDefault && $status === 'active') ? $messageType : null]);
        sms_pricing_cache_reset();
        return (int)db()->lastInsertId();
    }

    private function makePrice(int $routeId, ?int $operatorId, int $millicredits, string $from = '2000-01-01 00:00:00', ?string $to = null, string $status = 'active'): int
    {
        db()->prepare(
            'INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, effective_to, status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$routeId, $operatorId, $operatorId ?? 0, $millicredits, 'credit', $from, $to, $status]);
        sms_pricing_cache_reset();
        return (int)db()->lastInsertId();
    }

    private function assignSenderRoute(string $sender, int $routeId, string $messageType = 'default'): void
    {
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$sender, $messageType, $routeId, 'active', $sender . ':' . $messageType]);
        sms_pricing_cache_reset();
    }

    /** Points this test's sender at a private route/rate, so the seeded default catalog is irrelevant. */
    private function privateRouteAt(int $millicredits, ?int $operatorId = null): int
    {
        $suffix = bin2hex(random_bytes(3));
        $routeId = $this->makeRoute($this->makeProvider('prov_' . $suffix), 'route_' . $suffix);
        $this->makePrice($routeId, $operatorId, $millicredits);
        $this->assignSenderRoute($this->sender, $routeId);
        return $routeId;
    }

    /* ================= LEGACY PARITY — hard acceptance criterion (STEP 13/56) ================= */

    public function testAFreshlyMigratedInstallStillChargesExactlyOneCreditPerSegment(): void
    {
        // No sender assignment, no custom catalog: exactly the state an existing install is in the
        // moment this feature's migration is applied. The bill must be byte-identical to the
        // pre-feature `sms_parts($content) * count(recipients)`.
        $content = str_repeat('س', 150); // 3 unicode segments
        $recipients = ['989121110001', '989351110002', '989221110003'];

        $estimate = estimate_message_cost($this->actor(), $this->sender, $recipients, $content);
        $this->assertTrue($estimate['ok'], 'a migrated install must be able to price a send with zero admin configuration');
        $this->assertSame(sms_parts($content) * count($recipients), $estimate['pricing']['estimated_cost']);
        $this->assertSame(1.0, $estimate['pricing']['credits_per_segment']);
    }

    public function testLegacyParityHoldsForEveryOperatorAndForUnknownNumbersAlike(): void
    {
        // The seeded catalog covers the three Iranian carriers plus a route DEFAULT price, so an
        // unrecognized number must cost the same as a recognized one — no operator is special.
        foreach (['989121110001', '989351110001', '989221110001', '982112345678', '447700900123'] as $number) {
            $estimate = estimate_message_cost($this->actor(), $this->sender, [$number], 'hello');
            $this->assertTrue($estimate['ok'], "priceable: {$number}");
            $this->assertSame(1, $estimate['pricing']['estimated_cost'], "one segment, one credit: {$number}");
        }
    }

    public function testTheRealSendPathChargesTheSameLegacyAmountThePreviewShowed(): void
    {
        $content = str_repeat('س', 150);
        $recipients = ['989121110001', '989121110002'];
        $estimate = estimate_message_cost($this->actor(), $this->sender, $recipients, $content);

        $priced = sms_pricing_price_single_content($recipients, $content, $this->sender, null, null, false);
        $this->assertTrue($priced['ok']);
        $this->assertSame($estimate['pricing']['estimated_cost'], $priced['total_cost'],
            'preview and the send path must reach the same number through the same function');
        $this->assertSame(sms_parts($content) * count($recipients), $priced['total_cost']);
    }

    /* ================= Operator resolution over real rows (STEP 5/17) ================= */

    public function testLongestPrefixWinsOverRealCatalogRows(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $broad  = $this->makeOperator('broad_' . $suffix, ['0195']);
        $narrow = $this->makeOperator('narrow_' . $suffix, ['01954']);

        $this->assertSame($narrow, sms_resolve_operator('9819541234567')['operator_id']);
        $this->assertSame($broad, sms_resolve_operator('9819551234567')['operator_id']);
    }

    public function testAnArchivedOperatorOrPrefixStopsMatchingImmediately(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->makeOperator('arch_' . $suffix, ['0196']);
        $this->assertSame($id, sms_resolve_operator('9819612345678')['operator_id']);

        db()->prepare("UPDATE ellsms_sms_operator_prefixes SET status='archived', active_prefix=NULL WHERE operator_id = ?")->execute([$id]);
        sms_pricing_cache_reset();
        $this->assertNull(sms_resolve_operator('9819612345678')['operator_id'], 'an archived prefix must stop matching');
        $this->assertSame('unknown', sms_resolve_operator('9819612345678')['operator_code']);
    }

    public function testTheDatabaseItselfRefusesTwoActiveRulesForOnePrefix(): void
    {
        // This is what makes longest-prefix matching unambiguous under concurrent admin edits —
        // an application-level check could race, a unique index cannot.
        $suffix = bin2hex(random_bytes(3));
        $this->makeOperator('first_' . $suffix, ['0197']);
        $this->expectException(\PDOException::class);
        $this->makeOperator('second_' . $suffix, ['0197']);
    }

    public function testOperatorDetectionIsReportedAsAConfiguredClassificationNotAVerifiedCarrier(): void
    {
        // Number portability means a prefix cannot prove the CURRENT carrier (STEP 6). Everything
        // downstream — snapshots included — must say where the classification came from.
        $this->assertSame('prefix', sms_resolve_operator('989121110001')['operator_source']);
        $this->assertSame('prefix', sms_resolve_operator('447700900123')['operator_source'], 'even an unmatched number reports its source honestly');
    }

    /* ================= Route selection (STEP 8/9/15) ================= */

    public function testAnExplicitSenderAssignmentBeatsTheDefaultRoute(): void
    {
        $routeId = $this->privateRouteAt(2000);
        $resolved = sms_pricing_route_for_sender($this->sender, 'promotional');
        $this->assertSame($routeId, (int)$resolved['route_id']);
        $this->assertSame('sender_assignment', $resolved['selection']);

        $unassigned = sms_pricing_route_for_sender('6000', 'promotional');
        $this->assertSame('default_route', $unassigned['selection'], 'a sender with no assignment falls to the configured default');
    }

    public function testARouteUnderAnArchivedProviderIsUnusableForNewPricing(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $providerId = $this->makeProvider('dead_' . $suffix);
        $routeId = $this->makeRoute($providerId, 'r_' . $suffix);
        $this->makePrice($routeId, null, 5000);
        $this->assignSenderRoute($this->sender, $routeId);
        $this->assertSame(5000, (int)sms_pricing_resolve($this->sender, '989121110001')['unit_price']);

        db()->prepare("UPDATE ellsms_sms_providers SET status='archived' WHERE id = ?")->execute([$providerId]);
        sms_pricing_cache_reset();

        // Falls through to the next deterministic step (the seeded default route) — NOT to some
        // other provider chosen by price or health, which this feature deliberately never does.
        $resolved = sms_pricing_route_for_sender($this->sender, 'promotional');
        $this->assertSame('default_route', $resolved['selection']);
        $this->assertNotSame($routeId, (int)$resolved['route_id']);
    }

    public function testTheDatabaseRefusesASecondActiveDefaultRouteForOneMessageType(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $providerId = $this->makeProvider('p_' . $suffix);
        $this->makeRoute($providerId, 'a_' . $suffix, 'otp', true);
        $this->expectException(\PDOException::class);
        $this->makeRoute($providerId, 'b_' . $suffix, 'otp', true);
    }

    /* ================= Price resolution (STEP 10/11) ================= */

    public function testAnOperatorSpecificRateOverridesTheRouteDefaultForThatOperatorOnly(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $operatorId = $this->makeOperator('op_' . $suffix, ['0198']);
        $routeId = $this->privateRouteAt(1000);
        $this->makePrice($routeId, $operatorId, 2500);

        $this->assertSame(2500, (int)sms_pricing_resolve($this->sender, '9819812345678')['unit_price']);
        $this->assertSame(1000, (int)sms_pricing_resolve($this->sender, '989121110001')['unit_price']);
    }

    public function testAnUnknownNumberUsesTheRouteDefaultRateRatherThanAnArbitraryOperator(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $operatorId = $this->makeOperator('op_' . $suffix, ['0199']);
        $routeId = $this->privateRouteAt(1000);
        $this->makePrice($routeId, $operatorId, 9000);

        $resolution = sms_pricing_resolve($this->sender, '447700900123');
        $this->assertNull($resolution['operator_id']);
        $this->assertSame(1000, (int)$resolution['unit_price']);
        $this->assertSame('route_default', $resolution['price_source']);
    }

    public function testThePriceInEffectDependsOnThePricingTimestamp(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $routeId = $this->makeRoute($this->makeProvider('p_' . $suffix), 'r_' . $suffix);
        $this->makePrice($routeId, null, 1000, '2026-01-01 00:00:00', '2026-06-01 00:00:00');
        $this->makePrice($routeId, null, 3000, '2026-06-01 00:00:00');
        $this->assignSenderRoute($this->sender, $routeId);

        $this->assertSame(1000, (int)sms_pricing_resolve($this->sender, '989121110001', null, '2026-03-01 00:00:00')['unit_price']);
        $this->assertSame(3000, (int)sms_pricing_resolve($this->sender, '989121110001', null, '2026-09-01 00:00:00')['unit_price']);
    }

    /* ================= Fail closed (Invariant H, STEP 22/44) ================= */

    public function testWithTheLegacyFallbackDisabledAnUnpricedRecipientRefusesTheSend(): void
    {
        $suffix = bin2hex(random_bytes(3));
        // A route with NO price at all, pinned to this sender.
        $routeId = $this->makeRoute($this->makeProvider('p_' . $suffix), 'r_' . $suffix);
        $this->assignSenderRoute($this->sender, $routeId);
        $this->withFallbackDisabled(function (): void {
            $priced = sms_pricing_price_single_content(['989121110001'], 'hello', $this->sender, null, null, false);
            $this->assertFalse($priced['ok'], 'an unpriceable recipient must never be charged a guessed rate');
            $this->assertSame(1, $priced['unpriced_count']);
            $this->assertSame(0, $priced['total_cost']);

            $estimate = estimate_message_cost($this->actor(), $this->sender, ['989121110001'], 'hello');
            $this->assertFalse($estimate['ok']);
            $this->assertSame('pricing_unavailable', $estimate['reason']);
            $this->assertSame(1, $estimate['pricing_failure']['unpriced_count']);
        });
    }

    public function testTheRefusalReportsPricedVsUnpricedCountsSoTheUiCanExplainItself(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $operatorId = $this->makeOperator('known_' . $suffix, ['0193']);
        $routeId = $this->makeRoute($this->makeProvider('p_' . $suffix), 'r_' . $suffix);
        $this->makePrice($routeId, $operatorId, 1000);   // ONLY this operator has a rate
        $this->assignSenderRoute($this->sender, $routeId);

        $this->withFallbackDisabled(function (): void {
            $estimate = estimate_message_cost(
                $this->actor(), $this->sender,
                ['981931234567', '981931234568', '447700900123'],   // 2 on the priced operator, 1 unknown
                'hello'
            );
            $this->assertFalse($estimate['ok']);
            $this->assertSame(2, $estimate['pricing_failure']['priced_count']);
            $this->assertSame(1, $estimate['pricing_failure']['unpriced_count']);
            $this->assertArrayHasKey('operator_unknown_no_default_price', $estimate['pricing_failure']['reasons']);
        });
    }

    public function testAPlatformAdminIsNeverBlockedByAPricingGapBecauseTheSendIsFreeAnyway(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $routeId = $this->makeRoute($this->makeProvider('p_' . $suffix), 'r_' . $suffix);
        $this->assignSenderRoute($this->sender, $routeId);
        $this->withFallbackDisabled(function (): void {
            $estimate = estimate_message_cost($this->actor(true), $this->sender, ['989121110001'], 'hello');
            $this->assertTrue($estimate['ok'], 'an exempt send must not fail closed — the answer is zero either way');
            $this->assertSame(0, $estimate['pricing']['estimated_cost']);
            $this->assertSame('admin_exempt', $estimate['pricing']['price_source']);
        });
    }

    /**
     * Runs $work with the explicit global legacy fallback switched OFF — i.e. the state of an
     * install whose operator has finished configuring real tariffs and turned the compatibility net
     * off, which is the only state in which the engine truly fails closed.
     *
     * Flips the real ellsms_settings row (not a mock) and drops the pricing cache either side, the
     * same two steps public/sms-pricing.php performs. The row is written inside this test's own
     * transaction, so the rollback in tearDown() removes it regardless.
     */
    private function withFallbackDisabled(callable $work): void
    {
        db()->prepare("INSERT INTO ellsms_settings (skey, svalue) VALUES ('sms_pricing_legacy_fallback','0')
                       ON DUPLICATE KEY UPDATE svalue = '0'")->execute();
        sms_pricing_cache_reset();
        $this->assertFalse(sms_pricing_legacy_fallback_enabled(), 'the fallback switch must actually be off for this test to mean anything');
        try {
            $work();
        } finally {
            db()->prepare("DELETE FROM ellsms_settings WHERE skey = 'sms_pricing_legacy_fallback'")->execute();
            sms_pricing_cache_reset();
        }
    }

    /* ================= Price snapshots & historical immutability (STEP 23/45) ================= */

    public function testAnAcceptedSendWritesAnImmutablePriceSnapshot(): void
    {
        $routeId = $this->privateRouteAt(1500);
        $reference = 'test_' . bin2hex(random_bytes(4));

        $priced = sms_pricing_price_single_content(['989121110001', '989121110002'], str_repeat('س', 150), $this->sender, 'promotional', null, false);
        $this->assertTrue($priced['ok']);
        sms_price_snapshot_record($priced, $this->organizationId, $this->ownerId, 'direct_send', $reference);

        $rows = sms_price_snapshot_for('direct_send', $reference);
        $this->assertCount(1, $rows, 'both recipients share one pricing decision, so one group row');
        $this->assertSame(1500, (int)$rows[0]['unit_price_millicredits']);
        $this->assertSame(2, (int)$rows[0]['recipient_count']);
        $this->assertSame(6, (int)$rows[0]['segment_count']);
        $this->assertSame($priced['total_cost'], (int)$rows[0]['total_cost_credits']);
        $this->assertSame($routeId, (int)$rows[0]['route_id']);
        $this->assertSame('prefix', $rows[0]['operator_source']);
    }

    public function testReplayingASnapshotWriteKeepsTheFirstAcceptedPrice(): void
    {
        $this->privateRouteAt(1000);
        $reference = 'test_' . bin2hex(random_bytes(4));
        $priced = sms_pricing_price_single_content(['989121110001'], 'hello', $this->sender, 'promotional', null, false);
        sms_price_snapshot_record($priced, $this->organizationId, $this->ownerId, 'direct_send', $reference);

        // The admin raises the rate, and a crashed worker replays the same acceptance.
        $tampered = $priced;
        $tampered['groups'][0]['unit_price'] = 99000;
        $tampered['groups'][0]['cost'] = 99;
        sms_price_snapshot_record($tampered, $this->organizationId, $this->ownerId, 'direct_send', $reference);

        $rows = sms_price_snapshot_for('direct_send', $reference);
        $this->assertCount(1, $rows);
        $this->assertSame(1000, (int)$rows[0]['unit_price_millicredits'], 'the FIRST acceptance is what history keeps');
    }

    public function testAnAdminRateChangeNeverAltersAlreadyRecordedHistoricalCosts(): void
    {
        // The hard acceptance criterion of STEP 45: send at price X, change to Y, old cost stays X.
        $routeId = $this->privateRouteAt(1000);
        $oldReference = 'old_' . bin2hex(random_bytes(4));
        $before = sms_pricing_price_single_content(['989121110001'], str_repeat('س', 150), $this->sender, 'promotional', null, false);
        sms_price_snapshot_record($before, $this->organizationId, $this->ownerId, 'direct_send', $oldReference);
        $oldCost = (int)sms_price_snapshot_for('direct_send', $oldReference)[0]['total_cost_credits'];

        // Admin closes the old period and opens a new, dearer one — exactly what the admin page does.
        $now = sms_pricing_now();
        db()->prepare('UPDATE ellsms_sms_route_prices SET effective_to = ? WHERE route_id = ? AND effective_to IS NULL')->execute([$now, $routeId]);
        $this->makePrice($routeId, null, 4000, $now);

        $newReference = 'new_' . bin2hex(random_bytes(4));
        $after = sms_pricing_price_single_content(['989121110001'], str_repeat('س', 150), $this->sender, 'promotional', null, false);
        sms_price_snapshot_record($after, $this->organizationId, $this->ownerId, 'direct_send', $newReference);

        $this->assertSame($oldCost, (int)sms_price_snapshot_for('direct_send', $oldReference)[0]['total_cost_credits'],
            'the historical cost must be untouched by a rate change');
        $this->assertSame(3, $oldCost, '3 segments at 1 credit');
        $this->assertSame(12, (int)sms_price_snapshot_for('direct_send', $newReference)[0]['total_cost_credits'],
            'the NEW send uses the NEW rate: 3 segments at 4 credits');
    }

    public function testSettlementUpdatesOnlyTheSettledColumnsAndNeverThePrice(): void
    {
        $this->privateRouteAt(1000);
        $reference = 'settle_' . bin2hex(random_bytes(4));
        $priced = sms_pricing_price_single_content(['989121110001', '989121110002'], 'hello', $this->sender, 'promotional', null, false);
        sms_price_snapshot_record($priced, $this->organizationId, $this->ownerId, 'direct_send', $reference);

        // Only one of the two destinations actually sent.
        $settlement = sms_pricing_settlement($priced, ['989121110001'], 1);
        sms_price_snapshot_settle('direct_send', $reference, $settlement['by_group']);

        $row = sms_price_snapshot_for('direct_send', $reference)[0];
        $this->assertSame(1000, (int)$row['unit_price_millicredits']);
        $this->assertSame(2, (int)$row['total_cost_credits'], 'accepted cost (both recipients) is unchanged');
        $this->assertSame(1, (int)$row['committed_cost_credits'], 'only the recipient that sent was settled');
        $this->assertSame('settled', $row['status']);
    }

    /* ================= Bulk pricing (STEP 24/48) ================= */

    public function testABulkJobFreezesEachRowsAcceptedPriceOntoTheRowItself(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $operatorId = $this->makeOperator('bulkop_' . $suffix, ['0194']);
        $routeId = $this->privateRouteAt(1000);
        $this->makePrice($routeId, $operatorId, 3000);

        $items = [
            ['mobile' => '989121110001', 'content' => 'a'],           // route default: 1 credit
            ['mobile' => '9819412345678', 'content' => 'b'],          // operator rate: 3 credits
        ];
        [$ok, , $jobId] = bulk_queue_job($this->actor(), 'p2p', 'freeze test', $this->sender, null, $items);
        $this->assertTrue($ok, 'job should queue');

        $rows = db()->query('SELECT mobile, unit_price_millicredits, price_cost_credits, price_group_key FROM ellsms_bulk_items WHERE job_id = ' . (int)$jobId . ' ORDER BY id')->fetchAll();
        $this->assertSame(1000, (int)$rows[0]['unit_price_millicredits']);
        $this->assertSame(1, (int)$rows[0]['price_cost_credits']);
        $this->assertSame(3000, (int)$rows[1]['unit_price_millicredits']);
        $this->assertSame(3, (int)$rows[1]['price_cost_credits']);
        $this->assertNotSame($rows[0]['price_group_key'], $rows[1]['price_group_key'], 'different rates are different pricing groups');

        // The wallet reservation is the SUM of the frozen per-row prices, not a flat rate.
        $reserved = (int)db()->query('SELECT amount FROM ellsms_wallet_reservations WHERE reference_type = \'bulk_job\' AND reference_id = ' . (int)$jobId)->fetchColumn();
        $this->assertSame(4, $reserved);
    }

    public function testARateChangeAfterAcceptanceDoesNotChangeWhatAQueuedBulkRowWillCost(): void
    {
        $routeId = $this->privateRouteAt(1000);
        [$ok, , $jobId] = bulk_queue_job($this->actor(), 'p2p', 'frozen', $this->sender, null, [['mobile' => '989121110001', 'content' => 'a']]);
        $this->assertTrue($ok);
        $frozen = (int)db()->query('SELECT price_cost_credits FROM ellsms_bulk_items WHERE job_id = ' . (int)$jobId)->fetchColumn();

        $now = sms_pricing_now();
        db()->prepare('UPDATE ellsms_sms_route_prices SET effective_to = ? WHERE route_id = ? AND effective_to IS NULL')->execute([$now, $routeId]);
        $this->makePrice($routeId, null, 50000, $now);

        $this->assertSame($frozen, (int)db()->query('SELECT price_cost_credits FROM ellsms_bulk_items WHERE job_id = ' . (int)$jobId)->fetchColumn(),
            'a queued row keeps the price it was accepted at, however long it waits in the queue');
        $this->assertSame(1, $frozen);
    }

    public function testABulkJobPricesEveryRowAgainstOneTimestamp(): void
    {
        $this->privateRouteAt(1000);
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $items[] = ['mobile' => '98912111' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), 'content' => 'a'];
        }
        [$ok, , $jobId] = bulk_queue_job($this->actor(), 'p2p', 'one instant', $this->sender, null, $items);
        $this->assertTrue($ok);

        $instants = db()->query('SELECT DISTINCT priced_at FROM ellsms_sms_price_snapshots WHERE reference_type = \'bulk_job\' AND reference_id = ' . (int)$jobId)->fetchAll();
        $this->assertCount(1, $instants, 'one acceptance = one pricing instant, so a job can never straddle two price periods');
    }

    public function testABulkJobIsRefusedWholeWhenAnyRowCannotBePriced(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $operatorId = $this->makeOperator('only_' . $suffix, ['0192']);
        $routeId = $this->makeRoute($this->makeProvider('p_' . $suffix), 'r_' . $suffix);
        $this->makePrice($routeId, $operatorId, 1000);
        $this->assignSenderRoute($this->sender, $routeId);

        $this->withFallbackDisabled(function (): void {
            $jobsBefore = (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_jobs')->fetchColumn();
            $itemsBefore = (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items')->fetchColumn();

            [$ok, , $jobId, $reason] = bulk_queue_job($this->actor(), 'p2p', 'mixed', $this->sender, null, [
                ['mobile' => '981921234567', 'content' => 'priced'],
                ['mobile' => '447700900123', 'content' => 'unpriceable'],
            ]);

            $this->assertFalse($ok);
            $this->assertNull($jobId);
            $this->assertSame('pricing_unavailable', $reason);
            $this->assertSame($jobsBefore, (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_jobs')->fetchColumn(), 'no partial job');
            $this->assertSame($itemsBefore, (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items')->fetchColumn(), 'no partial items');
        });
    }

    /* ================= Batch efficiency (STEP 18) ================= */

    public function testResolvingThousandsOfRecipientsDoesNotIssueOneQueryPerNumber(): void
    {
        $this->privateRouteAt(1000);
        sms_pricing_cache_reset();

        $recipients = [];
        for ($i = 0; $i < 5000; $i++) {
            $recipients[] = '98912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
        }

        // MySQL's own statement counter is the honest measurement here — a mocked PDO would only
        // prove the mock was called as expected.
        $questions = static fn(): int => (int)db()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
        $before = $questions();
        $priced = sms_pricing_price_messages(
            array_map(static fn($m) => ['mobile' => $m, 'segments' => 1], $recipients),
            $this->sender, 'promotional', null, false
        );
        $after = $questions();

        $this->assertTrue($priced['ok']);
        $this->assertSame(5000, $priced['priced_count']);
        // Route lookup + price rows + prefix rules + the catalog probe, plus the two counter reads
        // themselves. A per-recipient query would be 5,000+.
        $this->assertLessThan(30, $after - $before, 'operator/route/price resolution must be a bounded number of queries regardless of recipient count');
    }

    /* ================= Preview breakdown (STEP 20/21) ================= */

    public function testThePreviewBreaksCostDownByOperatorProviderAndRoute(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $operatorId = $this->makeOperator('grp_' . $suffix, ['0191']);
        $routeId = $this->privateRouteAt(1000);
        $this->makePrice($routeId, $operatorId, 2000);

        $estimate = estimate_message_cost($this->actor(), $this->sender, [
            '9819112345678', '9819112345679',   // operator rate 2 credits
            '989121110001',                      // route default 1 credit
        ], 'hello');

        $this->assertTrue($estimate['ok']);
        $this->assertSame(5, $estimate['pricing']['estimated_cost']);
        $this->assertCount(2, $estimate['pricing']['groups']);
        $this->assertNull($estimate['pricing']['credits_per_segment'], 'mixed rates must not be flattened into one misleading unit price');
        $this->assertSame(1000, $estimate['pricing']['unit_price_min_millicredits']);
        $this->assertSame(2000, $estimate['pricing']['unit_price_max_millicredits']);

        $byOperator = [];
        foreach ($estimate['pricing']['groups'] as $group) {
            $byOperator[$group['operator']] = $group;
            $this->assertArrayHasKey('provider', $group);
            $this->assertArrayHasKey('route', $group);
        }
        $this->assertSame(2, $byOperator['grp_' . $suffix]['recipients']);
        $this->assertSame(4, $byOperator['grp_' . $suffix]['cost']);
        $this->assertSame(1, $byOperator['unknown']['cost'] ?? $byOperator['mci']['cost']);
    }

    /* ================= Rate replacement within one second (STEP 34/47) ================= */

    public function testReplacingARateTwiceInTheSameSecondProducesTwoNonOverlappingGaplessPeriods(): void
    {
        // Found by a live end-to-end run, not by theory: effective_from has one-second granularity
        // and is UNIQUE per (route, operator), so an admin correcting a rate immediately after
        // entering it originally collided and silently rolled the whole change back.
        $suffix = bin2hex(random_bytes(3));
        $routeId = $this->makeRoute($this->makeProvider('p_' . $suffix), 'r_' . $suffix);
        $this->assignSenderRoute($this->sender, $routeId);

        $now = sms_pricing_now();
        $first = sms_pricing_next_effective_from($routeId, null, $now);
        $this->makePrice($routeId, null, 2000, $first);

        // The correction, requested in the very same second.
        $second = sms_pricing_next_effective_from($routeId, null, $now);
        $this->assertNotSame($first, $second, 'the replacement must not try to reuse the same start instant');
        db()->prepare('UPDATE ellsms_sms_route_prices SET effective_to = ? WHERE route_id = ? AND effective_to IS NULL')
            ->execute([$second, $routeId]);
        $this->makePrice($routeId, null, 7000, $second);

        $this->assertSame(2, (int)db()->query('SELECT COUNT(*) FROM ellsms_sms_route_prices WHERE route_id = ' . $routeId)->fetchColumn());

        // Gapless AND non-overlapping: exactly one rate applies at every instant across the boundary.
        $justBefore = gmdate('Y-m-d H:i:s', strtotime($second . ' UTC') - 1);
        $this->assertSame(2000, (int)sms_pricing_resolve($this->sender, '989121110001', null, $justBefore)['unit_price']);
        $this->assertSame(7000, (int)sms_pricing_resolve($this->sender, '989121110001', null, $second)['unit_price']);
    }

    /* ================= Preview vs. actual after a rate change (STEP 49) ================= */

    public function testAPreviewShownBeforeARateChangeForcesReconfirmationRatherThanChargingSilently(): void
    {
        $routeId = $this->privateRouteAt(1000);
        $recipients = ['989121110001', '989121110002'];
        $content = str_repeat('س', 150); // 3 segments

        $preview = estimate_message_cost($this->actor(), $this->sender, $recipients, $content);
        $this->assertTrue($preview['ok']);
        $previewedCost = (int)$preview['pricing']['estimated_cost'];
        $this->assertSame(6, $previewedCost);

        // The admin triples the rate while the user is still looking at the confirmation card.
        $now = sms_pricing_now();
        db()->prepare('UPDATE ellsms_sms_route_prices SET effective_to = ? WHERE route_id = ? AND effective_to IS NULL')->execute([$now, $routeId]);
        $this->makePrice($routeId, null, 3000, $now);

        // The confirm step re-prices server-side from the resubmitted inputs — it never reuses the
        // number the browser carried forward.
        $recheck = estimate_message_cost($this->actor(), $this->sender, $recipients, $content);
        $this->assertSame(18, (int)$recheck['pricing']['estimated_cost'], 'the authoritative price is the CURRENT one');

        $check = cost_preview_confirmation_check($previewedCost, (int)$recheck['pricing']['estimated_cost'], time());
        $this->assertTrue($check['require_reconfirm'], 'a materially different price must be re-shown, never silently charged');
        $this->assertTrue($check['changed_materially']);
        $this->assertFalse($check['expired']);
    }

    public function testThePreviewNeverLeaksProviderConfigurationBeyondItsDisplayCode(): void
    {
        $this->privateRouteAt(1000);
        $estimate = estimate_message_cost($this->actor(), $this->sender, ['989121110001'], 'hello');
        $encoded = (string)json_encode($estimate['pricing']);
        foreach (['secret', 'password', 'token', 'api_key', 'endpoint', 'url', 'credential'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }
}
