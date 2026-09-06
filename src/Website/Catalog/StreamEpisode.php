<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * One Testabend as the public site shows it: the episode plus everything worth
 * saying about the evening, derived from the Spezis tasted in it.
 *
 * Every figure here is computed from the tests themselves — nothing is stored
 * and nothing is invented. A figure that cannot be derived (no segment lengths
 * recorded yet, say) stays null and the page simply omits it.
 */
final readonly class StreamEpisode
{
    /**
     * @param list<RatedDrink> $drinks in tasting order
     */
    private function __construct(
        public int $number,
        public string $title,
        public ?string $url,
        public ?string $recordedOn,
        public array $drinks,
        public ?RatedDrink $best,
        public ?RatedDrink $worst,
        public ?float $averageGesamt,
        public ?int $tastingSeconds,
        public ?int $averageSeconds,
        public ?RatedDrink $longest,
        public ?RatedDrink $shortest,
        public ?int $firstOffset,
        public ?int $lastOffset,
    ) {
    }

    /**
     * Groups a catalog into its Testabende, newest episode first, each evening
     * in tasting order.
     *
     * @return list<self>
     */
    public static function fromCollection(RatedDrinkCollection $collection): array
    {
        /** @var array<int, array{segment: StreamSegment, drinks: list<RatedDrink>}> $grouped */
        $grouped = [];

        foreach ($collection->tested() as $drink) {
            $segment = $drink->stream;

            if ($segment === null) {
                continue;
            }

            $grouped[$segment->runNumber] ??= ['segment' => $segment, 'drinks' => []];
            $grouped[$segment->runNumber]['drinks'][] = $drink;

            // Every segment of an episode repeats its title and address; keep
            // the first one that actually carries an address.
            if ($grouped[$segment->runNumber]['segment']->url === null && $segment->url !== null) {
                $grouped[$segment->runNumber]['segment'] = $segment;
            }
        }

        krsort($grouped);
        $episodes = [];

        foreach ($grouped as $number => $entry) {
            $episodes[] = self::build($number, $entry['segment'], $entry['drinks']);
        }

        return $episodes;
    }

    /** @param list<RatedDrink> $drinks */
    private static function build(int $number, StreamSegment $segment, array $drinks): self
    {
        usort(
            $drinks,
            static fn (RatedDrink $a, RatedDrink $b): int
                => ($a->stream->offsetSeconds ?? PHP_INT_MAX) <=> ($b->stream->offsetSeconds ?? PHP_INT_MAX),
        );

        $best = null;
        $worst = null;
        $longest = null;
        $shortest = null;
        $gesamtSum = 0.0;
        $rated = 0;
        $seconds = 0;
        $timed = 0;

        foreach ($drinks as $drink) {
            $result = $drink->result;

            if ($result !== null) {
                ++$rated;
                $gesamtSum += $result->gesamt();

                if ($best === null || $result->gesamt() > ($best->result?->gesamt() ?? -1.0)) {
                    $best = $drink;
                }

                if ($worst === null || $result->gesamt() < ($worst->result?->gesamt() ?? PHP_FLOAT_MAX)) {
                    $worst = $drink;
                }
            }

            $duration = $drink->stream->durationSeconds ?? null;

            if ($duration === null) {
                continue;
            }

            ++$timed;
            $seconds += $duration;

            if ($longest === null || $duration > ($longest->stream->durationSeconds ?? 0)) {
                $longest = $drink;
            }

            if ($shortest === null || $duration < ($shortest->stream->durationSeconds ?? PHP_INT_MAX)) {
                $shortest = $drink;
            }
        }

        $first = $drinks[0]->stream ?? null;
        $last = $drinks[count($drinks) - 1]->stream ?? null;

        return new self(
            $number,
            $segment->title(),
            $segment->url,
            null,
            $drinks,
            $best,
            $worst,
            $rated === 0 ? null : $gesamtSum / $rated,
            $timed === 0 ? null : $seconds,
            $timed === 0 ? null : (int) round($seconds / $timed),
            $longest,
            $shortest,
            $first?->offsetSeconds,
            $last?->offsetSeconds,
        );
    }

    /** The same episode with its recording date filled in. */
    public function withRecordedOn(?string $recordedOn): self
    {
        return new self(
            $this->number,
            $this->title,
            $this->url,
            $recordedOn,
            $this->drinks,
            $this->best,
            $this->worst,
            $this->averageGesamt,
            $this->tastingSeconds,
            $this->averageSeconds,
            $this->longest,
            $this->shortest,
            $this->firstOffset,
            $this->lastOffset,
        );
    }

    public function count(): int
    {
        return count($this->drinks);
    }

    /** `1:23:45` / `4:05` — the shortest unambiguous reading of an offset. */
    public static function clock(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60)
            : sprintf('%d:%02d', $minutes, $seconds % 60);
    }

    /** `6:32 min` — a segment length as it is read, not as it is stored. */
    public static function minutes(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60);
    }
}
