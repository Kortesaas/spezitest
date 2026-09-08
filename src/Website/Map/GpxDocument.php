<?php

declare(strict_types=1);

namespace Spezitest\Website\Map;

/**
 * Builds a GPX 1.1 waypoint file for the hunt map.
 *
 * A GPX file carries the place name inside each `<wpt>`, so it survives into any
 * map or GPS app (OsmAnd, Organic Maps, Komoot, Garmin, …) — the one location
 * label that every one of them keeps. Served from the site's own origin so a
 * phone's "open with" sheet has a real file to hand to an app.
 */
final readonly class GpxDocument
{
    /**
     * @param list<array{latitude: float, longitude: float, name: string, description: ?string}> $waypoints
     */
    public function __construct(private array $waypoints)
    {
    }

    public function render(): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<gpx version="1.1" creator="Spezitest — spezitest.de"'
            . ' xmlns="http://www.topografix.com/GPX/1/1">' . "\n";

        foreach ($this->waypoints as $waypoint) {
            $out .= sprintf(
                "  <wpt lat=\"%s\" lon=\"%s\">\n    <name>%s</name>\n",
                number_format($waypoint['latitude'], 5, '.', ''),
                number_format($waypoint['longitude'], 5, '.', ''),
                self::escape($waypoint['name']),
            );

            if ($waypoint['description'] !== null && $waypoint['description'] !== '') {
                $out .= '    <desc>' . self::escape($waypoint['description']) . "</desc>\n";
            }

            $out .= "  </wpt>\n";
        }

        return $out . "</gpx>\n";
    }

    /**
     * A safe download filename stem (no extension) from a label.
     */
    public static function filename(string $label): string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'];
        $slug = strtr($label, $map);
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $slug));
        $slug = trim($slug, '-');

        return $slug === '' ? 'spezi' : $slug;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
