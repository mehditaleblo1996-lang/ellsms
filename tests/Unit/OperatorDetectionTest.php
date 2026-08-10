<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * detect_operator() (app/bootstrap.php) — the per-operator breakdown used
 * in analytics.php. app/bootstrap.php's own comment is explicit that
 * OPERATOR_PREFIX_MAP is a static, best-effort table that can go stale —
 * these tests check the function's *logic* (correct prefix extraction,
 * correct fallback), not that the prefix table is exhaustive or
 * permanently accurate.
 */
final class OperatorDetectionTest extends TestCase
{
    public function testRecognizesMciPrefix(): void
    {
        $this->assertSame('همراه اول', detect_operator('989121234567'));
    }

    public function testRecognizesIrancellPrefix(): void
    {
        $this->assertSame('ایرانسل', detect_operator('989351234567'));
    }

    public function testRecognizesRightelPrefix(): void
    {
        $this->assertSame('رایتل', detect_operator('989201234567'));
    }

    public function testUnknownPrefixFallsBackToOtherUnknown(): void
    {
        $this->assertSame('سایر / نامشخص', detect_operator('989501234567'));
    }

    public function testMalformedInputFallsBackToOtherUnknown(): void
    {
        $this->assertSame('سایر / نامشخص', detect_operator('not-a-number'));
        $this->assertSame('سایر / نامشخص', detect_operator('12345'));
    }

    public function testNumberNotStartingWithNinetyEightFallsBackToOtherUnknown(): void
    {
        // A well-formed 12-digit number that isn't actually an Iranian
        // mobile number (wrong country code) must not be misattributed.
        $this->assertSame('سایر / نامشخص', detect_operator('442071234567'));
    }
}
