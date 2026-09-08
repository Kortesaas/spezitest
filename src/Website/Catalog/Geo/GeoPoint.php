<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog\Geo;

/**
 * A geographic position for a drink's origin.
 *
 * `approximate` is true when the exact five-digit postal code was not in the
 * bundled table and the centre of its three-digit Leitregion was used instead;
 * the map labels those points so a coarse placement is never shown as precise.
 */
final readonly class GeoPoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public bool $approximate,
    ) {
    }
}
