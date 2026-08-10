<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * gregorian_to_jalali() / jalali_to_gregorian() (app/bootstrap.php) — the
 * hand-rolled Jalali calendar conversion every date on every page renders
 * through. app/bootstrap.php's own comment claims this was "verified
 * against known Nowruz dates" — these tests lock in that claim as an
 * executable check instead of a comment, using the calendar's own defining
 * property (Nowruz = the vernal equinox, always March 19-21) rather than
 * a hardcoded year mapping that would need to be re-verified by hand.
 */
final class JalaliCalendarTest extends TestCase
{
    /** @return list<array{0:int,1:int,2:int}> Gregorian [y,m,d] fixtures spanning ordinary days, a month boundary, and a leap day. */
    private static function gregorianFixtures(): array
    {
        return [
            [2024, 1, 15],
            [2024, 2, 29], // leap day
            [2024, 3, 20],
            [2023, 12, 31],
            [2000, 1, 1],
            [1999, 6, 30],
        ];
    }

    public function testRoundTripGregorianToJalaliAndBackIsLossless(): void
    {
        foreach (self::gregorianFixtures() as [$gy, $gm, $gd]) {
            [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
            $this->assertSame(
                [$gy, $gm, $gd],
                jalali_to_gregorian($jy, $jm, $jd),
                "round trip failed for {$gy}-{$gm}-{$gd}"
            );
        }
    }

    /**
     * Nowruz (1 Farvardin, i.e. Jalali new year) is defined as the day of
     * the March equinox — it must always land in the last two days of
     * March in the Gregorian calendar, for any Jalali year. This is the
     * calendar's own defining invariant, not a fact this test author has
     * to independently remember correctly.
     */
    public function testJalaliNewYearAlwaysFallsInLateMarch(): void
    {
        foreach ([1350, 1370, 1390, 1400, 1403, 1410] as $jalaliYear) {
            [$gy, $gm, $gd] = jalali_to_gregorian($jalaliYear, 1, 1);
            $this->assertSame(3, $gm, "Jalali year {$jalaliYear}'s new year should fall in March");
            $this->assertGreaterThanOrEqual(19, $gd, "Jalali year {$jalaliYear}'s new year day out of range");
            $this->assertLessThanOrEqual(21, $gd, "Jalali year {$jalaliYear}'s new year day out of range");
        }
    }

    public function testJalaliMonthsAdvanceCorrectlyAcrossAYearBoundary(): void
    {
        // 29 Esfand (last day of a non-leap Jalali year) -> next day must
        // roll over to 1 Farvardin of the following Jalali year.
        [$gy1, $gm1, $gd1] = jalali_to_gregorian(1402, 12, 29);
        $nextDayTimestamp = mktime(0, 0, 0, $gm1, $gd1 + 1, $gy1);
        [$jy2, $jm2, $jd2] = gregorian_to_jalali(
            (int)date('Y', $nextDayTimestamp),
            (int)date('n', $nextDayTimestamp),
            (int)date('j', $nextDayTimestamp)
        );
        $this->assertSame([1403, 1, 1], [$jy2, $jm2, $jd2]);
    }
}
