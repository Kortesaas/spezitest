<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog\Geo;

use RuntimeException;

/**
 * Resolves a drink's `origin_location` to an approximate coordinate.
 *
 * Placement is offline and deterministic: a postal code at the start of the
 * location string is looked up in the bundled GeoNames centroid table
 * ({@see Geo/postal-centroids.php}). No address is geocoded and no network call
 * is made.
 *
 * Germany is the default — a five-digit code, optionally with a `D-` prefix.
 * Austria (`A-`/`AT-`), Switzerland (`CH-`) and Liechtenstein (`FL-`) are
 * recognised by their prefix, or by a bare four-digit code when `origin_region`
 * names the country. Sweden (`SE-`) shares Germany's five-digit format, so it
 * needs the `SE-` prefix or an `origin_region` of "Schweden" to be told apart;
 * its table is keyed by the three-digit prefix, which pins the postal town.
 * Anything else — other foreign entries, blanks — resolves to null and the map
 * lists them separately.
 *
 * The German table also carries each code's town name and a name → code map,
 * used by the search box; the foreign tables are coordinates only.
 */
final readonly class PostalGeocoder
{
    /** Words in `origin_region` that pin down a mapped foreign country. */
    private const REGION_COUNTRY = [
        'oesterreich' => 'AT',
        'austria' => 'AT',
        'schweiz' => 'CH',
        'suisse' => 'CH',
        'svizzera' => 'CH',
        'switzerland' => 'CH',
        'liechtenstein' => 'LI',
        'schweden' => 'SE',
        'sweden' => 'SE',
        'sverige' => 'SE',
    ];

    /**
     * Foreign countries whose table is keyed by a shortened postal code rather
     * than the full one. Sweden's five-digit codes are dense; the three-digit
     * prefix is the postal town, which is all the map needs.
     */
    private const FOREIGN_KEY_LENGTH = ['SE' => 3];

    /**
     * Words in `origin_region` that name a country we do not map. A five-digit
     * code paired with one of these is not a German postal code — it must not
     * fall through to a German Leitregion guess.
     */
    private const FOREIGN_REGION_WORDS = [
        'usa', 'vereinigtestaaten', 'frankreich', 'france', 'italien', 'italia', 'italy',
        'niederlande', 'netherlands', 'belgien', 'belgium', 'luxemburg', 'luxembourg',
        'daenemark', 'denmark', 'polen', 'poland', 'tschechien', 'czechia',
        'grossbritannien', 'england', 'spanien', 'spain',
    ];

    /**
     * @param array<int|string, array{0: float, 1: float, 2?: string}> $exact German codes, keyed by five-digit postal code
     * @param array<int|string, array{0: float, 1: float}> $prefix keyed by three-digit Leitregion prefix
     * @param array<string, string> $byName normalised German town name → one representative postal code
     * @param array<string, array<int|string, array{0: float, 1: float, 2?: string}>> $foreign country code → postal key → coordinate (AT/CH/LI keyed by the four-digit code, SE by the three-digit prefix)
     */
    public function __construct(
        private array $exact,
        private array $prefix,
        private array $byName = [],
        private array $foreign = [],
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
        /** @var array<string, array<int|string, array{0: float, 1: float, 2?: string}>> $foreign */
        $foreign = isset($table['foreign']) && is_array($table['foreign']) ? $table['foreign'] : [];

        return new self($exact, $prefix, $byName, $foreign);
    }

    public function locate(?string $originLocation, ?string $originRegion = null): ?GeoPoint
    {
        $classification = self::classify($originLocation, $originRegion);

        if ($classification === null) {
            return null;
        }

        ['country' => $country, 'code' => $code] = $classification;

        if ($country !== 'DE') {
            $row = $this->foreign[$country][self::foreignKey($country, $code)] ?? null;

            return $row === null ? null : new GeoPoint($row[0], $row[1], false);
        }

        if (isset($this->exact[$code])) {
            [$latitude, $longitude] = $this->exact[$code];

            return new GeoPoint($latitude, $longitude, false);
        }

        $prefix = substr($code, 0, 3);

        if (isset($this->prefix[$prefix])) {
            [$latitude, $longitude] = $this->prefix[$prefix];

            return new GeoPoint($latitude, $longitude, true);
        }

        return null;
    }

    /**
     * The town name recorded for an exact German postal code, or null.
     */
    public function place(string $postalCode): ?string
    {
        $name = $this->exact[$postalCode][2] ?? '';

        return $name === '' ? null : $name;
    }

    /**
     * One representative postal code for a German town, matched on its
     * normalised name ({@see self::normalise()}). Null when the town is not in
     * the table.
     */
    public function postalCodeFor(string $townName): ?string
    {
        return $this->byName[self::normalise($townName)] ?? null;
    }

    /**
     * Up to `$limit` German postal codes whose digits start with `$digits`,
     * each with its town name, in numeric order — for the search-box type-ahead.
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
     * The country and postal code a location string resolves to, or null.
     *
     * "72768 Reutlingen" → DE/72768, "A-5020 Salzburg" → AT/5020, "CH-8001
     * Zürich" → CH/8001, "SE-35246 Växjö" → SE/35246. A bare four-digit code
     * needs `origin_region` to name the country ("5020 Salzburg" + "Österreich"
     * → AT/5020); a bare five-digit code is German unless `origin_region` says
     * "Schweden" ("35246 Växjö" + "Schweden" → SE/35246). A code may run
     * straight into the town name; a longer digit run (a stray phone number) is
     * rejected.
     *
     * @return array{country: string, code: string}|null
     */
    public static function classify(?string $originLocation, ?string $originRegion = null): ?array
    {
        if ($originLocation === null) {
            return null;
        }

        $value = ltrim($originLocation);

        // The country can also sit at the end of the location itself
        // ("35246 Växjö, Schweden"); fall back to that when no region is given.
        $regionHint = $originRegion;

        if (($regionHint === null || trim($regionHint) === '') && str_contains($value, ',')) {
            $regionHint = trim((string) substr($value, (int) strrpos($value, ',') + 1));
        }

        if (preg_match('/^(?:A|AT)[-\s]*(\d{4})(?!\d)/i', $value, $matches) === 1) {
            return ['country' => 'AT', 'code' => $matches[1]];
        }

        if (preg_match('/^CH[-\s]*(\d{4})(?!\d)/i', $value, $matches) === 1) {
            return ['country' => 'CH', 'code' => $matches[1]];
        }

        if (preg_match('/^(?:FL|LI)[-\s]*(\d{4})(?!\d)/i', $value, $matches) === 1) {
            return ['country' => 'LI', 'code' => $matches[1]];
        }

        // Sweden: "SE-35246 Växjö" or the "SE-352 46 Växjö" spaced form.
        if (preg_match('/^SE[-\s]*(\d{3})[-\s]?(\d{2})(?!\d)/i', $value, $matches) === 1) {
            return ['country' => 'SE', 'code' => $matches[1] . $matches[2]];
        }

        if (preg_match('/^(?:D|DE)?[-\s]*(\d{5})(?!\d)/i', $value, $matches) === 1) {
            $regionCountry = self::countryFromRegion($regionHint);

            // Sweden is the one mapped neighbour that also uses five digits.
            if ($regionCountry === 'SE') {
                return ['country' => 'SE', 'code' => $matches[1]];
            }

            if ($regionCountry !== null || self::namesUnmappedCountry($regionHint)) {
                return null; // a foreign code that only looks German
            }

            return ['country' => 'DE', 'code' => $matches[1]];
        }

        if (preg_match('/^(\d{4})(?!\d)/', $value, $matches) === 1) {
            $country = self::countryFromRegion($regionHint);

            return $country === null || $country === 'DE' ? null : ['country' => $country, 'code' => $matches[1]];
        }

        return null;
    }

    /**
     * The hunt map's grouping key for a classified location: a bare German
     * postal code, or a country-namespaced foreign one (`at-7122`, `se-352`)
     * because a short code is not unique across countries. Sweden groups by the
     * three-digit postal town, matching its centroid table.
     *
     * @param array{country: string, code: string} $classification
     */
    public static function mapKey(array $classification): string
    {
        if ($classification['country'] === 'DE') {
            return $classification['code'];
        }

        return strtolower($classification['country'])
            . '-' . self::foreignKey($classification['country'], $classification['code']);
    }

    /**
     * The key a foreign postal code takes in its country table — the full code
     * for AT/CH/LI, the three-digit prefix for Sweden.
     */
    private static function foreignKey(string $country, string $code): string
    {
        $length = self::FOREIGN_KEY_LENGTH[$country] ?? strlen($code);

        return substr($code, 0, $length);
    }

    /**
     * The leading five-digit German postal code of a location string, or null —
     * for the search box, which reads a bare term with no region context.
     */
    public static function postalCode(?string $originLocation): ?string
    {
        $classification = self::classify($originLocation);

        return $classification !== null && $classification['country'] === 'DE' ? $classification['code'] : null;
    }

    private static function countryFromRegion(?string $originRegion): ?string
    {
        if ($originRegion === null) {
            return null;
        }

        $key = self::normalise($originRegion);

        foreach (self::REGION_COUNTRY as $needle => $country) {
            if (str_contains($key, $needle)) {
                return $country;
            }
        }

        return null;
    }

    private static function namesUnmappedCountry(?string $originRegion): bool
    {
        if ($originRegion === null) {
            return false;
        }

        $key = self::normalise($originRegion);

        foreach (self::FOREIGN_REGION_WORDS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }
}
