<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

final class PricePerformanceCalculator
{
    /**
     * @param iterable<int|float|string> $comparisonIntermediates Explicit comparison population.
     */
    public function calculate(
        int|float|string $roundedGesamt,
        int|float|string|null $price,
        iterable $comparisonIntermediates,
    ): ?PricePerformanceResult {
        if ($price === null) {
            return null;
        }

        $exactPrice = ExactNumber::from($price);

        if (!$exactPrice->isPositive()) {
            return null;
        }

        $intermediate = ExactNumber::from($roundedGesamt)->divideBy($exactPrice)->toFloat();
        $minimum = null;
        $maximum = null;

        foreach ($comparisonIntermediates as $comparison) {
            $value = ExactNumber::from($comparison)->toFloat();
            $minimum = $minimum === null ? $value : min($minimum, $value);
            $maximum = $maximum === null ? $value : max($maximum, $value);
        }

        if ($minimum === null) {
            return null;
        }

        if ($minimum === $maximum) {
            return null;
        }

        return new PricePerformanceResult(
            $intermediate,
            ($intermediate - $minimum) / ($maximum - $minimum),
        );
    }
}
