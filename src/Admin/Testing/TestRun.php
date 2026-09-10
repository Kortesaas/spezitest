<?php

declare(strict_types=1);

namespace Spezitest\Admin\Testing;

/**
 * One Spezistream: a livestream episode in which a batch of Spezis was tested.
 *
 * A run always has a number — the same value {@see \Spezitest\Admin\Persistence\TestRepository}
 * stores per test in `drink_tests.stream_reference`. Everything else is
 * optional, because the imported historical runs arrived as bare numbers and
 * their details (title, date, video URL) are filled in afterwards.
 */
final readonly class TestRun
{
    public function __construct(
        public int $number,
        public ?string $title,
        public ?string $recordedOn,
        public ?string $streamUrl,
        public string $status,
        public ?string $notes,
        public ?string $wheelImagePath,
        public ?string $wheelImageMime,
        public ?string $completedAt,
        /** How many tests carry this run's number. */
        public int $testCount = 0,
        /** How many of those also carry a segment timestamp. */
        public int $timedCount = 0,
        /** False when only `drink_tests` knows this number and no row exists yet. */
        public bool $hasDetails = true,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function displayTitle(): string
    {
        return $this->title ?? 'Spezistream #' . $this->number;
    }

    /**
     * The episode's video, deep-linked to a segment when the offset is known.
     * Returns null when no URL is recorded, so callers render nothing rather
     * than a dead link.
     */
    public function watchUrl(?int $offsetSeconds = null): ?string
    {
        if ($this->streamUrl === null || $this->streamUrl === '') {
            return null;
        }

        if ($offsetSeconds === null || $offsetSeconds <= 0) {
            return $this->streamUrl;
        }

        $separator = str_contains($this->streamUrl, '?') ? '&' : '?';

        return $this->streamUrl . $separator . 't=' . $offsetSeconds . 's';
    }
}
