<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Domain\Rating\PricePerformanceCalculator;

final class PricePerformanceCalculatorTest extends TestCase
{
    public function testComparisonPopulationIsExplicit(): void
    {
        $result = (new PricePerformanceCalculator())->calculate(
            '55.33',
            '0.6995',
            ['0.7488789237668161', '96.83333333333334'],
        );

        self::assertNotNull($result);
        self::assertEqualsWithDelta(79.09935668334525, $result->intermediate(), 1.0E-12);
        self::assertEqualsWithDelta(0.8154334459308494, $result->normalized(), 1.0E-12);
    }

    public function testMissingAndNonPositivePricesAreUnavailable(): void
    {
        $calculator = new PricePerformanceCalculator();
        $comparison = [1, 2];

        self::assertNull($calculator->calculate(30, null, $comparison));
        self::assertNull($calculator->calculate(30, 0, $comparison));
        self::assertNull($calculator->calculate(30, -1, $comparison));
    }

    public function testEmptyOrZeroWidthComparisonSetIsUnavailable(): void
    {
        $calculator = new PricePerformanceCalculator();

        self::assertNull($calculator->calculate(30, 1, []));
        self::assertNull($calculator->calculate(30, 1, [30, 30]));
    }
}
