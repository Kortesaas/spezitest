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
        self::assertNull($stats->medianGesamt);
        self::assertNull($stats->averageByCategory['optik']);
        self::assertSame([], $stats->categoryLeaderboards['optik']);
        self::assertSame([], $stats->disagreements);
        self::assertNull($stats->testerAgreement);
        self::assertSame([], $stats->timeline);
        self::assertSame([], $stats->regionCounts);
        self::assertSame([], $stats->regionScores);
    }

    public function testDerivesCoreFiguresFromRealRows(): void
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
        self::assertEqualsWithDelta(30.0, $stats->averageGesamt ?? -1.0, 1.0E-9);
        self::assertEqualsWithDelta(30.0, $stats->medianGesamt ?? -1.0, 1.0E-9);
        self::assertEqualsWithDelta(60.0, $stats->bestGesamt ?? -1.0, 1.0E-9);
        self::assertEqualsWithDelta(5.0, $stats->testerAverages['manu'] ?? -1.0, 1.0E-9);
        self::assertSame([['region' => 'Bayern', 'count' => 2]], $stats->regionCounts);

        $distributionTotal = array_sum(array_map(static fn (array $bin): int => $bin['count'], $stats->gesamtDistribution));
        self::assertSame(2, $distributionTotal);
    }

    public function testCategoryLeaderboardsAreTopFiveByCategoryAverage(): void
    {
        // Geschmack ranks Cola > Bravo > Alpha; Optik ranks the reverse.
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Alpha', ['manu' => [9, 5, 2], 'fabi' => [9, 5, 2], 'schorsch' => [9, 5, 2]]),
            CatalogFixture::tested('Bravo', ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]]),
            CatalogFixture::tested('Cola', ['manu' => [2, 5, 9], 'fabi' => [2, 5, 9], 'schorsch' => [2, 5, 9]]),
        ]);

        $stats = Statistics::fromCollection($collection);

        self::assertSame(
            ['Cola', 'Bravo', 'Alpha'],
            array_map(static fn (array $e): string => $e['name'], $stats->categoryLeaderboards['geschmack']),
        );
        self::assertSame(
            ['Alpha', 'Bravo', 'Cola'],
            array_map(static fn (array $e): string => $e['name'], $stats->categoryLeaderboards['optik']),
        );
        self::assertEqualsWithDelta(9.0, $stats->categoryLeaderboards['geschmack'][0]['value'], 1.0E-9);
    }

    public function testTesterProfilesCarryFavouriteHarshestAndCategoryAverages(): void
    {
        // Manu loves "Süß", pans "Herb"; Fabi is the opposite.
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Süß', ['manu' => [8, 8, 8], 'fabi' => [2, 2, 2], 'schorsch' => [5, 5, 5]]),
            CatalogFixture::tested('Herb', ['manu' => [2, 2, 2], 'fabi' => [8, 8, 8], 'schorsch' => [5, 5, 5]]),
        ]);

        $stats = Statistics::fromCollection($collection);

        $byCode = [];
        foreach ($stats->testerProfiles as $profile) {
            $byCode[$profile['code']] = $profile;
        }

        self::assertSame(['manu', 'fabi', 'schorsch'], array_keys($byCode));
        self::assertSame('Süß', $byCode['manu']['favourite']['name'] ?? null);
        self::assertSame('Herb', $byCode['manu']['harshest']['name'] ?? null);
        self::assertSame('Herb', $byCode['fabi']['favourite']['name'] ?? null);
        // Manu's favourite total: 8*1 + 8*2 + 8*3 = 48.
        self::assertEqualsWithDelta(48.0, $byCode['manu']['favourite']['value'], 1.0E-9);
        self::assertEqualsWithDelta(5.0, $byCode['manu']['byCategory']['optik'] ?? -1.0, 1.0E-9);
    }

    public function testDisagreementsAreOrderedBySpreadAndAgreementNamesTheClosestPair(): void
    {
        $collection = new RatedDrinkCollection([
            // Wide: Manu 6, Fabi 60, Schorsch 6 → spread 54.
            CatalogFixture::tested('Streit', ['manu' => [1, 1, 1], 'fabi' => [10, 10, 10], 'schorsch' => [1, 1, 1]]),
            // Narrow: everyone the same → spread 0.
            CatalogFixture::tested('Einig', ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]]),
        ]);

        $stats = Statistics::fromCollection($collection);

        self::assertSame('Streit', $stats->disagreements[0]['name']);
        self::assertSame('Einig', $stats->disagreements[1]['name']);
        self::assertEqualsWithDelta(54.0, $stats->disagreements[0]['spread'], 1.0E-9);
        // Manu and Schorsch never disagree here.
        self::assertNotNull($stats->testerAgreement);
        self::assertSame('Manu & Schorsch', $stats->testerAgreement['pair']);
        self::assertEqualsWithDelta(0.0, $stats->testerAgreement['meanSpread'], 1.0E-9);
    }

    public function testTimelineGroupsByStreamWithARunningAverage(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('A1', ['manu' => [0, 0, 0], 'fabi' => [0, 0, 0], 'schorsch' => [0, 0, 0]], null, null, null, 500, '2026-01-01 00:00:00', 1),
            CatalogFixture::tested('A2', ['manu' => [0, 0, 0], 'fabi' => [0, 0, 0], 'schorsch' => [0, 0, 0]], null, null, null, 500, '2026-01-01 00:00:00', 1),
            CatalogFixture::tested('B1', ['manu' => [10, 10, 10], 'fabi' => [10, 10, 10], 'schorsch' => [10, 10, 10]], null, null, null, 500, '2026-01-01 00:00:00', 2),
        ]);

        $stats = Statistics::fromCollection($collection);

        self::assertCount(2, $stats->timeline);
        self::assertSame([1, 2], array_map(static fn (array $t): int => $t['stream'], $stats->timeline));
        self::assertSame([2, 1], array_map(static fn (array $t): int => $t['count'], $stats->timeline));
        self::assertEqualsWithDelta(0.0, $stats->timeline[0]['averageGesamt'] ?? -1.0, 1.0E-9);
        self::assertEqualsWithDelta(60.0, $stats->timeline[1]['averageGesamt'] ?? -1.0, 1.0E-9);
        // Running average after both evenings: (0 + 0 + 60) / 3 = 20.
        self::assertEqualsWithDelta(20.0, $stats->timeline[1]['runningAverage'] ?? -1.0, 1.0E-9);
    }

    public function testRegionScoresNeedThreeTestedDrinksAndRankByAverage(): void
    {
        $mid = ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]];
        $high = ['manu' => [8, 8, 8], 'fabi' => [8, 8, 8], 'schorsch' => [8, 8, 8]];

        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Fr1', $high, null, 'Franken'),
            CatalogFixture::tested('Fr2', $high, null, 'Franken'),
            CatalogFixture::tested('Fr3', $high, null, 'Franken'),
            CatalogFixture::tested('Ba1', $mid, null, 'Baden'),
            CatalogFixture::tested('Ba2', $mid, null, 'Baden'),
            CatalogFixture::tested('Ba3', $mid, null, 'Baden'),
            CatalogFixture::tested('Sa1', $high, null, 'Saarland'),
        ]);

        $stats = Statistics::fromCollection($collection);

        self::assertSame(
            ['Franken', 'Baden'],
            array_map(static fn (array $r): string => $r['region'], $stats->regionScores),
        );
    }
}
