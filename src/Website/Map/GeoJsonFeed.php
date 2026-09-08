<?php

declare(strict_types=1);

namespace Spezitest\Website\Map;

use Spezitest\Website\Catalog\HuntMap;

/**
 * Builds the public GeoJSON feed consumed by the uMap map viewer.
 *
 * The set of pins is exactly the hunt map's: a drink is included only when its
 * lifecycle status is `identified` and its `origin_location` resolves to a
 * German postal-code coordinate (see {@see HuntMap}). Deleting, renaming,
 * moving or changing the status of a drink in the admin therefore changes this
 * feed on the next request — there is no separate map data to keep in sync.
 *
 * Each drink becomes one Point feature carrying its stable database id and its
 * name and nothing else. Coordinates that are somehow non-finite or outside
 * the valid range are dropped so the output is always well-formed GeoJSON.
 */
final readonly class GeoJsonFeed
{
    /**
     * @param list<array{id: int, name: string, latitude: float, longitude: float}> $features
     */
    private function __construct(private array $features)
    {
    }

    public static function fromHuntMap(HuntMap $map): self
    {
        $features = [];

        foreach ($map->points as $point) {
            if (!self::isValidCoordinate($point['latitude'], $point['longitude'])) {
                continue;
            }

            foreach ($point['drinks'] as $drink) {
                $features[] = [
                    'id' => $drink['id'],
                    'name' => $drink['name'],
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                ];
            }
        }

        return new self($features);
    }

    /**
     * A GeoJSON FeatureCollection, ready for {@see json_encode()}. GeoJSON
     * coordinates are [longitude, latitude].
     *
     * @return array{
     *     type: 'FeatureCollection',
     *     features: list<array{
     *         type: 'Feature',
     *         id: int,
     *         properties: array{name: string},
     *         geometry: array{type: 'Point', coordinates: array{0: float, 1: float}}
     *     }>
     * }
     */
    public function toFeatureCollection(): array
    {
        $features = [];

        foreach ($this->features as $feature) {
            $features[] = [
                'type' => 'Feature',
                'id' => $feature['id'],
                'properties' => ['name' => $feature['name']],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        round($feature['longitude'], 5),
                        round($feature['latitude'], 5),
                    ],
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    public function count(): int
    {
        return count($this->features);
    }

    private static function isValidCoordinate(float $latitude, float $longitude): bool
    {
        return is_finite($latitude)
            && is_finite($longitude)
            && $latitude >= -90.0 && $latitude <= 90.0
            && $longitude >= -180.0 && $longitude <= 180.0;
    }
}
