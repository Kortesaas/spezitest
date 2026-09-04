<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

use InvalidArgumentException;

/**
 * Small exact rational value used for bounded rating arithmetic.
 *
 * Database DECIMAL strings should be passed without converting them to float.
 */
final class ExactNumber
{
    private readonly int $numerator;

    private readonly int $denominator;

    private function __construct(int $numerator, int $denominator)
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('An exact number cannot have a zero denominator.');
        }

        if ($denominator < 0) {
            $numerator *= -1;
            $denominator *= -1;
        }

        $divisor = self::greatestCommonDivisor(abs($numerator), $denominator);
        $this->numerator = intdiv($numerator, $divisor);
        $this->denominator = intdiv($denominator, $divisor);
    }

    public static function from(int|float|string $value): self
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Rating numbers must be finite.');
            }

            $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

            if (!is_string($encoded)) {
                throw new InvalidArgumentException('The floating-point number could not be represented.');
            }

            $value = $encoded;
        }

        $text = trim((string) $value);

        if (preg_match('/\A([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?\z/D', $text, $matches) !== 1) {
            throw new InvalidArgumentException('Rating numbers must use a decimal numeric representation.');
        }

        $fraction = $matches[3] ?? '';
        $exponent = isset($matches[4]) ? (int) $matches[4] : 0;
        $digits = ltrim($matches[2] . $fraction, '0');

        if ($digits === '') {
            return new self(0, 1);
        }

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException('Rating number precision exceeds the supported exact range.');
        }

        $numerator = (int) $digits;

        if ($matches[1] === '-') {
            $numerator *= -1;
        }

        $decimalPlaces = strlen($fraction) - $exponent;

        if ($decimalPlaces < 0) {
            return new self($numerator * self::powerOfTen(-$decimalPlaces), 1);
        }

        return new self($numerator, self::powerOfTen($decimalPlaces));
    }

    public static function fromFraction(int $numerator, int $denominator): self
    {
        return new self($numerator, $denominator);
    }

    public static function zero(): self
    {
        return new self(0, 1);
    }

    public function add(self $other): self
    {
        return new self(
            $this->numerator * $other->denominator + $other->numerator * $this->denominator,
            $this->denominator * $other->denominator,
        );
    }

    public function multiplyByInt(int $multiplier): self
    {
        return new self($this->numerator * $multiplier, $this->denominator);
    }

    public function divideByInt(int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        return new self($this->numerator, $this->denominator * $divisor);
    }

    public function divideBy(self $divisor): self
    {
        if ($divisor->numerator === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        return new self(
            $this->numerator * $divisor->denominator,
            $this->denominator * $divisor->numerator,
        );
    }

    public function compare(self $other): int
    {
        return ($this->numerator * $other->denominator)
            <=> ($other->numerator * $this->denominator);
    }

    public function isPositive(): bool
    {
        return $this->numerator > 0;
    }

    public function numerator(): int
    {
        return $this->numerator;
    }

    public function denominator(): int
    {
        return $this->denominator;
    }

    public function toFloat(): float
    {
        return $this->numerator / $this->denominator;
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return max(1, $left);
    }

    private static function powerOfTen(int $power): int
    {
        if ($power < 0 || $power > 18) {
            throw new InvalidArgumentException('Decimal scale exceeds the supported exact range.');
        }

        $result = 1;

        for ($index = 0; $index < $power; ++$index) {
            $result *= 10;
        }

        return $result;
    }
}
