<?php

declare(strict_types=1);

namespace Spezitest\Admin\Testing;

/**
 * Where one test sits in its Spezistream's recording: the run it belongs to, the
 * offset at which its segment starts, and how long that segment ran.
 *
 * All three parts are independently optional. A test can be filed under an
 * evening long before the video exists, and a segment timestamp is only known
 * once the recording has been cut.
 */
final readonly class TestStreamPosition
{
    public function __construct(
        public ?int $runNumber,
        /** A `HH:MM:SS` string, matching the `TIME` column it is stored in. */
        public ?string $offset,
        /** Segment length in whole seconds. */
        public ?int $duration,
    ) {
    }

    /** The offset as whole seconds, for building a `?t=…s` deep link. */
    public static function offsetToSeconds(?string $offset): ?int
    {
        if ($offset === null || $offset === '') {
            return null;
        }

        $parts = array_map('intval', explode(':', $offset));

        if (count($parts) !== 3) {
            return null;
        }

        return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }

    /** `1:23:45` / `4:05` — the shortest unambiguous reading of an offset. */
    public static function formatOffset(?string $offset): ?string
    {
        $seconds = self::offsetToSeconds($offset);

        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60)
            : sprintf('%d:%02d', $minutes, $seconds % 60);
    }

    /** `6:32 min` — a segment length in the form the admin reads it. */
    public static function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60);
    }
}
