<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\MapScope;
use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;

final class MapScopeTest extends TestCase
{
    public function testFromPathFallsBackToAll(): void
    {
        self::assertSame(MapScope::All, MapScope::fromPath(null));
        self::assertSame(MapScope::All, MapScope::fromPath('alle'));
        self::assertSame(MapScope::All, MapScope::fromPath('unbekannt'));
        self::assertSame(MapScope::Tested, MapScope::fromPath('getestet'));
        self::assertSame(MapScope::Sought, MapScope::fromPath('gesucht'));
    }

    public function testSelectDrinksPerScope(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Getestete', ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]]),
            CatalogFixture::untested('Gekaufte', 'acquired'),
            CatalogFixture::untested('Gesuchte', 'identified'),
        ]);

        $name = static fn (RatedDrink $d): string => $d->name;

        self::assertSame(['Gekaufte', 'Gesuchte', 'Getestete'], array_map($name, MapScope::All->selectDrinks($collection)));
        self::assertSame(['Getestete'], array_map($name, MapScope::Tested->selectDrinks($collection)));
        self::assertSame(['Gesuchte'], array_map($name, MapScope::Sought->selectDrinks($collection)));
    }
}
