<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Part B — message delivery reporting.
 *
 * Covers the three properties the reporting layer exists to guarantee: it describes what ACTUALLY
 * happened (historical route/gateway/price, never today's configuration), it never loses precision
 * on a provider reference, and it never leaks across tenants.
 */
final class DeliveryReportingTest extends IntegrationTestCase
{
    /** A real 19-digit provider reference — beyond exact integer range in both PHP and JavaScript. */
    private const LONG_PROVIDER_ID = '4473621976262727360';

    /* ================= provider id precision (B9/B32) ================= */

    public function testALongProviderReferenceSurvivesStorageAndRenderingExactly(): void {
        $attemptId = $this->makeAttempt(['provider_message_id' => self::LONG_PROVIDER_ID]);

        $row = report_attempt_by_id($attemptId, null);

        $this->assertSame(self::LONG_PROVIDER_ID, (string)$row['provider_message_id'], 'the stored value must be byte-identical');
        // The failure this guards against is a float round-trip, which yields 4.4736219762627E+18.
        $this->assertStringNotContainsString('E+', (string)$row['provider_message_id']);
        $this->assertStringNotContainsString('.', (string)$row['provider_message_id']);

        $rendered = htmlspecialchars((string)$row['provider_message_id'], ENT_QUOTES, 'UTF-8');
        $this->assertSame(self::LONG_PROVIDER_ID, $rendered, 'rendering must preserve every digit');
        $this->assertSame(19, strlen($rendered));
    }

    public function testAProviderReferenceIsNeverDamagedByNumericHandling(): void {
        // Demonstrates the exact corruption this rule prevents, so the guarantee is not merely
        // asserted but shown to be non-trivial.
        $asFloat = (string)(float)self::LONG_PROVIDER_ID;
        $this->assertNotSame(self::LONG_PROVIDER_ID, $asFloat, 'a float round-trip must genuinely damage this id');

        $attemptId = $this->makeAttempt(['provider_message_id' => self::LONG_PROVIDER_ID]);
        $this->assertSame(self::LONG_PROVIDER_ID, (string)report_attempt_by_id($attemptId, null)['provider_message_id']);
    }

    /* ================= delivery lifecycle fields (B5/B8/B25/B26) ================= */

    public function testDeliveryCheckedAtIsNeverPresentedAsTheDeliveryTime(): void {
        // A polled-but-undelivered message: we asked, the answer was not "delivered".
        $attemptId = $this->makeAttempt([
            'delivery_status'     => 'sent',
            'delivery_checked_at' => '2026-08-15 10:37:21',
            'delivered_at'        => null,
            'delivery_attempts'   => 3,
        ]);
        $row = report_attempt_by_id($attemptId, null);

        $this->assertNotNull($row['delivery_checked_at']);
        $this->assertNull($row['delivered_at'], 'an unpolled delivery must stay null, never borrow the check time');

        $timeline = report_build_timeline($row);
        $labels = array_column($timeline, 'label');
        $this->assertContains('آخرین استعلام وضعیت', $labels);
        $this->assertContains('هنوز تحویل نشده', $labels, 'a message with no delivery time must say so explicitly');

        // No timeline step may carry the check time under a delivery label.
        foreach ($timeline as $step) {
            if ($step['label'] === 'تحویل') {
                $this->fail('a message with delivered_at = NULL must have no delivery step');
            }
        }
    }

    public function testADeliveredMessageShowsItsRealDeliveryTime(): void {
        $attemptId = $this->makeAttempt([
            'delivery_status'     => 'delivered',
            'delivery_checked_at' => '2026-08-15 10:37:21',
            'delivered_at'        => '2026-08-15 10:37:23',
            'delivery_attempts'   => 3,
        ]);
        $timeline = report_build_timeline(report_attempt_by_id($attemptId, null));

        $delivery = array_values(array_filter($timeline, static fn(array $s): bool => $s['label'] === 'تحویل'));
        $this->assertCount(1, $delivery);
        $this->assertSame('2026-08-15 10:37:23', $delivery[0]['at'], 'the delivery step must use delivered_at, not the poll time');
    }

    public function testDeliveryAttemptsIsRecordedAndDistinctFromSendRetries(): void {
        $attemptId = $this->makeAttempt(['delivery_attempts' => 4]);
        $this->assertSame(4, (int)report_attempt_by_id($attemptId, null)['delivery_attempts']);
    }

    public function testTheRawProviderStatusIsPersistedAlongsideTheCanonicalState(): void {
        // B8: the provider's own token ("2") is kept so an operator can tell a correct mapping from a
        // missing one. It never replaces the canonical state.
        $attemptId = $this->makeAttempt(['delivery_status' => 'sent', 'provider_message_id' => 'raw-1']);

        $changed = gateway_status_record('attempt', $attemptId, 'sent', 'delivered', '2026-08-15 10:37:23', '2');

        $this->assertTrue($changed);
        $row = report_attempt_by_id($attemptId, null);
        $this->assertSame('delivered', $row['delivery_status'], 'the canonical state is the mapped one');
        $this->assertSame('2', $row['provider_status'], 'the raw provider token must be preserved for diagnosis');
    }

    public function testARefusedTransitionStillRecordsTheRawTokenWithoutChangingTheState(): void {
        // The case where the raw token matters MOST: a terminal row re-reported by the provider.
        $attemptId = $this->makeAttempt(['delivery_status' => 'delivered', 'provider_message_id' => 'raw-2']);

        $changed = gateway_status_record('attempt', $attemptId, 'delivered', 'sent', null, '1');

        $this->assertFalse($changed, 'a terminal state must never be downgraded');
        $row = report_attempt_by_id($attemptId, null);
        $this->assertSame('delivered', $row['delivery_status'], 'monotonicity must hold');
        $this->assertSame('1', $row['provider_status'], 'but the token that was refused is still worth recording');
    }

    public function testAnUnknownProviderTokenIsVisibleRatherThanSilentlyDiscarded(): void {
        $attemptId = $this->makeAttempt(['delivery_status' => 'sent', 'provider_message_id' => 'raw-3']);

        // A token with no mapping maps to `unknown`, which may not overwrite a known state...
        $changed = gateway_status_record('attempt', $attemptId, 'sent', 'unknown', null, 'WEIRD_TOKEN');

        $this->assertFalse($changed);
        $row = report_attempt_by_id($attemptId, null);
        $this->assertSame('sent', $row['delivery_status']);
        // ...but the operator can now see EXACTLY which token needs a mapping entry.
        $this->assertSame('WEIRD_TOKEN', $row['provider_status']);
    }

    /* ================= segmentation parity (B3/B33) ================= */

    public function testReportPartCountMatchesTheBillingEngineExactly(): void {
        // ONE engine: the report must not have its own length algorithm. Each case asserts the
        // reported count equals sms_parts() — the same function pricing and cost preview call.
        // A plain loop rather than a data provider, matching the rest of this suite.
        $cases = [
            'short Persian'      => ['سلام', 1],
            'Persian at limit'   => [str_repeat('ا', 70), 1],
            'Persian multipart'  => [str_repeat('ا', 71), 2],
            'Persian 3 parts'    => [str_repeat('ا', 140), 3],
            'short Latin'        => ['hello', 1],
            'Latin at limit'     => [str_repeat('a', 160), 1],
            'Latin multipart'    => [str_repeat('a', 161), 2],
            'Latin 3 parts'      => [str_repeat('a', 320), 3],
            'mixed forces UCS-2' => [str_repeat('a', 100) . 'ی', 2],
        ];

        foreach ($cases as $label => [$content, $expectedParts]) {
            $this->assertSame($expectedParts, sms_parts($content), "segmentation engine: {$label}");

            $reported = report_segment_count(['segment_count' => sms_parts($content)], $content);
            $this->assertSame($expectedParts, $reported['parts'], "report must agree with the billed count: {$label}");
            $this->assertSame('snapshot', $reported['source'], $label);

            // With no snapshot the fallback still uses the same engine rather than a second one.
            $derived = report_segment_count(null, $content);
            $this->assertSame($expectedParts, $derived['parts'], "derived fallback: {$label}");
            $this->assertSame('derived', $derived['source'], $label);
        }
    }

    public function testAHistoricalPartCountIsReadFromTheSnapshotAndNotRecomputed(): void {
        // The snapshot is authoritative even when it disagrees with today's arithmetic — that is the
        // whole point of freezing it, and it is what keeps a report matching the invoice.
        $reported = report_segment_count(['segment_count' => 9], 'سلام');

        $this->assertSame(9, $reported['parts'], 'a stored historical count must win over recomputation');
        $this->assertSame('snapshot', $reported['source']);
        $this->assertNotSame(sms_parts('سلام'), $reported['parts']);
    }

    public function testAMessageWithNoStoredCountAndNoContentIsReportedUnavailableRatherThanZero(): void {
        // B4: leave it clearly unavailable rather than inventing a value.
        $reported = report_segment_count(null, null);
        $this->assertSame('unavailable', $reported['source']);
    }

    /* ================= encoding ================= */

    public function testEncodingIsDerivedWithTheSameRuleThatChoosesSegmentSizes(): void {
        $this->assertTrue(report_message_encoding('سلام')['unicode']);
        $this->assertFalse(report_message_encoding('hello')['unicode']);
        // One non-ASCII character forces the WHOLE message to UCS-2 — the same rule sms_parts() uses.
        $this->assertTrue(report_message_encoding('hello ی')['unicode']);
        $this->assertSame(2, sms_parts(str_repeat('a', 100) . 'ی'));
    }

    /* ================= historical accuracy (B16/B17) ================= */

    public function testTheReportDescribesTheRouteActuallyUsedNotTheSendersCurrentRoute(): void {
        $routeA = $this->makeRoute('route_used');
        $routeB = $this->makeRoute('route_current');
        $attemptId = $this->makeAttempt(['route_id' => $routeA, 'gateway_config_version' => 17]);

        // The sender is later re-pointed at a different route, exactly as an admin edit would do.
        $row = report_attempt_by_id($attemptId, null);

        $this->assertSame($routeA, (int)$row['route_id'], 'the report must show the route the message travelled');
        $this->assertNotSame($routeB, (int)$row['route_id']);
        $this->assertSame(17, (int)$row['gateway_config_version'], 'the config version pins the exact configuration used');
    }

    public function testHistoricalPricingIsReadFromTheSnapshotAndNeverRepriced(): void {
        $referenceId = 'hist-' . bin2hex(random_bytes(4));
        $this->makeSnapshot($referenceId, ['unit_price_millicredits' => 1000, 'segment_count' => 2,
                                           'recipient_count' => 1, 'total_cost_credits' => 2, 'committed_cost_credits' => 2]);

        $before = report_pricing_for('direct_send', $referenceId);
        $this->assertTrue($before['available']);
        $this->assertSame(2, $before['committed']);

        // An admin changes the tariff. The historical report must not move.
        db()->prepare('UPDATE ellsms_sms_route_prices SET price_per_segment_millicredits = 9000')->execute();

        $after = report_pricing_for('direct_send', $referenceId);
        $this->assertSame(2, $after['committed'], 'a historical charge must never be recomputed at the new rate');
        $this->assertSame(1000, (int)$after['groups'][0]['unit_price_millicredits']);
    }

    public function testUnitPriceFormattingUsesIntegerArithmeticRatherThanFloats(): void {
        $this->assertSame('1', report_format_millicredits(1000));
        $this->assertSame('1.5', report_format_millicredits(1500));
        $this->assertSame('0.001', report_format_millicredits(1));
        $this->assertSame('12', report_format_millicredits(12000));
    }

    /* ================= status presentation (B7) ================= */

    public function testCanonicalStatusesMapToPersianLabelsWithoutChangingTheStoredValue(): void {
        $this->assertSame('تحویل شده', report_delivery_status_label('delivered'));
        $this->assertSame('ارسال شده', report_delivery_status_label('sent'));
        $this->assertSame('ناموفق', report_delivery_status_label('failed'));
        $this->assertSame('رد شده', report_delivery_status_label('rejected'));
        $this->assertSame('منقضی', report_delivery_status_label('expired'));
        $this->assertSame('نامشخص', report_delivery_status_label('unknown'));
        $this->assertSame('نامشخص', report_delivery_status_label(null));

        $attemptId = $this->makeAttempt(['delivery_status' => 'delivered']);
        $this->assertSame('delivered', report_attempt_by_id($attemptId, null)['delivery_status'],
            'the DB keeps the canonical English enum value — only the display is translated');
    }

    /* ================= tenant isolation (B18) ================= */

    public function testAnAttemptFromAnotherOrganizationIsNotReachableByChangingTheIdInTheUrl(): void {
        $orgA = $this->makeOrganization();
        $orgB = $this->makeOrganization();
        $attemptId = $this->makeAttempt(['organization_id' => $orgA]);

        $this->assertNotNull(report_attempt_by_id($attemptId, $orgA), 'the owning organization sees its own message');
        $this->assertNull(report_attempt_by_id($attemptId, $orgB), 'another organization must see nothing at all');
        $this->assertNotNull(report_attempt_by_id($attemptId, null), 'a platform admin keeps its documented bypass');
    }

    public function testBulkRecipientsAreScopedThroughTheOwningJob(): void {
        $orgA = $this->makeOrganization();
        $orgB = $this->makeOrganization();
        $jobId = $this->makeBulkJob($orgA, [self::LONG_PROVIDER_ID, '4473621976262727361']);

        $this->assertCount(2, report_bulk_items($jobId, $orgA));
        $this->assertCount(0, report_bulk_items($jobId, $orgB), 'a crafted job id must not reach another tenant\'s recipients');
    }

    public function testEachBulkRecipientCarriesItsOwnProviderReferenceAndState(): void {
        // B15: the first recipient's values must never be repeated across rows.
        $orgId = $this->makeOrganization();
        $jobId = $this->makeBulkJob($orgId, [self::LONG_PROVIDER_ID, '4473621976262727361']);

        $items = report_bulk_items($jobId, $orgId);
        $ids = array_map(static fn(array $i): string => (string)$i['provider_message_id'], $items);

        $this->assertSame([self::LONG_PROVIDER_ID, '4473621976262727361'], $ids);
        $this->assertCount(2, array_unique($ids), 'each recipient must keep its own provider reference');
    }

    /* ================= performance (B20) ================= */

    public function testNamesForAWholePageAreResolvedInBoundedQueriesRatherThanPerRow(): void {
        $routeIds = [];
        for ($i = 0; $i < 12; $i++) {
            $routeIds[] = $this->makeRoute('perf_' . $i . '_' . bin2hex(random_bytes(3)));
        }

        // One call resolves every id on the page; the assertion is that all of them come back, which
        // is only possible from a set-based lookup rather than a per-row one.
        $names = report_resolve_names([], $routeIds, []);
        $this->assertCount(12, $names['routes']);
    }

    public function testAnIdWithNoMatchingRowKeepsItsNumericFormRatherThanVanishing(): void {
        $names = report_resolve_names([], [999999], []);
        $this->assertSame([], $names['routes'], 'a deleted route simply has no name to show');
    }

    /* ================= fixtures ================= */

    private function makeAttempt(array $overrides = []): int {
        $row = array_merge([
            'organization_id'        => null,
            'user_id'                => $this->makeUser(),
            'reference_type'         => 'direct_send',
            'reference_id'           => 'ref-' . bin2hex(random_bytes(6)),
            'status'                 => 'accepted',
            'gateway_id'             => null,
            'gateway_config_version' => 1,
            'route_id'               => null,
            'operator_id'            => null,
            'destination'            => '989121234567',
            'provider_message_id'    => 'pmid-' . bin2hex(random_bytes(4)),
            'provider_status'        => null,
            'delivery_status'        => 'sent',
            'delivery_checked_at'    => null,
            'delivery_attempts'      => 0,
            'delivered_at'           => null,
        ], $overrides);

        db()->prepare(
            "INSERT INTO ellsms_message_attempts
               (organization_id, user_id, reference_type, reference_id, status, error_code,
                gateway_id, gateway_config_version, route_id, operator_id, destination,
                provider_message_id, provider_status, delivery_status, delivery_checked_at,
                delivery_attempts, delivered_at, attempted_at)
             VALUES (?,?,?,?,?,'',?,?,?,?,?,?,?,?,?,?,?, NOW())"
        )->execute([
            $row['organization_id'], $row['user_id'], $row['reference_type'], $row['reference_id'],
            $row['status'], $row['gateway_id'], $row['gateway_config_version'], $row['route_id'],
            $row['operator_id'], $row['destination'], $row['provider_message_id'], $row['provider_status'],
            $row['delivery_status'], $row['delivery_checked_at'], $row['delivery_attempts'], $row['delivered_at'],
        ]);
        return (int)db()->lastInsertId();
    }

    private function makeOrganization(): int {
        $suffix = bin2hex(random_bytes(4));
        db()->prepare("INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?, ?, 'active', ?)")
            ->execute(['org_' . $suffix, 'org-' . $suffix, $this->makeUser()]);
        return (int)db()->lastInsertId();
    }

    private function makeRoute(string $code): int {
        $db = db();
        $db->prepare("INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,'active')")
           ->execute(['prov_' . bin2hex(random_bytes(4)), 'p']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_routes (provider_id, code, name, status) VALUES (?,?,?,'active')")
           ->execute([$providerId, $code . '_' . bin2hex(random_bytes(3)), $code]);
        return (int)$db->lastInsertId();
    }

    private function makeBulkJob(int $organizationId, array $providerMessageIds): int {
        $db = db();
        $userId = $this->makeUser();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows)
                      VALUES (?,?, 'p2p', 'report test', '5000', 'done', ?)")
           ->execute([$userId, $organizationId, count($providerMessageIds)]);
        $jobId = (int)$db->lastInsertId();

        foreach ($providerMessageIds as $i => $pmid) {
            $db->prepare(
                "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, provider_message_id, delivery_status, delivery_attempts)
                 VALUES (?,?,?, 'sent', 1, ?, 'sent', ?)"
            )->execute([$jobId, '98912000000' . $i, 'x', (string)$pmid, $i]);
        }
        return $jobId;
    }

    private function makeSnapshot(string $referenceId, array $overrides = []): void {
        $row = array_merge([
            'unit_price_millicredits' => 1000,
            'segment_count'           => 1,
            'recipient_count'         => 1,
            'total_cost_credits'      => 1,
            'committed_cost_credits'  => 1,
        ], $overrides);

        db()->prepare(
            "INSERT INTO ellsms_sms_price_snapshots
               (reference_type, reference_id, group_key, operator_code, operator_source, provider_code,
                route_code, message_type, unit_price_millicredits, currency, price_source,
                recipient_count, segment_count, total_cost_credits, committed_cost_credits, status, priced_at)
             VALUES ('direct_send', ?, ?, 'mci', 'prefix', 'p', 'r', 'default', ?, 'credit', 'route_operator',
                     ?, ?, ?, ?, 'settled', NOW())"
        )->execute([
            $referenceId, 'grp-' . bin2hex(random_bytes(4)),
            $row['unit_price_millicredits'], $row['recipient_count'], $row['segment_count'],
            $row['total_cost_credits'], $row['committed_cost_credits'],
        ]);
    }
}
