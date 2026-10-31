<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pure parts of app/SharedService.php: text shapes, the promotional window, line order, the price-row grant. */
final class SharedServiceTest extends TestCase
{
    public function testTheSameTextWithOtherNumbersHasTheSameShape(): void
    {
        $a = shared_service_fingerprint('سفارش 1234 شما به مبلغ 250,000 ریال در تاریخ 1405/08/09 ارسال شد.');
        $b = shared_service_fingerprint('سفارش ۹۸۷ شما به مبلغ ۱۲۰۰۰ ریال در تاریخ ۱۴۰۵/۰۸/۱۰ ارسال شد.');
        self::assertSame($a, $b);
    }

    public function testInvisibleAndArabicVariantsDoNotMakeANewShape(): void
    {
        self::assertSame(
            shared_service_fingerprint('کد ورود شما: 12'),
            shared_service_fingerprint("كد  ورود\u{200C} شما: 99")
        );
    }

    public function testAnotherWordIsAnotherShape(): void
    {
        self::assertNotSame(
            shared_service_fingerprint('سفارش 12 شما ارسال شد.'),
            shared_service_fingerprint('سفارش 12 شما ارسال شد. تخفیف ویژه در shop.ir')
        );
    }

    public function testPromoWindow(): void
    {
        $tz = new DateTimeZone('Asia/Tehran');
        self::assertNull(shared_service_promo_next_slot(new DateTimeImmutable('2026-10-31 10:00', $tz), '08:00', '21:00'));
        self::assertSame('2026-10-31 08:00', shared_service_promo_next_slot(new DateTimeImmutable('2026-10-31 06:30', $tz), '08:00', '21:00')->format('Y-m-d H:i'));
        self::assertSame('2026-11-01 08:00', shared_service_promo_next_slot(new DateTimeImmutable('2026-10-31 21:00', $tz), '08:00', '21:00')->format('Y-m-d H:i'));
        // Over midnight: 20:00–02:00.
        self::assertNull(shared_service_promo_next_slot(new DateTimeImmutable('2026-10-31 01:00', $tz), '20:00', '02:00'));
        self::assertSame('2026-10-31 20:00', shared_service_promo_next_slot(new DateTimeImmutable('2026-10-31 12:00', $tz), '20:00', '02:00')->format('Y-m-d H:i'));
        // Same start and end: any hour.
        self::assertNull(shared_service_promo_next_slot(new DateTimeImmutable('2026-10-31 03:00', $tz), '00:00', '00:00'));
    }

    public function testParseTimeOfDay(): void
    {
        self::assertSame('08:05', shared_service_parse_hhmm('8:5'));
        self::assertSame('21:00', shared_service_parse_hhmm('۲۱:۰۰'));
        self::assertNull(shared_service_parse_hhmm('25:00'));
        self::assertNull(shared_service_parse_hhmm('nine'));
    }

    public function testLinesAreTriedByPriorityAndSpreadWithinOne(): void
    {
        $lines = [
            ['number' => '3000', 'priority' => 200],
            ['number' => '1000', 'priority' => 100],
            ['number' => '2000', 'priority' => 100],
        ];
        $seenFirst = [];
        for ($i = 0; $i < 40; $i++) {
            $order = shared_service_line_order($lines, '98912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT));
            self::assertCount(3, $order);
            self::assertSame('3000', $order[2], 'a lower priority is only a fallback');
            self::assertEqualsCanonicalizing(['1000', '2000'], array_slice($order, 0, 2));
            $seenFirst[$order[0]] = true;
        }
        self::assertCount(2, $seenFirst, 'both equal-priority lines get recipients');
        self::assertSame(
            shared_service_line_order($lines, '989121234567'),
            shared_service_line_order($lines, '989121234567'),
            'one recipient keeps the same line'
        );
        self::assertSame([], shared_service_line_order([], '989121234567'));
    }

    public function testThePriceRowOverrideLivesOnlyInsideItsCall(): void
    {
        self::assertNull(gateway_price_row_override());
        $inside = gateway_price_with_row(GATEWAY_PRICE_SHARED_PROMO, static fn() => gateway_price_row_override());
        self::assertSame(GATEWAY_PRICE_SHARED_PROMO, $inside);
        self::assertNull(gateway_price_row_override());
        self::assertTrue(gateway_price_is_virtual_row(GATEWAY_PRICE_SHARED_SERVICE));
        self::assertTrue(gateway_price_is_virtual_row(GATEWAY_PRICE_ALL));
        self::assertFalse(gateway_price_is_virtual_row(7));
    }
}
