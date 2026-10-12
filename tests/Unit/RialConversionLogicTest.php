<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The pure arithmetic of the credit -> Rial conversion (app/RialConversion.php). */
final class RialConversionLogicTest extends TestCase
{
    public function testABalanceWithoutAnOpeningIsMultiplied(): void
    {
        $this->assertSame(['balance' => 60000, 'clamped' => false], \rial_conversion_wallet_target(50, 0, 1200, true));
    }

    public function testTheBackfilledOpeningStaysRialAndOnlyLaterMovementIsMultiplied(): void
    {
        // Opened at 500,000 Rial, then +100 and -10 credits: 500,000 + 90 * 1200.
        $this->assertSame(['balance' => 608000, 'clamped' => false], \rial_conversion_wallet_target(500090, 500000, 1200, true));
    }

    public function testTheOpeningIsMultipliedWhenTreatedAsCredits(): void
    {
        $this->assertSame(['balance' => 600108000, 'clamped' => false], \rial_conversion_wallet_target(500090, 500000, 1200, false));
    }

    public function testANegativeResultIsClampedToZeroAndReported(): void
    {
        $this->assertSame(['balance' => 0, 'clamped' => true], \rial_conversion_wallet_target(100, 1000, 1200, true));
    }

    public function testSettingValuesScale(): void
    {
        $this->assertSame('600000,1200000', \rial_conversion_scale_list('500, 1000,,x', 1200));
        $this->assertSame('120000', \rial_conversion_scale_number('100', 1200));
        $this->assertSame('120', \rial_conversion_scale_number('0.1', 1200));
        $this->assertSame('1.2', \rial_conversion_scale_number('0.001', 1200));
    }
}
