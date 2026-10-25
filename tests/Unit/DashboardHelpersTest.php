<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Dashboard.php';

/** The pure parts of the dashboard: the change against yesterday, the delivery rate and the chart scale. */
final class DashboardHelpersTest extends TestCase
{
    public function testChangePercentAgainstYesterday(): void
    {
        self::assertSame(12, dashboard_change_percent(112, 100));
        self::assertSame(-50, dashboard_change_percent(50, 100));
        self::assertSame(0, dashboard_change_percent(7, 7));
        self::assertNull(dashboard_change_percent(30, 0), 'nothing yesterday: no percentage to show');
    }

    public function testDeliveryRateIsBoundedAndNeedsSends(): void
    {
        self::assertSame(95, dashboard_delivery_rate(95, 100));
        self::assertSame(100, dashboard_delivery_rate(12, 10), 'never above 100%');
        self::assertNull(dashboard_delivery_rate(0, 0));
    }

    public function testChartScaleUsesRoundStepsThatReachTheMaximum(): void
    {
        self::assertSame(['top' => 4, 'ticks' => [0, 1, 2, 3, 4]], dashboard_chart_scale(0));
        self::assertSame(['top' => 8, 'ticks' => [0, 2, 4, 6, 8]], dashboard_chart_scale(7));
        self::assertSame(['top' => 120, 'ticks' => [0, 30, 60, 90, 120]], dashboard_chart_scale(110));
        self::assertSame(['top' => 60000, 'ticks' => [0, 15000, 30000, 45000, 60000]], dashboard_chart_scale(50000));
        foreach ([1, 9, 99, 101, 777, 12345, 999999] as $max) {
            $scale = dashboard_chart_scale($max);
            self::assertGreaterThanOrEqual($max, $scale['top'], (string)$max);
            self::assertLessThan($max * 2 + 4, $scale['top'], (string)$max);
        }
    }
}
