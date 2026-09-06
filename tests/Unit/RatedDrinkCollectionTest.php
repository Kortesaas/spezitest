<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Domain\Rating\PricePerformanceResult;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterCode;
use Spezitest\Domain\Rating\TesterRating;
use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;

final class RatedDrinkCollectionTest extends TestCase
{
    public function testPricePerformanceRankedOrdersBestValueFirstAndExcludesDrinksWithoutAPrice(): void
    {
        $best = $this->drink(1, 'Best Value', 0.9);
        $worst = $this->drink(2, 'Worst Value', 0.2);
        $middle = $this->drink(3, 'Middle Value', 0.5);
        $withoutPrice = $this->drink(4, 'No Price', null);

        $collection = new RatedDrinkCollection([$worst, $withoutPrice, $best, $middle]);

        self::assertSame(
            ['Best Value', 'Middle Value', 'Worst Value'],
            array_map(static fn (RatedDrink $drink): string => $drink->name, $collection->pricePerformanceRanked()),
        );
    }

    private function drink(int $id, string $name, ?float $normalized): RatedDrink
    {
        $result = (new RatingCalculator())->calculate([
            new TesterRating(TesterCode::Manu, 8, 8, 8),
            new TesterRating(TesterCode::Fabi, 8, 8, 8),
            new TesterRating(TesterCode::Schorsch, 8, 8, 8),
        ]);

        return new RatedDrink(
            $id,
            $name,
            null,
            null,
            null,
            null,
            'tested',
            false,
            '2026-01-01 00:00:00',
            $result,
            null,
            $normalized === null ? null : '0.89',
            $normalized === null ? null : 500,
            null,
            '2026-01-01 00:00:00',
            $normalized === null ? null : new PricePerformanceResult(1.0, $normalized),
            [],
        );
    }
}
