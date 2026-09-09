<?php

declare(strict_types=1);

namespace Spezitest\Website\Games;

/**
 * Builds a large first-party pool of plausible game-only product names.
 *
 * The September 2026 catalogue snapshot contains 196 names; 142 use two or
 * three whitespace-separated words, and its dominant endings are Cola-Mix,
 * Mix, Cola-Orange and Colamix. The generator mirrors those shapes. Every
 * candidate has two or three words, is deduplicated, and is checked against
 * the live catalogue before it reaches the browser. It uses no AI or network
 * service at runtime and never writes generated names into the database.
 */
final class FakeNameGenerator
{
    /** @var list<string> deliberately single-token, fictional brand stems */
    private const BRANDS = [
        'Alpenfunke', 'Auenperle', 'Bärenquell', 'Bergadler', 'Berggold', 'Bergkumpel',
        'Bergperle', 'Birkentaler', 'Brauquell', 'Burgbrunnen', 'Burgperle', 'Dorfquelle',
        'Eichenfunke', 'Eichenquell', 'Falkenperle', 'Felsenquell', 'Flussgold', 'Forstperle',
        'Grenzquelle', 'Hainbrunnen', 'Hainperle', 'Heidegold', 'Heimatquell', 'Hirschperle',
        'Hochlandquelle', 'Hofgold', 'Hofperle', 'Hopfenfunke', 'Kellerperle', 'Kronenquell',
        'Landbrunnen', 'Landgold', 'Lindenperle', 'Löwenquelle', 'Marktbrunnen', 'Moosgold',
        'Moosperle', 'Mühlenquell', 'Naturfunke', 'Quellbub', 'Quellgold', 'Quellmeister',
        'Rabenquelle', 'Schlossbrunnen', 'Schlossfunke', 'Silberperle', 'Sonnenquell',
        'Sprudelbub', 'Stadtperle', 'Talbrunnen', 'Talperle', 'Tannenquell', 'Waldgold',
        'Waldperle', 'Waldschatz', 'Wiesenfunke', 'Wiesengold', 'Wiesenquell', 'Wolfsbrunnen',
        'Wolfsperle', 'Zirbenquell', 'Zirbenperle', 'Kesselgold', 'Kesselperle', 'Quellfuchs',
    ];

    /** @var list<string> catalogue-like compound starts, each kept to one token */
    private const COMPOUND_PREFIXES = [
        'Alpen', 'Auen', 'Bären', 'Berg', 'Birken', 'Brau', 'Burg', 'Dorf', 'Eichen',
        'Falken', 'Felsen', 'Fluss', 'Forst', 'Grenz', 'Hain', 'Heide', 'Heimat',
        'Hirsch', 'Hochland', 'Hof', 'Hopfen', 'Keller', 'Kronen', 'Land', 'Linden',
        'Löwen', 'Markt', 'Moos', 'Mühlen', 'Natur', 'Raben', 'Schloss', 'Silber',
        'Sonnen', 'Stadt', 'Tal', 'Tannen', 'Wald', 'Wiesen', 'Wolfs', 'Zirben', 'Kessel',
    ];

    /** @var list<string> catalogue-like compound endings */
    private const COMPOUND_SUFFIXES = [
        'adler', 'brunnen', 'bub', 'funke', 'fuchs', 'gold', 'kumpel', 'meister',
        'perle', 'quell', 'schatz',
    ];

    /** @var list<string> `%s` receives one brand stem; every result stays at two or three words */
    private const PATTERNS = [
        '%s Cola-Mix',
        '%s Cola Mix',
        '%s Colamix',
        '%s ColaMix',
        '%s Cola-Orange',
        '%s Cola Orange',
        '%s Cola-Mix-Limonade',
        '%s Mix',
        '%s Spezi',
    ];

    /**
     * @param list<string> $realNames
     * @return list<string>
     */
    public static function pool(array $realNames): array
    {
        $blocked = [];

        foreach ($realNames as $name) {
            $blocked[self::normalise($name)] = true;
        }

        $pool = [];
        $brands = self::BRANDS;

        foreach (self::COMPOUND_PREFIXES as $prefix) {
            foreach (self::COMPOUND_SUFFIXES as $suffix) {
                $brands[] = $prefix . $suffix;
            }
        }

        foreach ($brands as $brand) {
            foreach (self::PATTERNS as $pattern) {
                $candidate = sprintf($pattern, $brand);
                $key = self::normalise($candidate);

                if (isset($blocked[$key]) || isset($pool[$key])) {
                    continue;
                }

                $pool[$key] = $candidate;
            }
        }

        return array_values($pool);
    }

    public static function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/[^a-z0-9]+/', '', $name);
    }
}
