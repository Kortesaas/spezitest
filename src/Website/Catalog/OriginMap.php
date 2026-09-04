<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * Places catalogued drinks on an abstracted map of Germany.
 *
 * There are no coordinate columns in the database and none are invented here.
 * A drink is placed only when its `origin_location` starts with a German
 * five-digit postal code; the first two digits identify the postal
 * "Leitregion", and each of those has a fixed approximate centre in the table
 * below. A dot therefore marks a postal region, never an exact address — the
 * rendered map says so, and drinks that cannot be placed are reported as such
 * rather than dropped silently or guessed.
 *
 * The outline and the dots go through the same projection, so they always line
 * up regardless of the chosen canvas size.
 */
final readonly class OriginMap
{
    /** Canvas padding and scale of the projection, in SVG user units. */
    private const PADDING = 14.0;

    private const SCALE = 78.0;

    private const LON_ORIGIN = 5.8;

    private const LAT_ORIGIN = 55.15;

    /** cos(51°): keeps longitudes from stretching at German latitudes. */
    private const LON_FACTOR = 0.6293;

    /**
     * Approximate centre of every German two-digit postal region as
     * `[latitude, longitude, area name]`. Values are rounded to the region,
     * not the town.
     */
    private const POSTAL_REGIONS = [
        '01' => [51.05, 13.74, 'Dresden'],
        '02' => [51.18, 14.43, 'Bautzen'],
        '03' => [51.76, 14.33, 'Cottbus'],
        '04' => [51.34, 12.37, 'Leipzig'],
        '06' => [51.48, 11.97, 'Halle'],
        '07' => [50.88, 11.97, 'Gera'],
        '08' => [50.72, 12.49, 'Zwickau'],
        '09' => [50.83, 12.92, 'Chemnitz'],
        '10' => [52.52, 13.40, 'Berlin'],
        '12' => [52.45, 13.40, 'Berlin'],
        '13' => [52.57, 13.35, 'Berlin'],
        '14' => [52.40, 13.06, 'Potsdam'],
        '15' => [52.34, 14.55, 'Frankfurt (Oder)'],
        '16' => [52.83, 13.24, 'Oranienburg'],
        '17' => [53.56, 13.26, 'Neubrandenburg'],
        '18' => [54.09, 12.14, 'Rostock'],
        '19' => [53.63, 11.41, 'Schwerin'],
        '20' => [53.55, 9.99, 'Hamburg'],
        '21' => [53.25, 10.41, 'Lüneburg'],
        '22' => [53.60, 10.05, 'Hamburg'],
        '23' => [53.87, 10.69, 'Lübeck'],
        '24' => [54.32, 10.14, 'Kiel'],
        '25' => [54.10, 9.30, 'Itzehoe'],
        '26' => [53.30, 7.80, 'Oldenburg'],
        '27' => [53.55, 8.58, 'Bremerhaven'],
        '28' => [53.08, 8.81, 'Bremen'],
        '29' => [52.83, 10.06, 'Celle'],
        '30' => [52.37, 9.73, 'Hannover'],
        '31' => [52.15, 9.95, 'Hildesheim'],
        '32' => [52.20, 8.67, 'Minden'],
        '33' => [51.90, 8.63, 'Bielefeld'],
        '34' => [51.31, 9.49, 'Kassel'],
        '35' => [50.80, 8.77, 'Gießen'],
        '36' => [50.55, 9.68, 'Fulda'],
        '37' => [51.53, 9.94, 'Göttingen'],
        '38' => [52.27, 10.52, 'Braunschweig'],
        '39' => [52.13, 11.63, 'Magdeburg'],
        '40' => [51.23, 6.78, 'Düsseldorf'],
        '41' => [51.19, 6.44, 'Mönchengladbach'],
        '42' => [51.26, 7.15, 'Wuppertal'],
        '44' => [51.51, 7.47, 'Dortmund'],
        '45' => [51.46, 7.01, 'Essen'],
        '46' => [51.50, 6.65, 'Oberhausen'],
        '47' => [51.43, 6.76, 'Duisburg'],
        '48' => [51.96, 7.63, 'Münster'],
        '49' => [52.28, 8.05, 'Osnabrück'],
        '50' => [50.94, 6.96, 'Köln'],
        '51' => [51.03, 7.08, 'Leverkusen'],
        '52' => [50.78, 6.08, 'Aachen'],
        '53' => [50.73, 7.10, 'Bonn'],
        '54' => [49.76, 6.64, 'Trier'],
        '55' => [49.99, 8.25, 'Mainz'],
        '56' => [50.36, 7.59, 'Koblenz'],
        '57' => [50.88, 8.02, 'Siegen'],
        '58' => [51.36, 7.47, 'Hagen'],
        '59' => [51.68, 7.82, 'Hamm'],
        '60' => [50.11, 8.68, 'Frankfurt am Main'],
        '61' => [50.23, 8.61, 'Bad Homburg'],
        '63' => [50.06, 8.90, 'Offenbach'],
        '64' => [49.87, 8.65, 'Darmstadt'],
        '65' => [50.08, 8.24, 'Wiesbaden'],
        '66' => [49.24, 6.99, 'Saarbrücken'],
        '67' => [49.44, 8.30, 'Ludwigshafen'],
        '68' => [49.49, 8.47, 'Mannheim'],
        '69' => [49.40, 8.68, 'Heidelberg'],
        '70' => [48.78, 9.18, 'Stuttgart'],
        '71' => [48.85, 9.10, 'Ludwigsburg'],
        '72' => [48.52, 9.06, 'Tübingen'],
        '73' => [48.70, 9.65, 'Göppingen'],
        '74' => [49.14, 9.22, 'Heilbronn'],
        '75' => [48.89, 8.70, 'Pforzheim'],
        '76' => [49.01, 8.40, 'Karlsruhe'],
        '77' => [48.47, 7.94, 'Offenburg'],
        '78' => [48.06, 8.46, 'Villingen-Schwenningen'],
        '79' => [47.99, 7.85, 'Freiburg'],
        '80' => [48.14, 11.58, 'München'],
        '81' => [48.11, 11.60, 'München'],
        '82' => [47.90, 11.30, 'Starnberg'],
        '83' => [47.86, 12.12, 'Rosenheim'],
        '84' => [48.54, 12.15, 'Landshut'],
        '85' => [48.60, 11.55, 'Ingolstadt'],
        '86' => [48.37, 10.90, 'Augsburg'],
        '87' => [47.73, 10.32, 'Kempten'],
        '88' => [47.78, 9.61, 'Ravensburg'],
        '89' => [48.40, 9.99, 'Ulm'],
        '90' => [49.45, 11.08, 'Nürnberg'],
        '91' => [49.40, 10.80, 'Erlangen'],
        '92' => [49.45, 11.86, 'Amberg'],
        '93' => [49.01, 12.10, 'Regensburg'],
        '94' => [48.62, 13.20, 'Passau'],
        '95' => [50.05, 11.75, 'Bayreuth'],
        '96' => [49.90, 10.90, 'Bamberg'],
        '97' => [49.79, 9.95, 'Würzburg'],
        '98' => [50.60, 10.65, 'Suhl'],
        '99' => [50.98, 11.03, 'Erfurt'],
    ];

    /**
     * Simplified national border, as [longitude, latitude] pairs running
     * clockwise from the Danish border. Deliberately coarse: the map is a
     * graphic in the Spezitest style, not a cartographic reference.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const OUTLINE = [
        [8.42, 55.05], [8.70, 54.90], [9.44, 54.83], [9.90, 54.78], [10.03, 54.47],
        [10.15, 54.33], [10.85, 54.02], [11.45, 53.95], [11.90, 54.08], [12.10, 54.18],
        [12.75, 54.30], [13.10, 54.32], [13.45, 54.60], [13.80, 54.40], [14.20, 53.95],
        [14.42, 53.25], [14.60, 52.60], [14.55, 52.35], [14.70, 51.90], [14.75, 51.55],
        [15.03, 51.28], [14.98, 51.10], [14.60, 51.00], [14.30, 51.05], [13.90, 50.80],
        [13.40, 50.65], [12.95, 50.40], [12.50, 50.35], [12.20, 50.10], [12.10, 49.95],
        [12.45, 49.75], [12.55, 49.40], [12.90, 49.30], [13.30, 49.10], [13.60, 48.95],
        [13.83, 48.77], [13.44, 48.55], [13.10, 48.28], [12.75, 48.10], [13.00, 47.85],
        [13.05, 47.65], [12.75, 47.68], [12.20, 47.70], [11.60, 47.58], [11.25, 47.42],
        [10.90, 47.50], [10.45, 47.55], [10.20, 47.38], [10.10, 47.35], [9.85, 47.55],
        [9.60, 47.53], [9.20, 47.66], [8.85, 47.65], [8.60, 47.80], [8.45, 47.58],
        [8.10, 47.57], [7.70, 47.54], [7.59, 47.58], [7.55, 47.90], [7.58, 48.30],
        [7.80, 48.60], [8.00, 48.80], [8.10, 49.00], [7.95, 49.05], [7.60, 49.05],
        [7.10, 49.12], [6.85, 49.20], [6.60, 49.35], [6.36, 49.47], [6.15, 49.70],
        [6.13, 50.03], [6.20, 50.13], [6.35, 50.32], [6.18, 50.50], [6.03, 50.72],
        [5.98, 50.80], [6.02, 50.98], [6.09, 51.18], [5.95, 51.35], [6.17, 51.50],
        [6.22, 51.68], [6.40, 51.83], [6.72, 51.90], [6.80, 52.12], [7.07, 52.24],
        [6.98, 52.47], [7.05, 52.64], [6.95, 52.90], [7.20, 53.24], [7.02, 53.32],
        [6.95, 53.57], [7.60, 53.70], [8.10, 53.72], [8.30, 53.58], [8.50, 53.55],
        [8.62, 53.87], [8.90, 53.90], [9.10, 53.95], [8.98, 54.10], [8.85, 54.30],
        [8.65, 54.45], [8.60, 54.75], [8.55, 54.92],
    ];

    /**
     * @param list<array{key: string, area: string, x: float, y: float, count: int, tested: int, drinks: list<array{name: string, slug: string, place: string, gesamt: ?float}>}> $points
     * @param list<array{label: string, count: int}> $elsewhere
     */
    private function __construct(
        public array $points,
        public int $placed,
        public int $unplaced,
        public array $elsewhere,
        public int $largest,
    ) {
    }

    public static function fromCollection(RatedDrinkCollection $collection): self
    {
        /** @var array<string, array{key: string, area: string, x: float, y: float, count: int, tested: int, drinks: list<array{name: string, slug: string, place: string, gesamt: ?float}>}> $grouped */
        $grouped = [];
        $unplaced = 0;
        /** @var array<string, int> $elsewhere */
        $elsewhere = [];

        foreach ($collection->all() as $drink) {
            $prefix = self::postalPrefix($drink->originLocation);

            if ($prefix === null) {
                ++$unplaced;
                $label = $drink->originRegion ?? 'Ohne Herkunftsangabe';
                $elsewhere[$label] = ($elsewhere[$label] ?? 0) + 1;

                continue;
            }

            [$latitude, $longitude, $area] = self::POSTAL_REGIONS[$prefix];

            $grouped[$prefix] ??= [
                'key' => $prefix,
                'area' => $area,
                'x' => self::projectX($longitude),
                'y' => self::projectY($latitude),
                'count' => 0,
                'tested' => 0,
                'drinks' => [],
            ];

            ++$grouped[$prefix]['count'];

            if ($drink->isTested()) {
                ++$grouped[$prefix]['tested'];
            }

            $grouped[$prefix]['drinks'][] = [
                'name' => $drink->name,
                'slug' => $drink->slug(),
                'place' => self::placeName($drink->originLocation),
                'gesamt' => $drink->result?->gesamt(),
            ];
        }

        foreach ($grouped as $key => $point) {
            usort(
                $grouped[$key]['drinks'],
                static fn (array $a, array $b): int => ($b['gesamt'] ?? -1.0) <=> ($a['gesamt'] ?? -1.0),
            );
        }

        // Draw the busiest regions first so smaller dots stay clickable on top.
        uasort($grouped, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $largest = 0;
        $placed = 0;

        foreach ($grouped as $point) {
            $largest = max($largest, $point['count']);
            $placed += $point['count'];
        }

        arsort($elsewhere);
        $elsewhereList = [];

        foreach ($elsewhere as $label => $count) {
            $elsewhereList[] = ['label' => (string) $label, 'count' => $count];
        }

        return new self(array_values($grouped), $placed, $unplaced, $elsewhereList, $largest);
    }

    /**
     * The national outline as an SVG path in the same projection as the dots.
     */
    public function outlinePath(): string
    {
        $commands = [];

        foreach (self::OUTLINE as $index => [$longitude, $latitude]) {
            $commands[] = ($index === 0 ? 'M' : 'L')
                . self::round(self::projectX($longitude)) . ' ' . self::round(self::projectY($latitude));
        }

        return implode(' ', $commands) . ' Z';
    }

    public function viewBox(): string
    {
        $maxX = 0.0;
        $maxY = 0.0;

        foreach (self::OUTLINE as [$longitude, $latitude]) {
            $maxX = max($maxX, self::projectX($longitude));
            $maxY = max($maxY, self::projectY($latitude));
        }

        return '0 0 ' . self::round($maxX + self::PADDING) . ' ' . self::round($maxY + self::PADDING);
    }

    /**
     * Dot radius, scaled by how many drinks share the region. Kept within a
     * narrow band so a busy region reads as bigger without hiding its
     * neighbours.
     */
    public function radius(int $count): float
    {
        if ($this->largest <= 1) {
            return 5.0;
        }

        $share = ($count - 1) / ($this->largest - 1);

        return round(5.0 + $share * 7.0, 1);
    }

    private static function postalPrefix(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        if (preg_match('/^(\d{5})\b/', trim($location), $matches) !== 1) {
            return null;
        }

        $prefix = substr($matches[1], 0, 2);

        return isset(self::POSTAL_REGIONS[$prefix]) ? $prefix : null;
    }

    private static function placeName(?string $location): string
    {
        $location = trim((string) $location);

        return (string) preg_replace('/^\d{5}\s+/', '', $location);
    }

    private static function projectX(float $longitude): float
    {
        return self::PADDING + ($longitude - self::LON_ORIGIN) * self::LON_FACTOR * self::SCALE;
    }

    private static function projectY(float $latitude): float
    {
        return self::PADDING + (self::LAT_ORIGIN - $latitude) * self::SCALE;
    }

    private static function round(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
