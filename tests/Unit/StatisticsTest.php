<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\Statistics;

final class StatisticsTest extends TestCase
{
    public function testEmptyCatalogYieldsNoRatingFigures(): void
    {
        $stats = Statistics::fromCollection(new RatedDrinkCollection([]));

        self::assertSame(0, $stats->testedCount);
        self::assertNull($stats->averageGesamt);
        self::assertNull($stats->averageByCategory['optik']);
        self::assertSame([], $stats->manufacturers);
        self::assertSame([], $stats->regionCounts);
    }

    public function testDerivesFiguresFromRealRows(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Alpha', [
                'manu' => [10, 10, 10], 'fabi' => [10, 10, 10], 'schorsch' => [10, 10, 10],
            ], 'Alpha Brauerei', 'Bayern'),
            CatalogFixture::tested('Beta', [
                'manu' => [0, 0, 0], 'fabi' => [0, 0, 0], 'schorsch' => [0, 0, 0],
            ], 'Alpha Brauerei', 'Bayern'),
            CatalogFixture::untested('Gamma', 'identified', null),
        ]);

        $stats = Statistics::fromCollection($collection);

        self::assertSame(2, $stats->testedCount);
        self::assertSame(3, $stats->total);
        self::assertSame(1, $stats->lifecycleCounts['identified']);
        self::assertNotNull($stats->averageGesamt);
        self::assertEqualsWithDelta(30.0, $stats->averageGesamt, 1.0E-9);
        self::assertEqualsWithDelta(5.0, $stats->testerAverages['manu'], 1.0E-9);

        self::assertNotNull($stats->bestByCategory['optik']);
        self::assertSame('Alpha', $stats->bestByCategory['optik']['name']);
        self::assertEqualsWithDelta(10.0, $stats->bestByCategory['optik']['value'], 1.0E-9);

        self::assertCount(1, $stats->manufacturers);
        self::assertSame('Alpha Brauerei', $stats->manufacturers[0]['name']);
        self::assertSame(2, $stats->manufacturers[0]['count']);

        self::assertSame([['region' => 'Bayern', 'count' => 2]], $stats->regionCounts);

        $distributionTotal = array_sum(array_map(static fn ($bin): int => $bin['count'], $stats->gesamtDistribution));
        self::assertSame(2, $distributionTotal);
    }
}
