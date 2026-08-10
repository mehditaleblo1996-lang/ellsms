<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic half of the SMS pricing engine (app/Sms/Pricing.php): prefix normalization,
 * longest-prefix matching, effective-dated price selection, and the money arithmetic.
 *
 * Every function exercised here takes its data as an ARGUMENT rather than reading the database —
 * that is deliberate in the engine's design, precisely so these rules can be proven without MySQL.
 * The DB-backed half (catalog loading, route selection, snapshots, legacy parity, concurrency) lives
 * in tests/Integration/SmsPricingTest.php and runs against real MySQL.
 *
 * Fixtures below are invented, NOT copied from the production seed: a test that asserted "0912 is
 * MCI" would be testing the seed data, not the matching rule, and would have to change every time a
 * regulator reassigns a block.
 */
final class SmsPricingTest extends TestCase
{
    /** Rule set in the shape sms_pricing_prefix_rules() returns. */
    private function rules(array $specs): array
    {
        $out = [];
        $id = 1;
        foreach ($specs as [$prefix, $operatorId, $code, $priority]) {
            $out[] = [
                'id' => $id++, 'normalized_prefix' => $prefix, 'prefix_length' => strlen($prefix),
                'priority' => $priority, 'operator_id' => $operatorId, 'operator_code' => $code,
                'operator_name' => strtoupper($code),
            ];
        }
        return $out;
    }

    /* ================= Prefix normalization (STEP 4) ================= */

    public function testPrefixNormalizationProducesTheSameInternationalFormAsNumberNormalization(): void
    {
        // The whole point: a prefix and a number must end up in one comparable space, so matching is
        // a plain str_starts_with() rather than a per-format special case.
        $this->assertSame('98912', \sms_pricing_normalize_prefix('0912'));
        $this->assertSame('98912', \sms_pricing_normalize_prefix('98912'));
        $this->assertSame('98912', \sms_pricing_normalize_prefix('+98 912'));
        $this->assertSame('98912', \sms_pricing_normalize_prefix('009 8912'));
        $this->assertSame('98912', \sms_pricing_normalize_prefix('912'), 'the bare mobile block form the old hard-coded map used');
        $this->assertStringStartsWith(\sms_pricing_normalize_prefix('0912'), (string)\normalize_msisdn('09121234567'));
    }

    public function testUnusablePrefixesAreRejectedRatherThanCoerced(): void
    {
        $this->assertNull(\sms_pricing_normalize_prefix(''));
        $this->assertNull(\sms_pricing_normalize_prefix('   '));
        $this->assertNull(\sms_pricing_normalize_prefix('abc'));
        $this->assertNull(\sms_pricing_normalize_prefix('0912345678901234567'), 'longer than any MSISDN');
    }

    public function testAWildcardOrRegexPrefixIsNotAPatternLanguage(): void
    {
        // Initial implementation is prefix-only by design (STEP 31) — '091*' must not become a
        // pattern, it just loses the non-digits and stays a literal prefix.
        $this->assertSame('98912', \sms_pricing_normalize_prefix('0912*'));
        $this->assertSame('9891', \sms_pricing_normalize_prefix('091.*'));
    }

    /* ================= Longest-prefix matching (STEP 5) ================= */

    public function testLongestPrefixWinsOverShorterOverlappingRules(): void
    {
        $rules = $this->rules([
            ['989', 1, 'broad', 0],
            ['9891', 2, 'medium', 0],
            ['98912', 3, 'narrow', 0],
        ]);
        $match = \sms_pricing_match_prefix($rules, '989121234567');
        $this->assertSame('narrow', $match['operator_code'], '98912 must beat 9891 and 989');
    }

    public function testRuleOrderInTheInputDoesNotChangeTheAnswer(): void
    {
        $forwards = $this->rules([['989', 1, 'broad', 0], ['98912', 3, 'narrow', 0]]);
        $backwards = array_reverse($forwards);
        $this->assertSame(
            \sms_pricing_match_prefix($forwards, '989121234567')['operator_code'],
            \sms_pricing_match_prefix($backwards, '989121234567')['operator_code'],
            'matching must be deterministic regardless of how the catalog happened to be returned'
        );
    }

    public function testAnUnmatchedNumberReturnsNullRatherThanAGuess(): void
    {
        $rules = $this->rules([['98912', 1, 'mci', 0]]);
        $this->assertNull(\sms_pricing_match_prefix($rules, '989351234567'));
        $this->assertNull(\sms_pricing_match_prefix($rules, '447700900000'), 'a foreign number is unknown, not "closest match"');
        $this->assertNull(\sms_pricing_match_prefix($rules, ''));
        $this->assertNull(\sms_pricing_match_prefix([], '989121234567'), 'an empty catalog matches nothing');
    }

    public function testAmongEqualLengthPrefixesHigherPriorityWins(): void
    {
        // Same length can only happen for DIFFERENT prefixes (the database forbids two active rules
        // on one prefix), so this tie-break is about ordering stability, not about resolving a
        // genuine ambiguity.
        $rules = $this->rules([
            ['98912', 1, 'low', 0],
            ['98913', 2, 'high', 50],
        ]);
        $this->assertSame('low', \sms_pricing_match_prefix($rules, '989121234567')['operator_code']);
        $this->assertSame('high', \sms_pricing_match_prefix($rules, '989131234567')['operator_code']);
    }

    public function testAnIdenticalPrefixTieResolvesDeterministicallyByPriorityThenId(): void
    {
        // This state is supposed to be impossible (uniq_active_prefix) and the integrity check
        // reports it — but if it ever exists, the engine must still answer the SAME way every time
        // rather than picking at random (STEP 5: "do not silently choose random matches").
        $rules = $this->rules([
            ['98912', 1, 'first', 0],
            ['98912', 2, 'second', 10],
        ]);
        $this->assertSame('second', \sms_pricing_match_prefix($rules, '989121234567')['operator_code']);
        $this->assertSame('second', \sms_pricing_match_prefix(array_reverse($rules), '989121234567')['operator_code']);
    }

    public function testMatchingWorksOnNormalizedLocalAndInternationalInputAlike(): void
    {
        $rules = $this->rules([['98912', 1, 'mci', 0]]);
        foreach (['09121234567', '+989121234567', '00989121234567', '9121234567'] as $raw) {
            $normalized = \normalize_msisdn($raw);
            $this->assertNotNull($normalized, "{$raw} should normalize");
            $this->assertSame('mci', \sms_pricing_match_prefix($rules, $normalized)['operator_code'], "failed for {$raw}");
        }
    }

    /* ================= Effective-dated price selection (STEP 11/46) ================= */

    /** Price rows in the shape sms_pricing_prices_for_route() returns. */
    private function price(int $id, ?int $operatorId, int $millicredits, string $from, ?string $to = null): array
    {
        return [
            'id' => $id, 'route_id' => 1, 'operator_id' => $operatorId,
            'price_per_segment_millicredits' => $millicredits, 'currency' => 'credit',
            'effective_from' => $from, 'effective_to' => $to,
        ];
    }

    public function testAnOperatorSpecificPriceBeatsTheRouteDefault(): void
    {
        $prices = [
            $this->price(1, null, 1000, '2026-01-01 00:00:00'),
            $this->price(2, 7, 1500, '2026-01-01 00:00:00'),
        ];
        $this->assertSame(1500, (int)\sms_pricing_select_price($prices, 7, '2026-06-01 00:00:00')['price_per_segment_millicredits']);
        $this->assertSame(1000, (int)\sms_pricing_select_price($prices, 9, '2026-06-01 00:00:00')['price_per_segment_millicredits'],
            'an operator with no specific rate falls to the route default');
        $this->assertSame(1000, (int)\sms_pricing_select_price($prices, null, '2026-06-01 00:00:00')['price_per_segment_millicredits'],
            'an UNKNOWN number uses the route default, never an arbitrary operator rate');
    }

    public function testThePriceInEffectDependsOnThePricingInstantNotOnWhichRowIsNewest(): void
    {
        $prices = [
            $this->price(1, null, 1000, '2026-01-01 00:00:00', '2026-06-01 00:00:00'),
            $this->price(2, null, 2000, '2026-06-01 00:00:00'),
        ];
        $this->assertSame(1000, (int)\sms_pricing_select_price($prices, null, '2026-03-15 12:00:00')['price_per_segment_millicredits']);
        $this->assertSame(2000, (int)\sms_pricing_select_price($prices, null, '2026-09-15 12:00:00')['price_per_segment_millicredits']);
    }

    public function testThePeriodIsHalfOpenSoClosingAndOpeningAtTheSameInstantYieldsExactlyOneAnswer(): void
    {
        // This is what makes the admin UI's "close the old period at T, open the new one at T"
        // produce one price at T rather than two overlapping ones (STEP 47).
        $prices = [
            $this->price(1, null, 1000, '2026-01-01 00:00:00', '2026-06-01 00:00:00'),
            $this->price(2, null, 2000, '2026-06-01 00:00:00'),
        ];
        $this->assertSame(2000, (int)\sms_pricing_select_price($prices, null, '2026-06-01 00:00:00')['price_per_segment_millicredits'],
            'at the exact boundary the NEW period applies — the old one has already ended');
    }

    public function testAPriceThatHasNotStartedOrHasEndedIsNeverSelected(): void
    {
        $prices = [$this->price(1, null, 1000, '2026-06-01 00:00:00', '2026-07-01 00:00:00')];
        $this->assertNull(\sms_pricing_select_price($prices, null, '2026-05-31 23:59:59'), 'not yet effective');
        $this->assertNull(\sms_pricing_select_price($prices, null, '2026-07-01 00:00:00'), 'already ended');
        $this->assertNotNull(\sms_pricing_select_price($prices, null, '2026-06-15 00:00:00'));
    }

    public function testNoUsablePriceReturnsNullSoTheCallerCanFailClosed(): void
    {
        $this->assertNull(\sms_pricing_select_price([], 7, '2026-06-01 00:00:00'));
        $this->assertNull(
            \sms_pricing_select_price([$this->price(1, 5, 1000, '2026-01-01 00:00:00')], 7, '2026-06-01 00:00:00'),
            "another operator's rate must never be borrowed"
        );
    }

    /* ================= Money arithmetic (STEP 12) ================= */

    public function testLegacyParityIsExactAtOneCreditPerSegment(): void
    {
        // The hard backward-compatibility requirement: a 1000-millicredit rate must reproduce the
        // pre-feature `sms_parts() * count` arithmetic EXACTLY, with no rounding anywhere.
        foreach ([1, 2, 3, 7, 40] as $segments) {
            $this->assertSame($segments, \sms_pricing_cost_for_segments($segments, \SMS_PRICE_SCALE));
        }
    }

    public function testFractionalRatesRoundUpPerMessage(): void
    {
        // Rounding is per MESSAGE, deliberately: it makes a recipient's cost a property of that
        // recipient alone, so splitting/retrying/reporting on one row reproduces the same number.
        $this->assertSame(2, \sms_pricing_cost_for_segments(1, 1500), '1.5 credits for one segment cannot be half-spent');
        $this->assertSame(3, \sms_pricing_cost_for_segments(2, 1500));
        $this->assertSame(1, \sms_pricing_cost_for_segments(1, 1));
        $this->assertSame(1, \sms_pricing_cost_for_segments(3, 100), '0.1 x 3 = 0.3 credits still costs a whole credit');
    }

    public function testZeroSegmentsOrZeroPriceCostsNothing(): void
    {
        $this->assertSame(0, \sms_pricing_cost_for_segments(0, 1000));
        $this->assertSame(0, \sms_pricing_cost_for_segments(3, 0));
    }

    public function testNoFloatEverParticipatesInACostComputation(): void
    {
        $cost = \sms_pricing_cost_for_segments(3, 1333);
        $this->assertIsInt($cost);
        $this->assertSame(4, $cost, '3 x 1.333 = 3.999 credits -> 4');
    }

    /* ================= Admin price parsing ================= */

    public function testAdminEnteredCreditPricesBecomeIntegerMillicredits(): void
    {
        $this->assertSame(1000, \sms_pricing_credits_to_millicredits('1'));
        $this->assertSame(1250, \sms_pricing_credits_to_millicredits('1.25'));
        $this->assertSame(1500, \sms_pricing_credits_to_millicredits('1.5'));
        $this->assertSame(1, \sms_pricing_credits_to_millicredits('0.001'));
        $this->assertSame(0, \sms_pricing_credits_to_millicredits('0'));
    }

    public function testMalformedOrOverPreciseAdminPricesAreRejected(): void
    {
        // Rejected, not silently rounded: an operator who typed a fourth decimal meant something,
        // and quietly discarding it would produce a tariff they did not enter.
        $this->assertNull(\sms_pricing_credits_to_millicredits('1.2345'));
        $this->assertNull(\sms_pricing_credits_to_millicredits('-1'));
        $this->assertNull(\sms_pricing_credits_to_millicredits('abc'));
        $this->assertNull(\sms_pricing_credits_to_millicredits(''));
        $this->assertNull(\sms_pricing_credits_to_millicredits('1e3'));
    }

    /* ================= Message types (STEP 16) ================= */

    public function testAnUnknownMessageTypeFallsBackToTheConfiguredDefaultRatherThanBeingTrusted(): void
    {
        // A client-supplied type is rejected at the API boundary, but the engine still refuses to
        // trust any value it is handed — a cheap 'otp' tariff must not be reachable by typo either.
        $this->assertContains(\sms_pricing_normalize_message_type('otp'), \SMS_MESSAGE_TYPES);
        $this->assertSame('otp', \sms_pricing_normalize_message_type('otp'));
        $this->assertContains(\sms_pricing_normalize_message_type('cheap_please'), \SMS_MESSAGE_TYPES);
        $this->assertContains(\sms_pricing_normalize_message_type(null), \SMS_MESSAGE_TYPES);
        $this->assertContains(\sms_pricing_normalize_message_type(''), \SMS_MESSAGE_TYPES);
    }

    /* ================= Pricing group identity (STEP 24) ================= */

    public function testGroupKeyIsStableForIdenticalDecisionsAndDistinctForDifferentOnes(): void
    {
        $base = [
            'route_id' => 1, 'operator_id' => 2, 'unit_price' => 1000,
            'message_type' => 'promotional', 'price_source' => 'route_operator',
        ];
        $this->assertSame(\sms_pricing_group_key($base), \sms_pricing_group_key($base));
        $this->assertNotSame(\sms_pricing_group_key($base), \sms_pricing_group_key(['unit_price' => 1500] + $base));
        $this->assertNotSame(\sms_pricing_group_key($base), \sms_pricing_group_key(['operator_id' => 3] + $base));
        $this->assertNotSame(\sms_pricing_group_key($base), \sms_pricing_group_key(['route_id' => 9] + $base));
    }
}
