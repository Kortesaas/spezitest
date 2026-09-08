<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;

final class PostalGeocoderTest extends TestCase
{
    private function geocoder(): PostalGeocoder
    {
        return new PostalGeocoder(
            ['74939' => [49.2964, 8.8225], '01067' => [51.06, 13.72]],
            ['749' => [49.2, 9.2], '010' => [51.05, 13.74]],
        );
    }

    public function testExactPostalCodeResolvesPrecisely(): void
    {
        $point = $this->geocoder()->locate('74939 Zuzenhausen');

        self::assertNotNull($point);
        self::assertSame(49.2964, $point->latitude);
        self::assertSame(8.8225, $point->longitude);
        self::assertFalse($point->approximate);
    }

    public function testUnknownCodeFallsBackToThreeDigitPrefixAndIsMarkedApproximate(): void
    {
        $point = $this->geocoder()->locate('74999 Irgendwo');

        self::assertNotNull($point);
        self::assertSame(49.2, $point->latitude);
        self::assertTrue($point->approximate);
    }

    public function testPostalCodeRunningIntoTheTownNameStillParses(): void
    {
        self::assertSame('74939', PostalGeocoder::postalCode('74939Zuzenhausen'));
    }

    public function testForeignAndBlankOriginsDoNotResolve(): void
    {
        $geocoder = $this->geocoder();

        self::assertNull($geocoder->locate('A-5020 Salzburg'));
        self::assertNull($geocoder->locate('CH-8000 Zürich'));
        self::assertNull($geocoder->locate(''));
        self::assertNull($geocoder->locate(null));
        self::assertNull(PostalGeocoder::postalCode('012345 too many digits'));
    }

    public function testBundledTableLoadsAndCoversARealCode(): void
    {
        $point = PostalGeocoder::default()->locate('10115 Berlin');

        self::assertNotNull($point);
        self::assertGreaterThan(52.0, $point->latitude);
        self::assertLessThan(53.0, $point->latitude);
        self::assertGreaterThan(12.0, $point->longitude);
        self::assertLessThan(14.5, $point->longitude);
    }
}
