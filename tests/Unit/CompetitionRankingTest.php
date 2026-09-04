<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Domain\Rating\CompetitionRanking;

final class CompetitionRankingTest extends TestCase
{
    public function testRankingIsDescendingWithCompetitionTies(): void
    {
        $ranks = (new CompetitionRanking())->rank([
            'first' => '55.33',
            'tie-a' => '43.67',
            'tie-b' => '43.67',
            'last' => '31.00',
        ]);

        self::assertSame([
            'first' => 1,
            'tie-a' => 2,
            'tie-b' => 2,
            'last' => 4,
        ], $ranks);
    }

    public function testEmptyComparisonSetProducesNoRanks(): void
    {
        self::assertSame([], (new CompetitionRanking())->rank([]));
    }
}
