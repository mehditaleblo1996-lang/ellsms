<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cost preview — the pure, database-free half of app/Cost/MessageCostEstimator.php: segment
 * analysis, pricing arithmetic, and the confirmation-drift check. The DB-backed half (eligibility
 * against a real blacklist, wallet/quota preview, zero-mutation, preview/actual parity) is covered
 * by tests/Integration/CostPreviewTest.php.
 *
 * The single most important property asserted here is that cost_estimate_segments() never disagrees
 * with sms_parts() — the estimator must not have its own segmentation opinion (Invariant D).
 */
final class CostEstimatorTest extends TestCase
{
    /* ================= Parity with the existing source of truth (Invariant D) ================= */

    public function testSegmentCountAlwaysMatchesSmsParts(): void
    {
        $cases = [
            '', 'a', 'Hello world',
            str_repeat('a', 159), str_repeat('a', 160), str_repeat('a', 161),
            str_repeat('a', 306), str_repeat('a', 307),
            'س', str_repeat('س', 69), str_repeat('س', 70), str_repeat('س', 71),
            str_repeat('س', 134), str_repeat('س', 135),
            'mixed لاتین and فارسی',
        ];
        foreach ($cases as $content) {
            $this->assertSame(
                \sms_parts($content),
                \cost_estimate_segments($content)['segments'],
                'the estimator must never compute a different segment count than the send path does'
            );
        }
    }

    /* ================= GSM-7 ================= */

    public function testGsm7EncodingIsDetectedForPlainAscii(): void
    {
        $r = \cost_estimate_segments('Hello there');
        $this->assertSame('gsm7', $r['encoding']);
        $this->assertSame(160, $r['single_segment_limit']);
        $this->assertSame(153, $r['concatenated_segment_limit']);
    }

    public function testGsm7SinglePartBoundary(): void
    {
        $this->assertSame(1, \cost_estimate_segments(str_repeat('a', 160))['segments']);
        $this->assertSame(2, \cost_estimate_segments(str_repeat('a', 161))['segments']);
    }

    public function testGsm7ConcatenatedSegmentsUse153CharacterParts(): void
    {
        $this->assertSame(2, \cost_estimate_segments(str_repeat('a', 306))['segments']);
        $this->assertSame(3, \cost_estimate_segments(str_repeat('a', 307))['segments']);
    }

    public function testGsm7RemainingCharactersInSingleSegment(): void
    {
        $r = \cost_estimate_segments(str_repeat('a', 100));
        $this->assertSame(60, $r['characters_remaining_in_segment']);
        $this->assertFalse($r['concatenated']);
    }

    /* ================= Unicode / Persian ================= */

    public function testPersianIsDetectedAsUnicodeNotGsm7(): void
    {
        // The assumption this whole feature exists to correct — Persian is UCS-2, so the usable
        // single-segment length is 70 characters, not 160.
        $r = \cost_estimate_segments('سلام');
        $this->assertSame('unicode', $r['encoding']);
        $this->assertSame(70, $r['single_segment_limit']);
        $this->assertSame(67, $r['concatenated_segment_limit']);
    }

    public function testUnicodeSinglePartBoundary(): void
    {
        $this->assertSame(1, \cost_estimate_segments(str_repeat('س', 70))['segments']);
        $this->assertSame(2, \cost_estimate_segments(str_repeat('س', 71))['segments']);
    }

    public function testUnicodeConcatenatedSegmentsUse67CharacterParts(): void
    {
        $this->assertSame(2, \cost_estimate_segments(str_repeat('س', 134))['segments']);
        $this->assertSame(3, \cost_estimate_segments(str_repeat('س', 135))['segments']);
    }

    public function testARealisticPersianSentenceIsTwoSegments(): void
    {
        $text = 'سلام دوست عزیز، سفارش شما با موفقیت ثبت شد و تا ۴۸ ساعت آینده ارسال خواهد شد. با تشکر از خرید شما.';
        $r = \cost_estimate_segments($text);
        $this->assertSame('unicode', $r['encoding']);
        $this->assertGreaterThan(70, $r['characters']);
        $this->assertSame(2, $r['segments'], 'a ~98-character Persian message is two SMS, not one');
        $this->assertTrue($r['concatenated']);
    }

    public function testASingleNonAsciiCharacterForcesUnicodeForTheWholeMessage(): void
    {
        // 100 ASCII chars would be one GSM-7 segment; adding one Persian char makes the whole
        // message UCS-2, so 101 chars becomes TWO segments.
        $ascii = str_repeat('a', 100);
        $this->assertSame(1, \cost_estimate_segments($ascii)['segments']);
        $mixed = $ascii . 'ش';
        $r = \cost_estimate_segments($mixed);
        $this->assertSame('unicode', $r['encoding']);
        $this->assertSame(2, $r['segments']);
    }

    public function testEmptyContentIsZeroSegments(): void
    {
        $this->assertSame(0, \cost_estimate_segments('')['segments']);
    }

    public function testRemainingCharactersInFinalConcatenatedSegment(): void
    {
        // 140 unicode chars = 3 segments (67+67 = 134, leaving 6 in the third).
        $r = \cost_estimate_segments(str_repeat('س', 140));
        $this->assertSame(3, $r['segments']);
        $this->assertSame(67 - 6, $r['characters_remaining_in_segment']);
    }

    /* ================= Pricing (STEP 6/7) ================= */

    /**
     * A finished pricing pass, in the exact shape sms_pricing_price_messages() returns, so the
     * rendering half can be tested without a database. Route pricing made this block route/operator
     * aware; the fixture below is what a single-route, single-rate install (the legacy-parity
     * default) actually produces.
     */
    private function pricedFixture(int $unitPriceMillicredits = 1000, string $source = 'route_default'): array
    {
        return [
            'ok' => true, 'priced_at' => '2026-08-09 10:00:00', 'message_type' => 'promotional',
            'currency' => 'credit', 'total_cost' => 6, 'legacy_fallback_used' => $source === 'legacy_fallback',
            'groups' => [[
                'group_key' => 'g1', 'operator_id' => 1, 'operator_code' => 'mci', 'operator_name' => 'MCI',
                'operator_source' => 'prefix', 'provider_id' => 1, 'provider_code' => 'legacy',
                'route_id' => 1, 'route_code' => 'default', 'message_type' => 'promotional',
                'unit_price' => $unitPriceMillicredits,
                'unit_price_credits' => $unitPriceMillicredits / 1000,
                'currency' => 'credit', 'price_source' => $source, 'pricing_rule_id' => 7,
                'recipients' => 3, 'segments' => 6, 'cost' => 6,
            ]],
        ];
    }

    public function testPricingBlockShapeAndUnit(): void
    {
        $p = \cost_pricing_block($this->pricedFixture(), false);
        $this->assertSame('credit_per_segment', $p['unit']);
        $this->assertSame(1.0, $p['credits_per_segment'], 'a 1000-millicredit rate is one credit per SMS segment');
        $this->assertSame(1000, $p['unit_price_millicredits']);
        $this->assertSame('route_default', $p['price_source']);
        // estimated_cost and every wallet figure are denominated in CREDITS; the Rial figure is a
        // separate, display-only conversion and is labelled as such.
        $this->assertSame('credit', $p['currency']);
        $this->assertSame('IRR', $p['rial_currency']);
        $this->assertArrayHasKey('estimator_version', $p);
        $this->assertArrayHasKey('priced_at', $p);
    }

    public function testPricingBlockReportsMixedRatesAsARangeRatherThanOneMisleadingNumber(): void
    {
        // Two operators at different rates: a single "unit price" would be a number no recipient is
        // actually charged, so it must be null and the range/groups must carry the truth (STEP 21).
        $priced = $this->pricedFixture();
        $second = $priced['groups'][0];
        $second['group_key'] = 'g2';
        $second['operator_code'] = 'mtn';
        $second['unit_price'] = 1500;
        $second['unit_price_credits'] = 1.5;
        $priced['groups'][] = $second;

        $p = \cost_pricing_block($priced, false);
        $this->assertNull($p['credits_per_segment']);
        $this->assertNull($p['unit_price_millicredits']);
        $this->assertSame(1000, $p['unit_price_min_millicredits']);
        $this->assertSame(1500, $p['unit_price_max_millicredits']);
        $this->assertCount(2, $p['groups']);
    }

    public function testAdminIsExemptExactlyAsTheSendPathIs(): void
    {
        // dispatch_message() charges an admin nothing; a preview claiming otherwise would be wrong.
        $p = \cost_pricing_block($this->pricedFixture(), true);
        $this->assertSame(0, $p['credits_per_segment']);
        $this->assertSame('admin_exempt', $p['price_source']);
    }

    public function testPricingBlockExposesNoProviderSecrets(): void
    {
        $encoded = json_encode(\cost_pricing_block($this->pricedFixture(), false));
        foreach (['secret', 'password', 'merchant', 'token', 'api_key'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string)$encoded);
        }
    }

    /* ================= Confirmation drift (STEP 21/22) ================= */

    public function testUnchangedCostDoesNotRequireReconfirmation(): void
    {
        $c = \cost_preview_confirmation_check(100, 100, time());
        $this->assertFalse($c['require_reconfirm']);
        $this->assertFalse($c['changed_materially']);
        $this->assertFalse($c['expired']);
    }

    public function testCostIncreaseBeyondThresholdRequiresReconfirmation(): void
    {
        // Default threshold is 5%.
        $this->assertFalse(\cost_preview_confirmation_check(100, 104, time())['changed_materially']);
        $this->assertTrue(\cost_preview_confirmation_check(100, 120, time())['changed_materially']);
    }

    public function testCostDecreaseBeyondThresholdAlsoRequiresReconfirmation(): void
    {
        // A large drop is equally worth re-showing — it usually means the recipient set changed.
        $this->assertTrue(\cost_preview_confirmation_check(100, 50, time())['changed_materially']);
    }

    public function testFreeToNotFreeIsAlwaysMaterial(): void
    {
        $this->assertTrue(\cost_preview_confirmation_check(0, 5, time())['changed_materially']);
    }

    public function testExpiredPreviewRequiresReconfirmationEvenWhenCostIsIdentical(): void
    {
        $stale = time() - (\cost_preview_ttl_seconds() + 60);
        $c = \cost_preview_confirmation_check(100, 100, $stale);
        $this->assertTrue($c['expired']);
        $this->assertTrue($c['require_reconfirm']);
        $this->assertFalse($c['changed_materially']);
    }

    public function testMissingPreviewTimestampIsNotTreatedAsExpired(): void
    {
        $this->assertFalse(\cost_preview_confirmation_check(100, 100, null)['expired']);
    }

    /* ================= Fingerprint (STEP 20) ================= */

    public function testFingerprintIsDeterministicAndInputSensitive(): void
    {
        $a = \cost_preview_fingerprint(1, '5000', hash('sha256', 'body'), 10);
        $b = \cost_preview_fingerprint(1, '5000', hash('sha256', 'body'), 10);
        $this->assertSame($a, $b);

        $this->assertNotSame($a, \cost_preview_fingerprint(2, '5000', hash('sha256', 'body'), 10), 'organization must affect it');
        $this->assertNotSame($a, \cost_preview_fingerprint(1, '6000', hash('sha256', 'body'), 10), 'sender must affect it');
        $this->assertNotSame($a, \cost_preview_fingerprint(1, '5000', hash('sha256', 'other'), 10), 'content must affect it');
        $this->assertNotSame($a, \cost_preview_fingerprint(1, '5000', hash('sha256', 'body'), 11), 'recipient count must affect it');
    }

    /* ================= Notes / honesty (Invariant G) ================= */

    public function testNotesAlwaysDeclareTheEstimateIsRevalidatedAtSend(): void
    {
        $n = \cost_preview_notes();
        $this->assertTrue($n['estimate_only']);
        $this->assertTrue($n['revalidated_at_send']);
        $this->assertGreaterThan(0, $n['ttl_seconds']);
    }

    public function testEveryReasonCodeHasAHumanMessage(): void
    {
        foreach (['sender_missing_or_invalid', 'sender_not_allowed', 'content_empty',
                  'no_items', 'no_eligible_recipients', 'campaign_not_found'] as $reason) {
            $this->assertNotSame('', \cost_preview_reason_message($reason));
            $this->assertNotSame($reason, \cost_preview_reason_message($reason), "reason '{$reason}' has no human message");
        }
    }
}
