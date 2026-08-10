<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * to_persian_digits() / from_persian_digits() (app/bootstrap.php) — used
 * everywhere a date, amount, or user-typed 2FA/verification code crosses
 * the Persian-digit / ASCII-digit boundary. A regression here breaks
 * things as varied as displaying dates and reading back a submitted 2FA
 * code.
 */
final class PersianDigitsTest extends TestCase
{
    public function testAsciiDigitsConvertToPersianDigits(): void
    {
        $this->assertSame('۰۱۲۳۴۵۶۷۸۹', to_persian_digits('0123456789'));
    }

    public function testNonDigitCharactersAreLeftAlone(): void
    {
        $this->assertSame('۱۴۰۵/۰۴/۲۳ - ۱۰:۳۰', to_persian_digits('1405/04/23 - 10:30'));
    }

    public function testPersianDigitsConvertBackToAscii(): void
    {
        $this->assertSame('0123456789', from_persian_digits('۰۱۲۳۴۵۶۷۸۹'));
    }

    public function testArabicIndicDigitsAlsoConvertToAscii(): void
    {
        // login/2FA input can arrive with Arabic-Indic digits (٠١٢...) as
        // well as Persian ones (۰۱۲...) depending on the user's keyboard —
        // from_persian_digits() must normalize both to the same ASCII form.
        $this->assertSame('0123456789', from_persian_digits('٠١٢٣٤٥٦٧٨٩'));
    }

    public function testRoundTripAsciiToPersianAndBackIsLossless(): void
    {
        $original = '1234567890';
        $this->assertSame($original, from_persian_digits(to_persian_digits($original)));
    }

    public function testMixedDigitsAndTextRoundTrip(): void
    {
        $this->assertSame('کد ورود: 482913', from_persian_digits(to_persian_digits('کد ورود: 482913')));
    }
}
