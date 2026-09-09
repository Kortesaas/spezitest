<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Games\GameCatalog;

final class GameCatalogTest extends TestCase
{
    public function testGeographyUsesOnlyImagesWithExactCoordinates(): void
    {
        $exact = CatalogFixture::untested(
            'Exakte Flasche',
            hasImage: true,
            originLocation: '12345 Musterstadt',
        );
        $approximate = CatalogFixture::untested(
            'Ungefähre Flasche',
            hasImage: true,
            originLocation: '98765 Irgendwo',
        );
        $withoutImage = CatalogFixture::untested(
            'Unsichtbare Flasche',
            originLocation: '12345 Musterstadt',
        );
        $geocoder = new PostalGeocoder(
            ['12345' => [50.1, 8.6, 'Musterstadt']],
            ['987' => [49.4, 9.2]],
        );

        $rounds = GameCatalog::geography(
            new RatedDrinkCollection([$exact, $approximate, $withoutImage]),
            $geocoder,
        );

        self::assertCount(1, $rounds);
        self::assertSame('Exakte Flasche', $rounds[0]['name']);
        self::assertSame('/spezi/' . $exact->id . '/bild', $rounds[0]['image']);
        self::assertSame(50.1, $rounds[0]['latitude']);
    }

    public function testMemoryUsesImagesAndRealNameQuizDeduplicatesNames(): void
    {
        $first = CatalogFixture::untested('Doppelte Spezi', hasImage: true);
        $duplicate = CatalogFixture::untested('Doppelte Spezi');
        $other = CatalogFixture::untested('Andere Spezi', hasImage: true);
        $collection = new RatedDrinkCollection([$first, $duplicate, $other]);

        self::assertSame(
            ['Andere Spezi', 'Doppelte Spezi'],
            array_column(GameCatalog::memory($collection), 'name'),
        );
        self::assertSame(
            ['Andere Spezi', 'Doppelte Spezi'],
            array_column(GameCatalog::realNames($collection), 'name'),
        );
    }
}
