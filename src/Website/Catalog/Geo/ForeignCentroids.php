<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog\Geo;

/**
 * Lazily loads and caches the `foreign` block of the bundled centroid table
 * ({@see Geo/postal-centroids.php}) so the pure static {@see PostalGeocoder}
 * parser can consult it without an instance. Kept apart from the `readonly`
 * geocoder, which cannot hold a mutable static cache.
 *
 * @internal
 */
final class ForeignCentroids
{
    /** @var array<string, array<int|string, array{0: float, 1: float, 2?: string}>>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array<int|string, array{0: float, 1: float, 2?: string}>>
     */
    public static function all(): array
    {
        if (self::$table === null) {
            $data = require __DIR__ . '/postal-centroids.php';

            /** @var array<string, array<int|string, array{0: float, 1: float, 2?: string}>> $foreign */
            $foreign = is_array($data) && isset($data['foreign']) && is_array($data['foreign'])
                ? $data['foreign']
                : [];

            self::$table = $foreign;
        }

        return self::$table;
    }
}
