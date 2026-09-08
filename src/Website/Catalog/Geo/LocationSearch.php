<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog\Geo;

use Spezitest\Website\Catalog\HuntMap;

/**
 * Resolves a typed "PLZ oder Ort" to a coordinate so the hunt map can jump
 * there and sort the list by distance — the same result as "In meiner Nähe",
 * but centred on a search term instead of the visitor's device.
 *
 * Everything is offline and first-party: a five-digit postal code goes through
 * {@see PostalGeocoder}, a place name is matched against the drink origins
 * first and then the bundled GeoNames town index ({@see place-centroids.php}).
 * No address is sent anywhere.
 */
final readonly class LocationSearch
{
    /**
     * @param array<string, array{0: float, 1: float, 2: string}> $places
     */
    public function __construct(
        private PostalGeocoder $postalGeocoder,
        private array $places,
    ) {
    }

    public static function default(): self
    {
        $places = require __DIR__ . '/place-centroids.php';

        /** @var array<string, array{0: float, 1: float, 2: string}> $places */
        $places = is_array($places) ? $places : [];

        return new self(PostalGeocoder::default(), $places);
    }

    /**
     * @return array{latitude: float, longitude: float, label: string}|null
     */
    public function search(string $term, HuntMap $map): ?array
    {
        $term = trim($term);

        if ($term === '') {
            return null;
        }

        $postalCode = PostalGeocoder::postalCode($term);

        if ($postalCode !== null) {
            $point = $this->postalGeocoder->locate($term);

            if ($point === null) {
                return null;
            }

            $place = $this->postalGeocoder->place($postalCode);

            return [
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'label' => $place === null ? $postalCode : $postalCode . ' ' . $place,
            ];
        }

        $key = self::normalise($term);

        if (strlen($key) < 2) {
            return null;
        }

        // A place that actually has a still-wanted Spezi wins over a namesake.
        foreach ($map->points as $point) {
            if (self::normalise($point['place']) === $key) {
                return [
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'label' => $point['place'],
                ];
            }
        }

        $match = $this->places[$key] ?? $this->prefixMatch($key);

        if ($match === null) {
            return null;
        }

        return ['latitude' => $match[0], 'longitude' => $match[1], 'label' => $match[2]];
    }

    /**
     * Up to eight matches for what the visitor has typed, for the search-box
     * type-ahead. Digits suggest postal codes; text suggests place names —
     * places that actually have a still-wanted Spezi first, then the shortest
     * matching names from the town index (so "münch" leads with "München", not
     * "Münchenbernsdorf"). Every suggestion carries its postal code as a hint.
     *
     * @return list<array{label: string, sub: ?string}>
     */
    public function suggest(string $term, HuntMap $map): array
    {
        $term = trim($term);

        if (preg_match('/^\d{2,5}$/', $term) === 1) {
            $out = [];

            foreach ($this->postalGeocoder->startingWith($term) as $hit) {
                $out[] = [
                    'label' => $hit['place'] === null ? $hit['code'] : $hit['code'] . ' ' . $hit['place'],
                    'sub' => null,
                ];
            }

            return $out;
        }

        $key = self::normalise($term);

        if (strlen($key) < 2) {
            return [];
        }

        /** @var array<string, true> $seen */
        $seen = [];
        $suggestions = [];

        foreach ($map->points as $point) {
            if (str_starts_with(self::normalise($point['place']), $key) && !isset($seen[$point['place']])) {
                $seen[$point['place']] = true;
                $suggestions[] = ['label' => $point['place'], 'sub' => $this->hint($point['place'], 'Spezi hier')];
            }
        }

        /** @var list<array{0: string, 1: string}> $indexHits [display name, normalised key] */
        $indexHits = [];

        foreach ($this->places as $candidate => [, , $name]) {
            if (str_starts_with((string) $candidate, $key) && !isset($seen[$name])) {
                $seen[$name] = true;
                $indexHits[] = [$name, (string) $candidate];
            }
        }

        usort(
            $indexHits,
            static fn (array $a, array $b): int => [strlen($a[1]), $a[1]] <=> [strlen($b[1]), $b[1]],
        );

        foreach ($indexHits as [$name]) {
            if (count($suggestions) >= 8) {
                break;
            }

            $suggestions[] = ['label' => $name, 'sub' => $this->hint($name, null)];
        }

        return array_slice($suggestions, 0, 8);
    }

    private function hint(string $townName, ?string $prefix): ?string
    {
        $postalCode = $this->postalGeocoder->postalCodeFor($townName);
        $parts = array_filter([$prefix, $postalCode === null ? null : 'PLZ ' . $postalCode]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * A single unambiguous "starts with" hit (e.g. "garmisch" →
     * "Garmisch-Partenkirchen"), for terms of at least four characters.
     *
     * @return array{0: float, 1: float, 2: string}|null
     */
    private function prefixMatch(string $key): ?array
    {
        if (strlen($key) < 4) {
            return null;
        }

        $found = null;

        foreach ($this->places as $candidate => $value) {
            if (!str_starts_with($candidate, $key)) {
                continue;
            }

            if ($found !== null) {
                return null; // more than one — ambiguous, make the user be specific
            }

            $found = $value;
        }

        return $found;
    }

    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }
}
