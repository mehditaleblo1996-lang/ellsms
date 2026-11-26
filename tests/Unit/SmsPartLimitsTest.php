<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-gateway part sizes (app/SmsPartLimits.php, docs/gateway-part-limits.md): sms_parts() with a
 * gateway's own sizes, normalization, the admin form's validation, and the preview's segment block.
 */
final class SmsPartLimitsTest extends TestCase
{
    private const CUSTOM = ['fa_single' => 60, 'fa_multi' => 50, 'en_single' => 100, 'en_multi' => 90];

    public function testNullLimitsAreTheDefault(): void
    {
        $this->assertSame(1, sms_parts(str_repeat('س', 70), null));
        $this->assertSame(2, sms_parts(str_repeat('س', 71), null));
        $this->assertSame(sms_parts(str_repeat('a', 400)), sms_parts(str_repeat('a', 400), SMS_PART_LIMITS_DEFAULT));
    }

    public function testPersianCountedWithTheGatewaysSizes(): void
    {
        $this->assertSame(1, sms_parts(str_repeat('س', 60), self::CUSTOM));
        $this->assertSame(2, sms_parts(str_repeat('س', 61), self::CUSTOM));
        $this->assertSame(2, sms_parts(str_repeat('س', 100), self::CUSTOM));
        $this->assertSame(3, sms_parts(str_repeat('س', 101), self::CUSTOM));
    }

    public function testLatinCountedWithTheGatewaysSizes(): void
    {
        $this->assertSame(1, sms_parts(str_repeat('a', 100), self::CUSTOM));
        $this->assertSame(2, sms_parts(str_repeat('a', 101), self::CUSTOM));
        $this->assertSame(2, sms_parts(str_repeat('a', 180), self::CUSTOM));
        $this->assertSame(3, sms_parts(str_repeat('a', 181), self::CUSTOM));
    }

    public function testNormalizeFillsGapsAndCapsTheMultiPartSize(): void
    {
        $this->assertSame(SMS_PART_LIMITS_DEFAULT, sms_part_limits_normalize(null));
        $this->assertSame(SMS_PART_LIMITS_DEFAULT, sms_part_limits_normalize(['fa_single' => '', 'en_multi' => 0, 'fa_multi' => 'x']));
        $this->assertSame(['fa_single' => 60, 'fa_multi' => 60, 'en_single' => 160, 'en_multi' => 153],
            sms_part_limits_normalize(['fa_single' => 60]));
        $this->assertTrue(sms_part_limits_is_default([]));
        $this->assertFalse(sms_part_limits_is_default(self::CUSTOM));
    }

    public function testRowColumnsMapToLimits(): void
    {
        $row = ['part_fa_single' => '60', 'part_fa_multi' => '50', 'part_en_single' => null, 'part_en_multi' => null];
        $this->assertSame(['fa_single' => 60, 'fa_multi' => 50, 'en_single' => 160, 'en_multi' => 153], sms_part_limits_from_row($row));
    }

    public function testValidateAcceptsEmptyAsDefaultAndPersianDigits(): void
    {
        $r = sms_part_limits_validate(['fa_single' => '۶۰', 'fa_multi' => '50', 'en_single' => '', 'en_multi' => ' ']);
        $this->assertTrue($r['ok']);
        $this->assertSame(['fa_single' => 60, 'fa_multi' => 50, 'en_single' => null, 'en_multi' => null], $r['values']);
    }

    public function testValidateRejectsBadValues(): void
    {
        $this->assertFalse(sms_part_limits_validate(['fa_single' => 'abc'])['ok']);
        $this->assertFalse(sms_part_limits_validate(['fa_single' => '0'])['ok']);
        $this->assertFalse(sms_part_limits_validate(['en_single' => '5000'])['ok']);
        // A per-part size above the single-part size of the same alphabet (default single 70).
        $this->assertFalse(sms_part_limits_validate(['fa_multi' => '80'])['ok']);
        $this->assertFalse(sms_part_limits_validate(['en_single' => '120', 'en_multi' => '130'])['ok']);
    }

    public function testValidateCapsAnSmppGatewayToWhatOneSmsCarries(): void
    {
        $this->assertTrue(sms_part_limits_validate(['fa_single' => '75', 'fa_multi' => '67'], false)['ok']);
        $this->assertFalse(sms_part_limits_validate(['fa_single' => '75'], true)['ok']);
        $this->assertTrue(sms_part_limits_validate(['fa_single' => '65', 'fa_multi' => '60'], true)['ok']);
    }

    public function testPreviewSegmentBlockUsesTheSameSizes(): void
    {
        $r = cost_estimate_segments(str_repeat('س', 61), self::CUSTOM);
        $this->assertSame(2, $r['segments']);
        $this->assertSame(60, $r['single_segment_limit']);
        $this->assertSame(50, $r['concatenated_segment_limit']);
        $this->assertSame(39, $r['characters_remaining_in_segment']);
    }

    public function testNoSenderLineMeansTheDefault(): void
    {
        $this->assertSame(0, sms_gateway_id_for_sender(''));
        $this->assertSame(0, sms_gateway_id_for_sender(null));
        $this->assertSame(SMS_PART_LIMITS_DEFAULT, sms_gateway_part_limits(0));
    }

    public function testAiSecretaryFitsTheGatewaysSizes(): void
    {
        $fitted = ai_secretary_fit_parts(str_repeat('سلام دنیا ', 40), 1, self::CUSTOM);
        $this->assertSame(1, sms_parts($fitted, self::CUSTOM));
        $this->assertSame(60, ai_secretary_part_chars(1, true, self::CUSTOM));
        $this->assertSame(100, ai_secretary_part_chars(2, true, self::CUSTOM));
    }
}
