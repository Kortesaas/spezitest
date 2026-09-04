<?php

declare(strict_types=1);

namespace Spezitest\Domain\Rating;

final readonly class TesterRating
{
    private ExactNumber $optik;

    private ExactNumber $sueffigkeit;

    private ExactNumber $geschmack;

    public function __construct(
        private TesterCode $tester,
        int|float|string $optik,
        int|float|string $sueffigkeit,
        int|float|string $geschmack,
    ) {
        $this->optik = ExactNumber::from($optik);
        $this->sueffigkeit = ExactNumber::from($sueffigkeit);
        $this->geschmack = ExactNumber::from($geschmack);
    }

    public function tester(): TesterCode
    {
        return $this->tester;
    }

    public function optik(): ExactNumber
    {
        return $this->optik;
    }

    public function sueffigkeit(): ExactNumber
    {
        return $this->sueffigkeit;
    }

    public function geschmack(): ExactNumber
    {
        return $this->geschmack;
    }
}
