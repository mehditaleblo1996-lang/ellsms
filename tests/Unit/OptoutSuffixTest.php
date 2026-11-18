<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * app/OptoutSuffix.php — «افزودن لغو۱۱ به انتهای پیام» on the panel send forms.
 */
final class OptoutSuffixTest extends TestCase
{
    public function testAppendsOnANewLine(): void
    {
        $this->assertSame("سلام، تخفیف ویژه\nلغو۱۱", optout_suffix_apply('سلام، تخفیف ویژه'));
    }

    public function testTrailingWhitespaceIsTrimmedBeforeAppending(): void
    {
        $this->assertSame("سلام\nلغو۱۱", optout_suffix_apply("سلام  \n\n"));
    }

    public function testEmptyTextStaysEmpty(): void
    {
        $this->assertSame('', optout_suffix_apply(''));
        $this->assertSame('   ', optout_suffix_apply('   '));
    }

    /** @return array<string, array{string}> */
    public static function alreadyMarked(): array
    {
        return [
            'latin digits'        => ["سلام\nلغو11"],
            'persian digits'      => ["سلام\nلغو۱۱"],
            'arabic digits'       => ["سلام\nلغو١١"],
            'with space'          => ['سلام لغو ۱۱'],
            'with colon'          => ['سلام. لغو: 11'],
            'in the middle'       => ['برای لغو11 بفرستید. با تشکر'],
        ];
    }

    #[DataProvider('alreadyMarked')]
    public function testNeverDoubled(string $text): void
    {
        $this->assertTrue(optout_suffix_present($text));
        $this->assertSame($text, optout_suffix_apply($text));
    }

    public function testIdempotent(): void
    {
        $once = optout_suffix_apply('پیام');
        $this->assertSame($once, optout_suffix_apply($once));
    }

    public function testOtherNumbersAreNotAMarker(): void
    {
        $this->assertFalse(optout_suffix_present('لغو 110'));
        $this->assertFalse(optout_suffix_present('لغو ۱۲'));
        $this->assertFalse(optout_suffix_present('کد 11'));
        $this->assertSame("لغو ۱۲\nلغو۱۱", optout_suffix_apply('لغو ۱۲'));
    }

    public function testMaybeRespectsTheFlag(): void
    {
        $this->assertSame('پیام', optout_suffix_maybe('پیام', false));
        $this->assertSame("پیام\nلغو۱۱", optout_suffix_maybe('پیام', true));
    }

    public function testRequestedDefaultsToOn(): void
    {
        $this->assertTrue(optout_suffix_requested([]));
        $this->assertTrue(optout_suffix_requested([OPTOUT_SUFFIX_FIELD => '1']));
        $this->assertFalse(optout_suffix_requested([OPTOUT_SUFFIX_FIELD => '0']));
    }

    public function testCheckboxHtml(): void
    {
        $on = optout_suffix_checkbox_html(true);
        $this->assertStringContainsString('type="hidden" name="add_optout" value="0"', $on);
        $this->assertStringContainsString(' checked', $on);
        $this->assertStringNotContainsString(' checked', optout_suffix_checkbox_html(false));
    }
}
