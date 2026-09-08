<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\HuntMap;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Map\GeoJsonFeed;

final class GeoJsonFeedTest extends TestCase
{
    private function geocoder(): PostalGeocoder
    {
        return new PostalGeocoder(
            ['74939' => [49.2964, 8.8225], '30419' => [52.3705, 9.7332]],
            [],
        );
    }

    private function feed(RatedDrinkCollection $collection): GeoJsonFeed
    {
        return GeoJsonFeed::fromHuntMap(HuntMap::fromCollection($collection, $this->geocoder()));
    }

    public function testProducesAValidFeatureCollection(): void
    {
        $alpha = CatalogFixture::untested('Alpha Cola', 'identified', 'Brauerei A', false, '2026-01-01 00:00:00', '74939 Zuzenhausen');
        $collection = new RatedDrinkCollection([$alpha]);

        $document = $this->feed($collection)->toFeatureCollection();

        self::assertSame('FeatureCollection', $document['type']);
        self::assertCount(1, $document['features']);

        $feature = $document['features'][0];
        self::assertSame('Feature', $feature['type']);
        self::assertSame($alpha->id, $feature['id']);
        self::assertSame(
            [
                'name' => 'Alpha Cola',
                'description' => "Hersteller: **Brauerei A**\nOrt: **74939 Zuzenhausen**\n\n"
                    . "[[https://www.spezitest.de/spezi/{$alpha->id}|Auf spezitest.de ansehen]]",
                'place' => '74939 Zuzenhausen',
                'manufacturer' => 'Brauerei A',
            ],
            $feature['properties'],
        );
        self::assertSame('Point', $feature['geometry']['type']);
        // GeoJSON order is [longitude, latitude].
        self::assertSame([8.8225, 49.2964], $feature['geometry']['coordinates']);
    }

    public function testEmbedsThePackagePhotoAndDetailsInUmapSyntax(): void
    {
        $withPhoto = CatalogFixture::untested('Foto Cola', 'identified', 'Sprudel AG', true, '2026-01-01 00:00:00', '74939 Zuzenhausen');
        $without = CatalogFixture::untested('Ohne Foto', 'identified', null, false, '2026-01-01 00:00:00', '30419 Hannover');

        $features = GeoJsonFeed::fromHuntMap(
            HuntMap::fromCollection(new RatedDrinkCollection([$withPhoto, $without]), $this->geocoder()),
            'https://www.spezitest.de/',
        )->toFeatureCollection()['features'];

        $byName = [];
        foreach ($features as $feature) {
            $byName[$feature['properties']['name']] = $feature['properties'];
        }

        $image = 'https://www.spezitest.de/spezi/' . $withPhoto->id . '/bild';
        self::assertSame(
            [
                'name' => 'Foto Cola',
                'description' => "{{{$image}|110}}\n\nHersteller: **Sprudel AG**\nOrt: **74939 Zuzenhausen**\n\n"
                    . "[[https://www.spezitest.de/spezi/{$withPhoto->id}|Auf spezitest.de ansehen]]",
                'place' => '74939 Zuzenhausen',
                'manufacturer' => 'Sprudel AG',
                'image' => $image,
            ],
            $byName['Foto Cola'],
        );

        // No photo, no manufacturer: still a place line and the link, no image key.
        self::assertSame(
            [
                'name' => 'Ohne Foto',
                'description' => "Ort: **30419 Hannover**\n\n"
                    . "[[https://www.spezitest.de/spezi/{$without->id}|Auf spezitest.de ansehen]]",
                'place' => '30419 Hannover',
            ],
            $byName['Ohne Foto'],
        );
    }

    public function testOneFeaturePerDrinkEvenWhenTheyShareAPostalCode(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Zwei A', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::untested('Zwei B', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
        ]);

        $features = $this->feed($collection)->toFeatureCollection()['features'];

        self::assertCount(2, $features);
        self::assertSame(['Zwei A', 'Zwei B'], array_map(static fn (array $f): string => $f['properties']['name'], $features));
        self::assertSame($features[0]['geometry']['coordinates'], $features[1]['geometry']['coordinates']);
        self::assertNotSame($features[0]['id'], $features[1]['id']);
    }

    public function testExcludesDrinksThatAreNotOnThePublicMap(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Sichtbar', 'identified', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::untested('Erworben', 'acquired', null, false, '2026-01-01 00:00:00', '74939 Zuzenhausen'),
            CatalogFixture::tested('Getestet', ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]]),
            CatalogFixture::untested('Kein PLZ', 'identified', null, false, '2026-01-01 00:00:00', 'A-5020 Salzburg'),
        ]);

        $names = array_map(
            static fn (array $f): string => $f['properties']['name'],
            $this->feed($collection)->toFeatureCollection()['features'],
        );

        self::assertSame(['Sichtbar'], $names);
    }

    public function testEmptyCatalogYieldsAnEmptyFeatureCollection(): void
    {
        $document = GeoJsonFeed::fromHuntMap(
            HuntMap::fromCollection(new RatedDrinkCollection([]), $this->geocoder()),
        )->toFeatureCollection();

        self::assertSame('FeatureCollection', $document['type']);
        self::assertSame([], $document['features']);
    }

    public function testSerialisesToValidJson(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Ümlaut Cola', 'identified', null, false, '2026-01-01 00:00:00', '30419 Hannover'),
        ]);

        $json = json_encode($this->feed($collection)->toFeatureCollection(), JSON_THROW_ON_ERROR);
        /** @var array{type: string, features: list<array{properties: array{name: string}}>} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('FeatureCollection', $decoded['type']);
        self::assertSame('Ümlaut Cola', $decoded['features'][0]['properties']['name']);
    }
}
