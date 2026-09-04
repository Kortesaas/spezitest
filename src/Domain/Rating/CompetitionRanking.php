<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

final class CompetitionRanking
{
    /**
     * Rank exactly the supplied completed-result comparison set.
     *
     * @param array<array-key, int|float|string> $scores
     * @return array<array-key, int>
     */
    public function rank(array $scores): array
    {
        /** @var array<array-key, ExactNumber> $exactScores */
        $exactScores = [];

        foreach ($scores as $key => $score) {
            $exactScores[$key] = ExactNumber::from($score);
        }

        $ranks = [];

        foreach ($exactScores as $key => $score) {
            $greater = 0;

            foreach ($exactScores as $comparison) {
                if ($comparison->compare($score) > 0) {
                    ++$greater;
                }
            }

            $ranks[$key] = $greater + 1;
        }

        return $ranks;
    }
}
