<?php

declare(strict_types=1);

namespace Spezitest\Admin\Testing;

use Spezitest\Domain\Rating\TesterCode;

/**
 * A validated set of test-entry values from the admin form.
 *
 * Each tester's three category grades are present together or not at all, so
 * the values always map cleanly onto the `ratings` table (which requires all
 * three categories per row). {@see self::isComplete()} reports whether all
 * three canonical testers have a full set, which is the precondition for
 * completing a test.
 */
final readonly class TestEntryInput
{
    /**
     * @param array<string, array{optik: int, sueffigkeit: int, geschmack: int}> $ratings
     *        Keyed by tester code; only fully supplied testers appear.
     * @param string|null $priceAmount Normalised decimal string (e.g. "0.8900") or null.
     */
    public function __construct(
        public array $ratings,
        public ?string $priceAmount,
        public ?string $notes,
    ) {
    }

    public function isComplete(): bool
    {
        foreach (TesterCode::cases() as $tester) {
            if (!isset($this->ratings[$tester->value])) {
                return false;
            }
        }

        return true;
    }

    public function hasAnyRating(): bool
    {
        return $this->ratings !== [];
    }
}
