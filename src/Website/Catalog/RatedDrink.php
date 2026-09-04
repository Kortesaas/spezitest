<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

use Spezitest\Domain\Rating\PricePerformanceResult;
use Spezitest\Domain\Rating\RatingResult;

/**
 * A drink together with everything the public site needs to show about it.
 *
 * Rating figures are the output of the verified engine, computed on read. A
 * drink only carries a {@see RatingResult} when it has a completed test with a
 * full set of grades from all three canonical testers.
 */
final readonly class RatedDrink
{
    /**
     * @param array<string, array{optik: string, sueffigkeit: string, geschmack: string}> $testerGrades
     *        Raw grades keyed by tester code, present only for a completed test.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $manufacturer,
        public ?string $originLocation,
        public ?string $originRegion,
        public ?string $notes,
        public string $lifecycleStatus,
        public bool $hasImage,
        public string $updatedAt,
        public ?RatingResult $result,
        public ?int $rank,
        public ?string $priceAmount,
        public ?string $testNotes,
        public ?string $testedAt,
        public ?PricePerformanceResult $pricePerformance,
        public array $testerGrades = [],
    ) {
    }

    public function isTested(): bool
    {
        return $this->lifecycleStatus === 'tested' && $this->result !== null;
    }

    public function slug(): string
    {
        return Slug::forDrink($this->id, $this->name);
    }

    public function displayOrigin(): ?string
    {
        return $this->originRegion ?? $this->originLocation;
    }
}
