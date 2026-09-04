<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Spezitest\Domain\Rating\ExcelRounder;
use Spezitest\Domain\Rating\ExactNumber;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterCode;
use Spezitest\Domain\Rating\TesterRating;

final class RatingCalculatorTest extends TestCase
{
    public function testCategoryAveragesRemainUnroundedBeforeWeighting(): void
    {
        $result = (new RatingCalculator())->calculate([
            new TesterRating(TesterCode::Manu, 9, 10, 10),
            new TesterRating(TesterCode::Fabi, 9, 10, 10),
            new TesterRating(TesterCode::Schorsch, 8, 8, 8),
        ]);

        self::assertNotNull($result);
        self::assertEqualsWithDelta(8.666666666666666, $result->optikAverage(), 1.0E-14);
        self::assertEqualsWithDelta(9.333333333333334, $result->sueffigkeitAverage(), 1.0E-14);
        self::assertEqualsWithDelta(9.333333333333334, $result->geschmackAverage(), 1.0E-14);
        self::assertSame(55.33, $result->gesamt());
    }

    public function testWeightingIsOneTwoThree(): void
    {
        $result = (new RatingCalculator())->calculate([
            new TesterRating(TesterCode::Manu, 1, 2, 3),
            new TesterRating(TesterCode::Fabi, 1, 2, 3),
            new TesterRating(TesterCode::Schorsch, 1, 2, 3),
        ]);

        self::assertNotNull($result);
        self::assertSame(14.0, $result->gesamt());
    }

    public function testConceptualHistoricalMaximumIsSixty(): void
    {
        $result = (new RatingCalculator())->calculate([
            new TesterRating(TesterCode::Manu, 10, 10, 10),
            new TesterRating(TesterCode::Fabi, 10, 10, 10),
            new TesterRating(TesterCode::Schorsch, 10, 10, 10),
        ]);

        self::assertNotNull($result);
        self::assertSame(60.0, $result->gesamt());
    }

    public function testDecimalInputsArePreservedWithoutAnIntegerOnlyAssumption(): void
    {
        $result = (new RatingCalculator())->calculate([
            new TesterRating(TesterCode::Manu, '1.2500', '2.5000', '3.7500'),
            new TesterRating(TesterCode::Fabi, '1.2500', '2.5000', '3.7500'),
            new TesterRating(TesterCode::Schorsch, '1.2500', '2.5000', '3.7500'),
        ]);

        self::assertNotNull($result);
        self::assertSame(17.5, $result->gesamt());
    }

    public function testMissingCanonicalTesterPreventsOfficialResult(): void
    {
        $result = (new RatingCalculator())->calculate([
            new TesterRating(TesterCode::Manu, 5, 5, 5),
            new TesterRating(TesterCode::Fabi, 5, 5, 5),
        ]);

        self::assertNull($result);
    }

    public function testDuplicateTesterIsRejected(): void
    {
        $calculator = new RatingCalculator();

        $this->expectException(InvalidArgumentException::class);
        $calculator->calculate([
            new TesterRating(TesterCode::Manu, 5, 5, 5),
            new TesterRating(TesterCode::Manu, 5, 5, 5),
            new TesterRating(TesterCode::Fabi, 5, 5, 5),
            new TesterRating(TesterCode::Schorsch, 5, 5, 5),
        ]);
    }

    public function testExcelRoundingIsIsolatedAndUsesHalfAwayFromZero(): void
    {
        $rounder = new ExcelRounder();

        self::assertSame(1.01, $rounder->round(ExactNumber::from('1.005'), 2)->toFloat());
        self::assertSame(-1.01, $rounder->round(ExactNumber::from('-1.005'), 2)->toFloat());
        self::assertSame(2.67, $rounder->round(ExactNumber::fromFraction(8, 3), 2)->toFloat());
    }
}
