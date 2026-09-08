<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog\Geo;

use RuntimeException;

/**
 * Resolves a drink's `origin_location` to an approximate coordinate.
 *
 * Placement is offline and deterministic: a five-digit German postal code at
 * the start of the location string is looked up in the bundled GeoNames
 * centroid table ({@see Geo/postal-centroids.php}). No address is geocoded and
 * no network call is made. Locations without a leading German postal code —
 * foreign entries, blanks — resolve to null and the map lists them separately.
 */
final readonly class PostalGeocoder
{
    /**
     * @param array<int|string, array{0: float, 1: float}> $exact keyed by five-digit postal code
     * @param array<int|string, array{0: float, 1: float}> $prefix keyed by three-digit Leitregion prefix
     */
    public function __construct(
        private array $exact,
        private array $prefix,
    ) {
    }

    public static function default(): self
    {
        $table = require __DIR__ . '/postal-centroids.php';

        if (
            !is_array($table)
            || !isset($table['exact'], $table['prefix'])
            || !is_array($table['exact'])
            || !is_array($table['prefix'])
        ) {
            throw new RuntimeException('The bundled postal-centroid table is malformed.');
        }

        /** @var array<int|string, array{0: float, 1: float}> $exact */
        $exact = $table['exact'];
        /** @var array<int|string, array{0: float, 1: float}> $prefix */
        $prefix = $table['prefix'];

        return new self($exact, $prefix);
    }

    public function locate(?string $originLocation): ?GeoPoint
    {
        $postalCode = self::postalCode($originLocation);

        if ($postalCode === null) {
            return null;
        }

        if (isset($this->exact[$postalCode])) {
            [$latitude, $longitude] = $this->exact[$postalCode];

            return new GeoPoint($latitude, $longitude, false);
        }

        $prefix = substr($postalCode, 0, 3);

        if (isset($this->prefix[$prefix])) {
            [$latitude, $longitude] = $this->prefix[$prefix];

            return new GeoPoint($latitude, $longitude, true);
        }

        return null;
    }

    /**
     * The leading five-digit postal code of a location string, or null. A code
     * may run straight into the town name ("72768Reutlingen"); a longer digit
     * run (a stray phone number) is rejected.
     */
    public static function postalCode(?string $originLocation): ?string
    {
        if ($originLocation === null) {
            return null;
        }

        if (preg_match('/^(\d{5})(?!\d)/', ltrim($originLocation), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
