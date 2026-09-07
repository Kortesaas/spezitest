<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * Where a tested Spezi can be watched: the Spezistream it was tasted in and,
 * when the recording has been indexed, the exact point its segment starts.
 *
 * A segment always knows its run number, because that is what the test itself
 * records. The video address and the offset are both optional — an evening can
 * be tested long before its stream is published or cut.
 */
final readonly class StreamSegment
{
    public function __construct(
        public int $runNumber,
        public ?string $runTitle,
        public ?string $url,
        public ?int $offsetSeconds,
        /** How long the segment ran, derived from the gap to the next chapter. */
        public ?int $durationSeconds = null,
    ) {
    }

    /**
     * The address to open, deep-linked to the segment when the offset is known.
     * Null when no video address is on file, so callers render nothing rather
     * than a dead link.
     */
    public function watchUrl(): ?string
    {
        if ($this->url === null || $this->url === '') {
            return null;
        }

        if ($this->offsetSeconds === null || $this->offsetSeconds <= 0) {
            return $this->url;
        }

        return $this->url . (str_contains($this->url, '?') ? '&' : '?') . 't=' . $this->offsetSeconds . 's';
    }

    public function title(): string
    {
        return $this->runTitle ?? 'Spezistream #' . $this->runNumber;
    }

    /** `1:23:45` / `4:05`, or null when the segment has no timestamp. */
    public function formattedOffset(): ?string
    {
        if ($this->offsetSeconds === null) {
            return null;
        }

        $hours = intdiv($this->offsetSeconds, 3600);
        $minutes = intdiv($this->offsetSeconds % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $this->offsetSeconds % 60)
            : sprintf('%d:%02d', $minutes, $this->offsetSeconds % 60);
    }
}
