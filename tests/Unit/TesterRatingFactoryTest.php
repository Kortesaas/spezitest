<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterRatingFactory;

final class TesterRatingFactoryTest extends TestCase
{
    public function testBuildsRatingsForKnownTesterCodesAndFeedsTheEngine(): void
    {
        $ratings = TesterRatingFactory::fromMap([
            'manu' => ['optik' => '9', 'sueffigkeit' => '10', 'geschmack' => '10'],
            'fabi' => ['optik' => '9', 'sueffigkeit' => '10', 'geschmack' => '10'],
            'schorsch' => ['optik' => '8', 'sueffigkeit' => '8', 'geschmack' => '8'],
        ]);

        self::assertCount(3, $ratings);

        $result = (new RatingCalculator())->calculate($ratings);
        self::assertNotNull($result);
        self::assertSame(55.33, $result->gesamt());
    }

    public function testIgnoresUnknownTesterCodes(): void
    {
        $ratings = TesterRatingFactory::fromMap([
            'manu' => ['optik' => '5', 'sueffigkeit' => '5', 'geschmack' => '5'],
            'someone-else' => ['optik' => '1', 'sueffigkeit' => '1', 'geschmack' => '1'],
        ]);

        self::assertCount(1, $ratings);
        self::assertNull((new RatingCalculator())->calculate($ratings));
    }
}
