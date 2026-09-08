<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\HuntMap;
use Spezitest\Website\Catalog\RatedDrinkCollection;

final class HuntMapTest extends TestCase
{
    private function geocoder(): PostalGeocoder
    {
        return new PostalGeocoder(
            ['74939' => [49.2964, 8.8225], '30419' => [52.4, 9.7]],
            ['749' => [49.2, 9.2]],
        );
    }

    public function testGroupsIdentifiedDrinksBySharedPostalCodeAndIgnoresOtherLifecycles(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Zwei B', 'identified', 'Brauerei B', false, '2026-01-01 00:00:00', '74939 Zuzenhausen', 'Baden-Württemberg'),
            CatalogFixture::untested('Zwei A', 'identified', 'Brauerei A', false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::untested('Solo', 'identified', null, false, '2026-01-01 00:00:00', '30419 Hannover'),
            CatalogFixture::untested('Schon im Kasten', 'acquired', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::tested('Fertig', ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]]),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());

        self::assertSame(3, $map->placed);
        self::assertSame([], $map->unplaced);
        self::assertCount(2, $map->points);

        // Busiest place first, and the drinks within it are sorted by name.
        self::assertSame('Zuzenhausen', $map->points[0]['place']);
        self::assertSame(2, $map->points[0]['count']);
        self::assertSame(['Zwei A', 'Zwei B'], array_column($map->points[0]['drinks'], 'name'));
        self::assertFalse($map->points[0]['approximate']);
    }

    public function testDrinksWithoutAGermanPostalCodeAreListedAsUnplaced(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Österreicher', 'identified', null, false, '2026-01-01 00:00:00', 'A-5020 Salzburg', 'Österreich'),
            CatalogFixture::untested('Platzierbar', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());

        self::assertSame(1, $map->placed);
        self::assertCount(1, $map->unplaced);
        self::assertSame('Österreicher', $map->unplaced[0]['name']);
        self::assertSame('Österreich', $map->unplaced[0]['location']);
        self::assertSame(2, $map->total());
    }

    public function testApproximatePlacementIsFlaggedThroughToTheMarkers(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Ungefähr', 'identified', 'Limo GmbH', false, '2026-01-01 00:00:00', '74911 Nirgendwo'),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());
        $markers = $map->markers();

        self::assertCount(1, $markers);
        self::assertTrue($markers[0]['approximate']);
        self::assertSame('Limo GmbH', $markers[0]['drinks'][0]['sub']);
        self::assertSame(49.2, $markers[0]['lat']);
    }

    public function testEmptyWhenNothingIsIdentified(): void
    {
        $map = HuntMap::fromCollection(new RatedDrinkCollection([]), $this->geocoder());

        self::assertTrue($map->isEmpty());
        self::assertSame(0, $map->total());
    }

    public function testWaypointsCarryTheLabelAndDrinkNames(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Zwei A', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::untested('Zwei B', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::untested('Solo', 'identified', null, false, '2026-01-01 00:00:00', '30419 Hannover'),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());
        $all = $map->waypoints();

        self::assertCount(2, $all);
        self::assertSame('Zuzenhausen · 2 Spezis', $all[0]['name']);
        self::assertSame('Zwei A, Zwei B', $all[0]['description']);
        self::assertSame(49.2964, $all[0]['latitude']);

        $one = $map->waypointsForPostalCode('30419');
        self::assertCount(1, $one);
        self::assertSame('Solo', $one[0]['name']);
        self::assertNull($one[0]['description']);

        self::assertSame([], $map->waypointsForPostalCode('99999'));
    }
}
