<?php

declare(strict_types=1);

namespace Spezitest\Admin\Testing;

use Spezitest\Domain\Rating\RatingResult;

/**
 * Everything the admin test-entry form needs to render: the current grades
 * (persisted or resubmitted), the optional price and note, the test status and
 * — when a full set is present — the official result from the verified engine.
 */
final readonly class TestFormData
{
    /**
     * @param array<string, array{optik: string, sueffigkeit: string, geschmack: string}> $grades
     *        Keyed by tester code; a tester absent from the map has no grades yet.
     */
    public function __construct(
        public array $grades,
        public string $price,
        public string $notes,
        public string $status,
        public ?RatingResult $result,
    ) {
    }

    public static function empty(): self
    {
        return new self([], '', '', 'none', null);
    }

    public function grade(string $testerCode, string $category): string
    {
        $value = $this->grades[$testerCode][$category] ?? '';

        return $value === '' ? '' : (string) (int) round((float) $value);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
