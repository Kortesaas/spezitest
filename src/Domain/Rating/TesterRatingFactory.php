<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

/**
 * Builds {@see TesterRating} value objects from persisted or submitted grades.
 *
 * Values are passed straight through to {@see TesterRating} (which parses them
 * exactly, without a binary float conversion for decimal strings). Unknown
 * tester codes are ignored so a malformed row can never silently shift a
 * calculation onto the wrong tester.
 */
final class TesterRatingFactory
{
    /**
     * @param array<string, array{optik: int|string, sueffigkeit: int|string, geschmack: int|string}> $byTesterCode
     * @return list<TesterRating>
     */
    public static function fromMap(array $byTesterCode): array
    {
        $ratings = [];

        foreach ($byTesterCode as $code => $values) {
            $tester = TesterCode::tryFrom($code);

            if ($tester === null) {
                continue;
            }

            $ratings[] = new TesterRating(
                $tester,
                $values['optik'],
                $values['sueffigkeit'],
                $values['geschmack'],
            );
        }

        return $ratings;
    }
}
