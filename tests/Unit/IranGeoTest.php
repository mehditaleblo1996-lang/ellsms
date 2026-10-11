<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** app/IranGeo.php — the fixed country / province / city lists behind the address selects. */
final class IranGeoTest extends TestCase
{
    public function testAllThirtyOneProvincesAreListedWithCities(): void
    {
        $this->assertCount(31, GEO_IRAN_PROVINCES);
        foreach (GEO_IRAN_PROVINCES as $province => $cities) {
            $this->assertNotEmpty($cities, "{$province} has no cities");
            $this->assertSame($cities, array_values(array_unique($cities)), "{$province} lists a city twice");
        }
    }

    public function testIranIsTheFirstAndDefaultCountry(): void
    {
        $this->assertSame(GEO_DEFAULT_COUNTRY, GEO_COUNTRIES[0]);
        $this->assertSame(GEO_COUNTRIES, array_values(array_unique(GEO_COUNTRIES)));
    }

    public function testCityLookupRespectsTheProvince(): void
    {
        $this->assertTrue(\geo_is_city('کرج'));
        $this->assertTrue(\geo_is_city('کرج', 'البرز'));
        $this->assertFalse(\geo_is_city('کرج', 'تهران'));
        $this->assertFalse(\geo_is_city('شهر ناموجود'));
    }

    public function testCitySelectOffersOnlyTheProvincesCities(): void
    {
        $html = \geo_city_select('city', '', 'قم');
        $this->assertStringContainsString('value="قم"', $html);
        $this->assertStringNotContainsString('value="تهران"', $html);
    }

    /** An address typed before the lists existed must still show — not silently blank on the next save. */
    public function testValueStoredBeforeTheListsIsKeptAsAnOption(): void
    {
        $html = \geo_city_select('city', 'شهرک قدیمی', 'تهران');
        $this->assertStringContainsString('<option value="شهرک قدیمی" selected>', $html);
    }
}
