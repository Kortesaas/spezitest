<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\OriginMap;
use Spezitest\Website\Catalog\RatedDrinkCollection;

final class OriginMapTest extends TestCase
{
    public function testGermanPostalCodesArePlacedAndTheRestAreTallied(): void
    {
        $map = OriginMap::fromCollection(new RatedDrinkCollection([
            CatalogFixture::untested('Nord', 'identified', null, false, '2026-01-01 00:00:00', '30419 Hannover'),
            CatalogFixture::untested('Süd', 'identified', null, false, '2026-01-01 00:00:00', '80331 München'),
        ]));

        self::assertSame(2, $map->placed);
        self::assertSame(0, $map->unplaced);
        self::assertSame([], $map->elsewhere);
    }

    public function testForeignOriginsCountUnderTheirCountryNotOhneHerkunftsangabe(): void
    {
        $map = OriginMap::fromCollection(new RatedDrinkCollection([
            // Real Austrian postal code, no region column.
            CatalogFixture::untested('Alpen', 'identified', null, false, '2026-01-01 00:00:00', '5330 Fuschl am See'),
            CatalogFixture::untested('Lux', 'identified', null, false, '2026-01-01 00:00:00', 'L-1855 Luxemburg'),
            // Genuinely no origin.
            CatalogFixture::untested('Unbekannt', 'identified', null, false, '2026-01-01 00:00:00', null),
        ]));

        $tally = [];
        foreach ($map->elsewhere as $entry) {
            $tally[$entry['label']] = $entry['count'];
        }

        self::assertSame(1, $tally['Österreich'] ?? 0);
        self::assertSame(1, $tally['Luxemburg'] ?? 0);
        self::assertSame(1, $tally['Ohne Herkunftsangabe'] ?? 0);
    }
}
