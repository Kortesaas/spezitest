<?php

declare(strict_types=1);

namespace Spezitest\Tests\Support;

use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterCode;
use Spezitest\Domain\Rating\TesterRating;
use Spezitest\Website\Catalog\RatedDrink;

/**
 * Builders for {@see RatedDrink} value objects in unit tests. Rating results
 * are produced by the real {@see RatingCalculator} so the figures stay
 * consistent with the verified engine.
 */
final class CatalogFixture
{
    private static int $nextId = 1;

    /**
     * @param array<string, array{int, int, int}> $grades Keyed by tester code: [optik, sueffigkeit, geschmack].
     */
    public static function tested(
        string $name,
        array $grades,
        ?string $manufacturer = null,
        ?string $region = null,
        ?string $price = null,
        string $updatedAt = '2026-01-01 00:00:00',
    ): RatedDrink {
        $ratings = [];
        $testerGrades = [];

        foreach ($grades as $code => [$optik, $sueffigkeit, $geschmack]) {
            $tester = TesterCode::from($code);
            $ratings[] = new TesterRating($tester, $optik, $sueffigkeit, $geschmack);
            $testerGrades[$code] = [
                'optik' => (string) $optik,
                'sueffigkeit' => (string) $sueffigkeit,
                'geschmack' => (string) $geschmack,
            ];
        }

        $result = (new RatingCalculator())->calculate($ratings);

        return new RatedDrink(
            self::$nextId++,
            $name,
            $manufacturer,
            null,
            $region,
            null,
            'tested',
            false,
            $updatedAt,
            $result,
            null,
            $price,
            null,
            '2026-01-01 00:00:00',
            null,
            $testerGrades,
        );
    }

    public static function untested(
        string $name,
        string $status = 'acquired',
        ?string $manufacturer = null,
        bool $hasImage = false,
        string $updatedAt = '2026-01-01 00:00:00',
    ): RatedDrink {
        return new RatedDrink(
            self::$nextId++,
            $name,
            $manufacturer,
            null,
            null,
            null,
            $status,
            $hasImage,
            $updatedAt,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
        );
    }
}
