<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

use Spezitest\Website\Catalog\Geo\PostalGeocoder;

/**
 * Places a set of drinks on a real map by where they come from. The caller
 * chooses the set: the whole catalogue, just the tested ones, or the ones still
 * to be tested ({@see MapScope}); the public GeoJSON feed keeps to the
 * identified drinks via {@see self::fromCollection()}.
 *
 * Each drink is positioned from the postal code in its `origin_location` via
 * {@see PostalGeocoder} — Germany, or Austria / Switzerland / Liechtenstein /
 * Sweden when a country prefix or `origin_region` says so. Drinks sharing a place
 * become one point with a combined list. Drinks whose origin resolves to no
 * mapped postal code (other foreign origins, blank entries) are reported in
 * {@see self::$unplaced} rather than dropped or guessed.
 */
final readonly class HuntMap
{
    /** Foreign countries the map places, by their classification code. */
    private const COUNTRY_NAMES = [
        'AT' => 'Österreich',
        'CH' => 'Schweiz',
        'LI' => 'Liechtenstein',
        'SE' => 'Schweden',
    ];

    /**
     * @param list<array{
     *     key: string,
     *     postalCode: string,
     *     countryCode: string,
     *     country: ?string,
     *     place: string,
     *     latitude: float,
     *     longitude: float,
     *     approximate: bool,
     *     count: int,
     *     drinks: list<array{id: int, name: string, slug: string, manufacturer: ?string, hasImage: bool}>
     * }> $points
     * @param list<array{name: string, slug: string, location: ?string}> $unplaced
     */
    private function __construct(
        public array $points,
        public array $unplaced,
        public int $placed,
    ) {
    }

    /**
     * The still-wanted drinks only ({@see RatedDrinkCollection::identified()}).
     * The public GeoJSON feed uses this; the `/karte` page uses
     * {@see self::fromDrinks()} with a scope-selected list.
     */
    public static function fromCollection(
        RatedDrinkCollection $collection,
        PostalGeocoder $geocoder,
    ): self {
        return self::fromDrinks($collection->identified(), $geocoder);
    }

    /**
     * Place a given list of drinks — the caller decides which lifecycle states
     * to include ({@see MapScope}).
     *
     * @param list<RatedDrink> $drinks
     */
    public static function fromDrinks(array $drinks, PostalGeocoder $geocoder): self
    {
        /**
         * @var array<string, array{
         *     key: string, postalCode: string, countryCode: string, country: ?string,
         *     place: string, latitude: float, longitude: float,
         *     approximate: bool, count: int,
         *     drinks: list<array{id: int, name: string, slug: string, manufacturer: ?string, hasImage: bool}>
         * }> $grouped
         */
        $grouped = [];
        $unplaced = [];
        $placed = 0;

        foreach ($drinks as $drink) {
            $point = $geocoder->locate($drink->originLocation, $drink->originRegion);
            $classification = PostalGeocoder::classify($drink->originLocation, $drink->originRegion);

            if ($point === null || $classification === null) {
                $unplaced[] = [
                    'name' => $drink->name,
                    'slug' => $drink->slug(),
                    'location' => $drink->displayOrigin(),
                ];

                continue;
            }

            ['country' => $country, 'code' => $code] = $classification;
            $key = PostalGeocoder::mapKey($classification);
            ++$placed;

            $grouped[$key] ??= [
                'key' => $key,
                'postalCode' => $code,
                'countryCode' => $country,
                'country' => self::COUNTRY_NAMES[$country] ?? null,
                'place' => self::placeName($drink->originLocation),
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'approximate' => $point->approximate,
                'count' => 0,
                'drinks' => [],
            ];

            ++$grouped[$key]['count'];
            $grouped[$key]['drinks'][] = [
                'id' => $drink->id,
                'name' => $drink->name,
                'slug' => $drink->slug(),
                'manufacturer' => $drink->manufacturer,
                'hasImage' => $drink->hasImage,
            ];
        }

        foreach ($grouped as $key => $point) {
            usort(
                $grouped[$key]['drinks'],
                static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
            );
        }

        // Busiest regions first: the side list leads with the places worth a detour.
        uasort($grouped, static fn (array $a, array $b): int => [$b['count'], $a['place']] <=> [$a['count'], $b['place']]);

        usort($unplaced, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return new self(array_values($grouped), $unplaced, $placed);
    }

    public function total(): int
    {
        return $this->placed + count($this->unplaced);
    }

    public function isEmpty(): bool
    {
        return $this->points === [] && $this->unplaced === [];
    }

    /**
     * The point data the browser map needs, ready for {@see json_encode()}.
     *
     * @return list<array{
     *     lat: float, lon: float, place: string, key: string, postalCode: string,
     *     country: ?string, approximate: bool,
     *     drinks: list<array{name: string, slug: string, sub: string, image: ?string}>
     * }>
     */
    public function markers(): array
    {
        $markers = [];

        foreach ($this->points as $point) {
            $drinks = [];

            foreach ($point['drinks'] as $drink) {
                $drinks[] = [
                    'name' => $drink['name'],
                    'slug' => $drink['slug'],
                    'sub' => $drink['manufacturer'] ?? '',
                    'image' => $drink['hasImage'] ? '/spezi/' . $drink['id'] . '/bild' : null,
                ];
            }

            $markers[] = [
                'lat' => $point['latitude'],
                'lon' => $point['longitude'],
                'place' => $point['place'],
                'key' => $point['key'],
                'postalCode' => $point['postalCode'],
                'country' => $point['country'],
                'approximate' => $point['approximate'],
                'drinks' => $drinks,
            ];
        }

        return $markers;
    }

    /**
     * The pin label for one point: the Spezi name when a place holds a single
     * one, otherwise the place and the count.
     *
     * @param array{count: int, place: string, drinks: list<array{name: string}>} $point
     */
    public static function pointLabel(array $point): string
    {
        if ($point['count'] === 1 && isset($point['drinks'][0])) {
            return $point['drinks'][0]['name'];
        }

        return $point['place'] . ' · ' . $point['count'] . ' Spezis';
    }

    /**
     * @param array{drinks: list<array{name: string}>} $point
     */
    public static function pointDrinkNames(array $point): string
    {
        return implode(', ', array_map(
            static fn (array $drink): string => $drink['name'],
            $point['drinks'],
        ));
    }

    /**
     * Every placed drink as a GPX-ready waypoint.
     *
     * @return list<array{latitude: float, longitude: float, name: string, description: ?string}>
     */
    public function waypoints(): array
    {
        return $this->waypointsFrom($this->points);
    }

    /**
     * The waypoints for a single map key (empty when it is not on the map). The
     * key is a bare German postal code or a namespaced foreign one (`at-7122`).
     *
     * @return list<array{latitude: float, longitude: float, name: string, description: ?string}>
     */
    public function waypointsForKey(string $key): array
    {
        return $this->waypointsFrom(array_values(array_filter(
            $this->points,
            static fn (array $point): bool => $point['key'] === $key,
        )));
    }

    /**
     * @param list<array{
     *     latitude: float, longitude: float, count: int, place: string,
     *     drinks: list<array{name: string}>
     * }> $points
     * @return list<array{latitude: float, longitude: float, name: string, description: ?string}>
     */
    private function waypointsFrom(array $points): array
    {
        return array_map(
            static function (array $point): array {
                $label = self::pointLabel($point);
                $names = self::pointDrinkNames($point);

                return [
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'name' => $label,
                    // Only when it adds something over the name (a multi-Spezi place).
                    'description' => $names === '' || $names === $label ? null : $names,
                ];
            },
            $points,
        );
    }

    private static function placeName(?string $location): string
    {
        $location = trim((string) $location);
        // Drop a leading country tag and the postal code: "A-5020 Salzburg",
        // "SE-352 46 Växjö" and "72768 Reutlingen" all leave just the town.
        $withoutCode = preg_replace(
            '/^\s*(?:(?:A|AT|CH|D|DE|FL|LI|SE)[-\s]*)?(?:\d{3}\s\d{2}|\d{4,5})[-\s]*/i',
            '',
            $location,
        );
        $place = $withoutCode === null || $withoutCode === '' ? $location : $withoutCode;

        // Drop a trailing country ("Växjö, Schweden") — the map adds it back itself.
        $place = (string) preg_replace(
            '/,\s*(?:Schweden|Sweden|Sverige|Österreich|Oesterreich|Schweiz|Liechtenstein)\s*$/iu',
            '',
            $place,
        );

        return trim($place);
    }
}
