<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

final readonly class RatingResult
{
    public function __construct(
        private ExactNumber $optikAverage,
        private ExactNumber $sueffigkeitAverage,
        private ExactNumber $geschmackAverage,
        private ExactNumber $gesamt,
    ) {
    }

    public function optikAverage(): float
    {
        return $this->optikAverage->toFloat();
    }

    public function sueffigkeitAverage(): float
    {
        return $this->sueffigkeitAverage->toFloat();
    }

    public function geschmackAverage(): float
    {
        return $this->geschmackAverage->toFloat();
    }

    public function gesamt(): float
    {
        return $this->gesamt->toFloat();
    }

    public function exactGesamt(): ExactNumber
    {
        return $this->gesamt;
    }
}
