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

    public function testPlaceNameAndReverseLookupAndDigitPrefix(): void
    {
        $geocoder = new PostalGeocoder(
            ['80331' => [48.13, 11.57, 'München'], '80333' => [48.14, 11.57, 'München'], '01067' => [51.06, 13.72, 'Dresden']],
            [],
            ['muenchen' => '80331', 'dresden' => '01067'],
        );

        self::assertSame('München', $geocoder->place('80331'));
        self::assertNull($geocoder->place('99999'));
        self::assertSame('80331', $geocoder->postalCodeFor('München'));
        self::assertSame('80331', $geocoder->postalCodeFor('  münchen '));
        self::assertNull($geocoder->postalCodeFor('Nirgendwo'));

        $hits = $geocoder->startingWith('803');
        self::assertSame(
            [['code' => '80331', 'place' => 'München'], ['code' => '80333', 'place' => 'München']],
            $hits,
        );
        self::assertSame([], $geocoder->startingWith('abc'));
        self::assertCount(1, $geocoder->startingWith('803', 1));
    }

    public function testBundledTableCarriesTownNames(): void
    {
        $geocoder = PostalGeocoder::default();

        self::assertSame('Berlin', $geocoder->place('10115'));
        self::assertSame('München', $geocoder->place((string) $geocoder->postalCodeFor('München')));
    }

    public function testClassifiesGermanAndNeighbourLocations(): void
    {
        self::assertSame(['country' => 'DE', 'code' => '72768'], PostalGeocoder::classify('72768 Reutlingen'));
        self::assertSame(['country' => 'DE', 'code' => '10115'], PostalGeocoder::classify('D-10115 Berlin'));
        self::assertSame(['country' => 'AT', 'code' => '5020'], PostalGeocoder::classify('A-5020 Salzburg'));
        self::assertSame(['country' => 'AT', 'code' => '7122'], PostalGeocoder::classify('AT-7122 Gols'));
        self::assertSame(['country' => 'CH', 'code' => '8001'], PostalGeocoder::classify('CH-8001 Zürich'));
        self::assertSame(['country' => 'LI', 'code' => '9490'], PostalGeocoder::classify('FL-9490 Vaduz'));

        // A bare four-digit code needs the region to name the country.
        self::assertSame(['country' => 'AT', 'code' => '5020'], PostalGeocoder::classify('5020 Salzburg', 'Österreich'));
        self::assertNull(PostalGeocoder::classify('5020 Salzburg'));
        self::assertNull(PostalGeocoder::classify('90210 Beverly Hills', 'USA'));
        self::assertNull(PostalGeocoder::classify('Irgendwo'));
        self::assertNull(PostalGeocoder::classify(null));
    }

    public function testLocatesNeighbourCodesFromTheForeignTable(): void
    {
        $geocoder = new PostalGeocoder(
            ['10115' => [52.53, 13.38]],
            [],
            [],
            ['AT' => ['5020' => [47.7994, 13.044, 'Salzburg']], 'CH' => ['8001' => [47.37, 8.54, 'Zürich']]],
        );

        $austria = $geocoder->locate('A-5020 Salzburg', 'Österreich');
        self::assertNotNull($austria);
        self::assertSame(47.7994, $austria->latitude);
        self::assertFalse($austria->approximate);

        self::assertNotNull($geocoder->locate('8001 Zürich', 'Schweiz'));
        self::assertNull($geocoder->locate('A-9999 Nirgendwo', 'Österreich'));

        self::assertSame('at-5020', PostalGeocoder::mapKey(['country' => 'AT', 'code' => '5020']));
        self::assertSame('10115', PostalGeocoder::mapKey(['country' => 'DE', 'code' => '10115']));
    }

    public function testBundledTableCarriesNeighbourCodes(): void
    {
        $geocoder = PostalGeocoder::default();

        $salzburg = $geocoder->locate('A-5020 Salzburg', 'Österreich');
        self::assertNotNull($salzburg);
        self::assertEqualsWithDelta(47.8, $salzburg->latitude, 0.2);
        self::assertEqualsWithDelta(13.05, $salzburg->longitude, 0.2);

        $zurich = $geocoder->locate('CH-8001 Zürich');
        self::assertNotNull($zurich);
        self::assertEqualsWithDelta(47.37, $zurich->latitude, 0.2);
    }
}
