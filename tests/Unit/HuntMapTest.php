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
            [],
            [
                'AT' => ['5020' => [47.7994, 13.044, 'Salzburg']],
                'SE' => ['352' => [56.8777, 14.8091, 'Växjö']],
            ],
        );
    }

    public function testPlacesANeighbourDrinkUnderACountryNamespacedKey(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Alpen Cola', 'identified', 'Alm AG', false, '2026-01-01 00:00:00', 'A-5020 Salzburg', 'Österreich'),
            CatalogFixture::untested('Fremd Cola', 'identified', null, false, '2026-01-01 00:00:00', 'Beverly Hills', 'USA'),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());

        self::assertSame(1, $map->placed);
        self::assertCount(1, $map->points);
        self::assertSame('at-5020', $map->points[0]['key']);
        self::assertSame('5020', $map->points[0]['postalCode']);
        self::assertSame('Österreich', $map->points[0]['country']);
        self::assertSame('Salzburg', $map->points[0]['place']);
        self::assertSame(47.7994, $map->points[0]['latitude']);

        self::assertSame('at-5020', $map->markers()[0]['key']);
        self::assertSame('Österreich', $map->markers()[0]['country']);

        // The non-neighbour foreign origin stays in the side list.
        self::assertSame(['Fremd Cola'], array_column($map->unplaced, 'name'));

        // The per-place GPX resolves by the namespaced key.
        self::assertCount(1, $map->waypointsForKey('at-5020'));
    }

    public function testPlacesASwedishDrinkInSwedenRatherThanGermany(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Nord Cola', 'identified', 'ERT Godis', false, '2026-01-01 00:00:00', 'SE-35246 Växjö', 'Schweden'),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());

        self::assertSame(1, $map->placed);
        self::assertSame('se-352', $map->points[0]['key']);
        self::assertSame('Schweden', $map->points[0]['country']);
        self::assertSame('Växjö', $map->points[0]['place']);
        self::assertSame(56.8777, $map->points[0]['latitude']);
        self::assertSame([], $map->unplaced);
    }

    public function testFromDrinksPlacesWhateverListItIsGivenRegardlessOfLifecycle(): void
    {
        $tested = CatalogFixture::tested(
            'Getestete',
            ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]],
            null,
            null,
        );
        $acquired = CatalogFixture::untested('Gekaufte', 'acquired', null, false, '2026-01-01 00:00:00', '30419 Hannover');

        // fromCollection() still only sees the identified drinks...
        $collection = new RatedDrinkCollection([$tested, $acquired]);
        self::assertSame(0, HuntMap::fromCollection($collection, $this->geocoder())->placed);

        // ...but fromDrinks() places exactly the list handed to it.
        $map = HuntMap::fromDrinks([$acquired], $this->geocoder());
        self::assertSame(1, $map->placed);
        self::assertSame('Gekaufte', $map->points[0]['drinks'][0]['name']);
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

    public function testDrinksWithoutAPlaceablePostalCodeAreListedAsUnplaced(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Überseer', 'identified', null, false, '2026-01-01 00:00:00', 'Beverly Hills', 'USA'),
            CatalogFixture::untested('Platzierbar', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
        ]);

        $map = HuntMap::fromCollection($collection, $this->geocoder());

        self::assertSame(1, $map->placed);
        self::assertCount(1, $map->unplaced);
        self::assertSame('Überseer', $map->unplaced[0]['name']);
        self::assertSame('USA', $map->unplaced[0]['location']);
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
        self::assertNull($markers[0]['drinks'][0]['image']);
        self::assertSame(49.2, $markers[0]['lat']);
    }

    public function testMarkerCarriesThePackagePhotoUrlWhenThereIsOne(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Mit Bild', 'identified', null, true, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
        ]);

        $drink = $collection->all()[0];
        $markers = HuntMap::fromCollection($collection, $this->geocoder())->markers();

        self::assertSame('/spezi/' . $drink->id . '/bild', $markers[0]['drinks'][0]['image']);
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

        $one = $map->waypointsForKey('30419');
        self::assertCount(1, $one);
        self::assertSame('Solo', $one[0]['name']);
        self::assertNull($one[0]['description']);

        self::assertSame([], $map->waypointsForKey('99999'));
    }
}
