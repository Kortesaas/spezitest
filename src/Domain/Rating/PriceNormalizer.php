<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

use InvalidArgumentException;

/**
 * Converts a raw entered price + container volume into the application's
 * decided Preis/Leistung basis: price per 0.5 L. This is a presentation/input
 * decision, not a change to the verified rating formulas — the result still
 * flows through the unmodified {@see PricePerformanceCalculator}.
 */
final class PriceNormalizer
{
    private const REFERENCE_VOLUME_ML = 500;

    public function perReferenceVolume(string $priceAmount, int $volumeMl): float
    {
        if ($volumeMl <= 0) {
            throw new InvalidArgumentException('Volume must be positive.');
        }

        return ExactNumber::from($priceAmount)
            ->multiplyByInt(self::REFERENCE_VOLUME_ML)
            ->divideByInt($volumeMl)
            ->toFloat();
    }
}
