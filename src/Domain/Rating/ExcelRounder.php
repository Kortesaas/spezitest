<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

use InvalidArgumentException;

final class ExcelRounder
{
    public function round(ExactNumber $value, int $decimalPlaces): ExactNumber
    {
        if ($decimalPlaces < 0 || $decimalPlaces > 8) {
            throw new InvalidArgumentException('The supported rounding scale is between 0 and 8.');
        }

        $factor = 1;

        for ($index = 0; $index < $decimalPlaces; ++$index) {
            $factor *= 10;
        }

        $sign = $value->numerator() < 0 ? -1 : 1;
        $scaledNumerator = abs($value->numerator()) * $factor;
        $quotient = intdiv($scaledNumerator, $value->denominator());
        $remainder = $scaledNumerator % $value->denominator();

        if ($remainder * 2 >= $value->denominator()) {
            ++$quotient;
        }

        return ExactNumber::fromFraction($sign * $quotient, $factor);
    }
}
