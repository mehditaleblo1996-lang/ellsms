<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The pure parts behind the panel topbar: the credit box colour and the notification «back» path. */
final class PanelTopbarTest extends TestCase
{
    public function testCreditLevelWithoutThresholdFlagsOnlyAnEmptyBalance(): void
    {
        self::assertSame('low', profile_credit_level(0, 0));
        self::assertSame('low', profile_credit_level(-500, 0));
        self::assertSame('', profile_credit_level(1, 0));
    }

    public function testCreditLevelFollowsTheThreshold(): void
    {
        self::assertSame('low', profile_credit_level(99_999, 100_000));
        self::assertSame('warn', profile_credit_level(100_000, 100_000));
        self::assertSame('warn', profile_credit_level(199_999, 100_000));
        self::assertSame('', profile_credit_level(200_000, 100_000));
    }

    public function testLocalReturnPathKeepsSameSitePaths(): void
    {
        self::assertSame('/dashboard', local_return_path('/dashboard'));
        self::assertSame('/messages/reports?page=2&status=failed', local_return_path(' /messages/reports?page=2&status=failed '));
    }

    public function testLocalReturnPathRefusesAnythingThatLeavesTheSite(): void
    {
        foreach (['', 'dashboard', '//evil.example', 'https://evil.example/', '/\\evil.example', "/x\r\nLocation: //evil", 'javascript:alert(1)'] as $bad) {
            self::assertNull(local_return_path($bad), $bad);
        }
    }
}
