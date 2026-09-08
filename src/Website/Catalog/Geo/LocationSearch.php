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

            return $point === null
                ? null
                : ['latitude' => $point->latitude, 'longitude' => $point->longitude, 'label' => $postalCode];
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
