<?php

declare(strict_types=1);

namespace Spezitest\Website\Map;

use Spezitest\Website\Catalog\HuntMap;

/**
 * Builds the public GeoJSON feed consumed by the uMap map viewer.
 *
 * The pins are the hunt map's: a drink is included when its `origin_location`
 * resolves to a postal-code coordinate — German, Austrian, Swiss or
 * Liechtenstein (see {@see HuntMap}) — whatever its lifecycle status, unless
 * the caller narrows it to some statuses. Every feature carries its `status`
 * (`identified` / `acquired` / `tested`) and uMap's own `_umap_options.color`
 * for it — grey still sought, navy blue bought, red tested, the colours of the
 * site's status badges — so uMap draws the same picture as `/karte`. Deleting,
 * renaming, moving or changing the status of a drink in the admin therefore
 * changes this feed on the next request — there is no separate map data to keep
 * in sync.
 *
 * Each drink becomes one Point feature carrying its stable database id, its
 * name, and a `description` written in uMap's own text syntax: the package
 * photo (when one exists), the manufacturer, the place, and a link back to the
 * drink page on this site. The default uMap popup renders `description`, so it
 * shows everything with no popup-template setup. The bare `image` URL and the
 * `manufacturer` / `place` strings are included as their own properties too,
 * for any other consumer. Coordinates that are somehow non-finite or outside
 * the valid range are dropped so the output is always well-formed GeoJSON.
 */
final readonly class GeoJsonFeed
{
    /** Popup thumbnail width in pixels — small; the popup is narrow. */
    private const IMAGE_WIDTH = 110;

    /** Lifecycle status → [pin colour, label], matching the `/karte` map. */
    public const STATUSES = [
        'identified' => ['#646567', 'Noch gesucht'],
        'acquired' => ['#002D55', 'Erworben'],
        'tested' => ['#E60005', 'Getestet'],
    ];

    /**
     * @param list<array{
     *     id: int, name: string, latitude: float, longitude: float,
     *     image: ?string, manufacturer: ?string, place: string,
     *     approximate: bool, link: string, status: string
     * }> $features
     */
    private function __construct(private array $features)
    {
    }

    /**
     * @param list<string> $statuses only these lifecycle statuses; empty = all
     */
    public static function fromHuntMap(
        HuntMap $map,
        string $siteUrl = 'https://www.spezitest.de',
        array $statuses = [],
    ): self {
        $base = rtrim($siteUrl, '/');
        $features = [];

        foreach ($map->points as $point) {
            if (!self::isValidCoordinate($point['latitude'], $point['longitude'])) {
                continue;
            }

            $place = trim($point['postalCode'] . ' ' . $point['place']);

            if ($point['country'] !== null) {
                $place .= ', ' . $point['country'];
            }

            foreach ($point['drinks'] as $drink) {
                if ($statuses !== [] && !in_array($drink['status'], $statuses, true)) {
                    continue;
                }

                $manufacturer = $drink['manufacturer'] ?? '';

                $features[] = [
                    'id' => $drink['id'],
                    'name' => $drink['name'],
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'image' => $drink['hasImage'] ? $base . '/spezi/' . $drink['id'] . '/bild' : null,
                    'manufacturer' => $manufacturer === '' ? null : $manufacturer,
                    'place' => $place,
                    'approximate' => $point['approximate'],
                    'link' => $base . '/spezi/' . $drink['id'],
                    'status' => $drink['status'],
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
     *         properties: array{
     *             name: string, description: string, manufacturer?: string,
     *             place: string, image?: string, status: string,
     *             _umap_options: array{color: string}
     *         },
     *         geometry: array{type: 'Point', coordinates: array{0: float, 1: float}}
     *     }>
     * }
     */
    public function toFeatureCollection(): array
    {
        $features = [];

        foreach ($this->features as $feature) {
            $properties = [
                'name' => $feature['name'],
                'description' => self::describe($feature),
                'place' => $feature['place'],
                'status' => $feature['status'],
                '_umap_options' => ['color' => self::colour($feature['status'])],
            ];

            if ($feature['manufacturer'] !== null) {
                $properties['manufacturer'] = $feature['manufacturer'];
            }

            if ($feature['image'] !== null) {
                $properties['image'] = $feature['image'];
            }

            $features[] = [
                'type' => 'Feature',
                'id' => $feature['id'],
                'properties' => $properties,
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

    /**
     * The popup body in uMap text syntax: photo, then a labelled detail line
     * per fact we hold, then a link back to the drink page. `name` is the popup
     * heading (uMap's default template), so it is not repeated here.
     *
     * @param array{
     *     image: ?string, manufacturer: ?string, place: string,
     *     approximate: bool, link: string, status: string
     * } $feature
     */
    private static function describe(array $feature): string
    {
        $blocks = [];

        if ($feature['image'] !== null) {
            $blocks[] = '{{' . $feature['image'] . '|' . self::IMAGE_WIDTH . '}}';
        }

        $rows = [];

        if ($feature['manufacturer'] !== null) {
            $rows[] = 'Hersteller: **' . $feature['manufacturer'] . '**';
        }

        $place = $feature['place'];

        if ($feature['approximate']) {
            $place .= ' *(ungefähre Lage)*';
        }

        $rows[] = 'Ort: **' . $place . '**';
        $rows[] = 'Status: **' . (self::STATUSES[$feature['status']][1] ?? $feature['status']) . '**';
        $blocks[] = implode("\n", $rows);

        $blocks[] = '[[' . $feature['link'] . '|Auf spezitest.de ansehen]]';

        return implode("\n\n", $blocks);
    }

    private static function colour(string $status): string
    {
        return (self::STATUSES[$status] ?? self::STATUSES['identified'])[0];
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
