<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\Geo\LocationSearch;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\HuntMap;
use Spezitest\Website\Catalog\RatedDrinkCollection;

final class LocationSearchTest extends TestCase
{
    private function search(): LocationSearch
    {
        return new LocationSearch(
            new PostalGeocoder(['74939' => [49.2964, 8.8225]], ['749' => [49.2, 9.2]]),
            [
                'muenchen' => [48.1374, 11.5755, 'München'],
                'munich' => [48.1374, 11.5755, 'München'],
                'garmischpartenkirchen' => [47.4921, 11.0958, 'Garmisch-Partenkirchen'],
            ],
        );
    }

    private function map(): HuntMap
    {
        return HuntMap::fromCollection(
            new RatedDrinkCollection([
                CatalogFixture::untested('Dorf-Limo', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            ]),
            new PostalGeocoder(['74939' => [49.2964, 8.8225]], []),
        );
    }

    public function testResolvesAPostalCode(): void
    {
        $hit = $this->search()->search('74939', $this->map());

        self::assertNotNull($hit);
        self::assertSame(49.2964, $hit['latitude']);
        self::assertSame('74939', $hit['label']);
    }

    public function testResolvesATownByNameCaseAndUmlautInsensitively(): void
    {
        foreach (['München', 'muenchen', 'MUNICH', ' münchen '] as $term) {
            $hit = $this->search()->search($term, $this->map());
            self::assertNotNull($hit, $term);
            self::assertSame('München', $hit['label']);
            self::assertSame(48.1374, $hit['latitude']);
        }
    }

    public function testPrefersAPlaceThatActuallyHasASpezi(): void
    {
        $hit = $this->search()->search('Zuzenhausen', $this->map());

        self::assertNotNull($hit);
        self::assertSame('Zuzenhausen', $hit['label']);
        self::assertSame(49.2964, $hit['latitude']);
    }

    public function testUniquePrefixMatchResolves(): void
    {
        $hit = $this->search()->search('garmisch', $this->map());

        self::assertNotNull($hit);
        self::assertSame('Garmisch-Partenkirchen', $hit['label']);
    }

    public function testUnknownOrEmptyTermsReturnNull(): void
    {
        $search = $this->search();
        $map = $this->map();

        self::assertNull($search->search('', $map));
        self::assertNull($search->search('   ', $map));
        self::assertNull($search->search('zzznope', $map));
        self::assertNull($search->search('99999', $map));
    }

    public function testSuggestListsPrefixMatchesWithSpeziPlacesFirst(): void
    {
        $search = new LocationSearch(
            new PostalGeocoder(
                ['74939' => [49.2964, 8.8225, 'Münchsdorf'], '80331' => [48.1374, 11.5755, 'München']],
                [],
                ['muenchen' => '80331', 'muenchsdorf' => '74939'],
            ),
            [
                'muenchen' => [48.1374, 11.5755, 'München'],
                'muenchberg' => [50.19, 11.79, 'Münchberg'],
                'muenster' => [51.96, 7.63, 'Münster'],
            ],
        );
        $map = HuntMap::fromCollection(
            new RatedDrinkCollection([
                CatalogFixture::untested('Münchsdorfer', 'identified', null, false, '2026-01-01 00:00:00', '74939 Münchsdorf'),
            ]),
            new PostalGeocoder(['74939' => [48.5, 12.9]], []),
        );

        $items = $search->suggest('münch', $map);
        $labels = array_map(static fn (array $i): string => $i['label'], $items);

        self::assertContains('Münchsdorf', $labels);
        self::assertSame('Münchsdorf', $labels[0], 'a Spezi place comes first');
        self::assertContains('München', $labels);
        self::assertNotContains('Münster', $labels);
        self::assertSame('Spezi hier · PLZ 74939', $items[0]['sub']);
        self::assertSame('PLZ 80331', $items[(int) array_search('München', $labels, true)]['sub']);

        // Shortest matching name leads among the index hits.
        self::assertLessThan(
            array_search('Münchberg', $labels, true),
            array_search('München', $labels, true),
        );

        self::assertSame([], $search->suggest('m', $map));
    }

    public function testSuggestOnDigitsListsPostalCodesWithTheirTown(): void
    {
        $search = new LocationSearch(
            new PostalGeocoder(
                ['80331' => [48.13, 11.57, 'München'], '80333' => [48.14, 11.57, 'München'], '81929' => [48.16, 11.66, 'München']],
                [],
            ),
            [],
        );

        $items = $search->suggest('8033', $this->map());

        self::assertSame(['80331 München', '80333 München'], array_map(static fn (array $i): string => $i['label'], $items));
        self::assertNull($items[0]['sub']);
    }

    public function testBundledIndexLoadsAndFindsARealCity(): void
    {
        $hit = LocationSearch::default()->search('Regensburg', $this->map());

        self::assertNotNull($hit);
        self::assertSame('Regensburg', $hit['label']);
        self::assertGreaterThan(48.5, $hit['latitude']);
        self::assertLessThan(49.5, $hit['latitude']);
    }
}
