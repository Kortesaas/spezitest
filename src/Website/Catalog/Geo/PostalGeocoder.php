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
 *
 * The same table carries each code's town name and a name → representative code
 * map, used by the search box to show a town its postal code and vice versa.
 */
final readonly class PostalGeocoder
{
    /**
     * @param array<int|string, array{0: float, 1: float, 2?: string}> $exact keyed by five-digit postal code
     * @param array<int|string, array{0: float, 1: float}> $prefix keyed by three-digit Leitregion prefix
     * @param array<string, string> $byName normalised town name → one representative postal code
     */
    public function __construct(
        private array $exact,
        private array $prefix,
        private array $byName = [],
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

        /** @var array<int|string, array{0: float, 1: float, 2?: string}> $exact */
        $exact = $table['exact'];
        /** @var array<int|string, array{0: float, 1: float}> $prefix */
        $prefix = $table['prefix'];
        /** @var array<string, string> $byName */
        $byName = isset($table['byName']) && is_array($table['byName']) ? $table['byName'] : [];

        return new self($exact, $prefix, $byName);
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
     * The town name recorded for an exact postal code, or null.
     */
    public function place(string $postalCode): ?string
    {
        $name = $this->exact[$postalCode][2] ?? '';

        return $name === '' ? null : $name;
    }

    /**
     * One representative postal code for a town, matched on its normalised name
     * ({@see self::normalise()}). Null when the town is not in the table.
     */
    public function postalCodeFor(string $townName): ?string
    {
        return $this->byName[self::normalise($townName)] ?? null;
    }

    /**
     * Up to `$limit` postal codes whose digits start with `$digits`, each with
     * its town name, in numeric order — for the search-box type-ahead.
     *
     * @return list<array{code: string, place: ?string}>
     */
    public function startingWith(string $digits, int $limit = 8): array
    {
        if (preg_match('/^\d{2,5}$/', $digits) !== 1) {
            return [];
        }

        $matches = [];

        foreach ($this->exact as $code => $value) {
            $code = (string) $code;

            if (!str_starts_with($code, $digits)) {
                continue;
            }

            $name = $value[2] ?? '';
            $matches[$code] = $name === '' ? null : $name;
        }

        ksort($matches, SORT_STRING);
        $matches = array_slice($matches, 0, $limit, true);

        $out = [];

        foreach ($matches as $code => $place) {
            $out[] = ['code' => (string) $code, 'place' => $place];
        }

        return $out;
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

    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }
}
