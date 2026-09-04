<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

final readonly class PricePerformanceResult
{
    public function __construct(
        private float $intermediate,
        private float $normalized,
    ) {
    }

    public function intermediate(): float
    {
        return $this->intermediate;
    }

    public function normalized(): float
    {
        return $this->normalized;
    }
}
