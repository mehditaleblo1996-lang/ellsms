<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Operator logos (app/OperatorLogos.php) are found from codes and from Persian display names. */
final class OperatorLogosTest extends TestCase
{
    public function testCodesAndNamesMapToTheirLogo(): void
    {
        foreach (['mci', 'MCI', 'همراه اول', 'همراه‌اول', 'همراه اول (MCI)'] as $value) {
            $this->assertSame('mci', \operator_logo_key($value), $value);
        }
        foreach (['mtn', 'MTN', 'Irancell', 'ایرانسل'] as $value) {
            $this->assertSame('mtn', \operator_logo_key($value), $value);
        }
        foreach (['rightel', 'رایتل', 'رايتل'] as $value) {
            $this->assertSame('rightel', \operator_logo_key($value), $value);
        }
        $this->assertNull(\operator_logo_key('سایر / نامشخص'));
        $this->assertNull(\operator_logo_key(''));
        $this->assertNull(\operator_logo_key(null));
    }

    public function testEveryLogoFileExists(): void
    {
        foreach (array_keys(\OPERATOR_LOGO_NAMES) as $key) {
            $this->assertFileExists(dirname(__DIR__, 2) . "/public/assets/img/operators/{$key}.png");
            $this->assertFileExists(dirname(__DIR__, 2) . "/public/assets/img/operators/{$key}-icon.png");
        }
    }

    public function testLabelsFallBackToPlainEscapedText(): void
    {
        $this->assertSame('سایر &lt;x&gt;', \operator_label(null, 'سایر <x>'));
        $html = \operator_label('mtn', null, 24);
        $this->assertStringContainsString('/assets/img/operators/mtn-icon.png', $html);
        $this->assertStringContainsString('height:24px', $html);
        $this->assertStringContainsString('ایرانسل', $html);
    }
}
