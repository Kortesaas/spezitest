<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

use InvalidArgumentException;

final readonly class RatingCalculator
{
    public function __construct(private ExcelRounder $rounder = new ExcelRounder())
    {
    }

    /**
     * @param iterable<TesterRating> $ratings
     */
    public function calculate(iterable $ratings): ?RatingResult
    {
        /** @var array<string, TesterRating> $byTester */
        $byTester = [];

        foreach ($ratings as $rating) {
            $code = $rating->tester()->value;

            if (isset($byTester[$code])) {
                throw new InvalidArgumentException('A tester may only have one rating in a calculation.');
            }

            $byTester[$code] = $rating;
        }

        foreach (TesterCode::cases() as $tester) {
            if (!isset($byTester[$tester->value])) {
                return null;
            }
        }

        $optik = ExactNumber::zero();
        $sueffigkeit = ExactNumber::zero();
        $geschmack = ExactNumber::zero();

        foreach (TesterCode::cases() as $tester) {
            $rating = $byTester[$tester->value];
            $optik = $optik->add($rating->optik());
            $sueffigkeit = $sueffigkeit->add($rating->sueffigkeit());
            $geschmack = $geschmack->add($rating->geschmack());
        }

        $optikAverage = $optik->divideByInt(3);
        $sueffigkeitAverage = $sueffigkeit->divideByInt(3);
        $geschmackAverage = $geschmack->divideByInt(3);
        $weightedTotal = $optikAverage
            ->add($sueffigkeitAverage->multiplyByInt(2))
            ->add($geschmackAverage->multiplyByInt(3));

        return new RatingResult(
            $optikAverage,
            $sueffigkeitAverage,
            $geschmackAverage,
            $this->rounder->round($weightedTotal, 2),
        );
    }
}
