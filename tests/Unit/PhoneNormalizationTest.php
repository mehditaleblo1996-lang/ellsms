<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * normalize_msisdn() / normalize_originator() / parse_destinations()
 * (app/bootstrap.php) — the normalization every send path in the app
 * depends on. A regression here silently corrupts destinations across
 * every send flow (direct, bulk, scheduled, auto-reply), so these are
 * exactly the "safe to test without a rewrite" business rules this
 * phase is meant to lock in.
 */
final class PhoneNormalizationTest extends TestCase
{
    public function testMobileStartingWithZeroNineIsRewrittenToInternational(): void
    {
        $this->assertSame('989123456789', normalize_msisdn('09123456789'));
    }

    public function testMobileWithPlusPrefixIsAccepted(): void
    {
        $this->assertSame('989123456789', normalize_msisdn('+989123456789'));
    }

    public function testMobileWithDoubleZeroPrefixIsAccepted(): void
    {
        $this->assertSame('989123456789', normalize_msisdn('00989123456789'));
    }

    public function testTenDigitMobileStartingWithNineIsRewrittenToInternational(): void
    {
        $this->assertSame('989123456789', normalize_msisdn('9123456789'));
    }

    public function testSurroundingWhitespaceAndSeparatorsAreStripped(): void
    {
        $this->assertSame('989123456789', normalize_msisdn(' 0912-345-6789 '));
    }

    public function testNonNumericInputIsRejected(): void
    {
        $this->assertNull(normalize_msisdn('not a number'));
    }

    public function testTooShortInputIsRejected(): void
    {
        $this->assertNull(normalize_msisdn('123'));
    }

    public function testEmptyInputIsRejected(): void
    {
        $this->assertNull(normalize_msisdn(''));
    }

    /**
     * A sender line/originator is NOT a mobile number — normalize_originator()
     * must never apply the 09->98 mobile rewrite, unlike normalize_msisdn().
     * This is the exact distinction app/bootstrap.php's own docblock warns
     * about ("this does NOT rewrite a leading 09 to 98... that rewrite
     * would corrupt it") — worth locking in explicitly.
     */
    public function testOriginatorIsNotRewrittenLikeAMobileNumber(): void
    {
        $this->assertSame('09123456789', normalize_originator('09123456789'));
    }

    public function testOriginatorStripsNonDigitsOnly(): void
    {
        $this->assertSame('3000123456', normalize_originator(' 3000-123-456 '));
    }

    public function testOriginatorRejectsEmptyResult(): void
    {
        $this->assertNull(normalize_originator('abc'));
    }

    public function testParseDestinationsDedupesAndNormalizes(): void
    {
        $result = parse_destinations("09123456789, 09123456789\n+989129999999;9351234567");
        sort($result);
        $this->assertSame(['989123456789', '989129999999', '989351234567'], $result);
    }

    public function testParseDestinationsSilentlyDropsInvalidEntries(): void
    {
        $result = parse_destinations('09123456789, not-a-number, 123');
        $this->assertSame(['989123456789'], $result);
    }

    public function testParseDestinationsAcceptsPersianComma(): void
    {
        $result = parse_destinations('09123456789،09129999999');
        sort($result);
        $this->assertSame(['989123456789', '989129999999'], $result);
    }

    /**
     * PHP silently casts numeric-string array keys to int — bootstrap.php's
     * own comment calls this out and works around it with strval(). If that
     * workaround ever regresses, every destination fed into json_encode()
     * for the backend API request would still look correct in PHP but the
     * array keys (not values) would be ints, which callers rely on NOT
     * being the case downstream (e.g. array_column-style consumption).
     */
    public function testParseDestinationsReturnsRealStringsNotIntCastNumerics(): void
    {
        foreach (parse_destinations('09123456789') as $destination) {
            $this->assertIsString($destination);
        }
    }

    public function testParseDestinationsOnEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], parse_destinations(''));
    }
}
