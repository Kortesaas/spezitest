<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * Which Spezis the `/karte` map shows. The bare `/karte` URL is {@see self::All};
 * `/karte/getestet` and `/karte/gesucht` narrow it down. The public GeoJSON feed
 * is deliberately not a scope here — it always stays on the identified drinks.
 */
enum MapScope: string
{
    case All = 'alle';
    case Tested = 'getestet';
    case Sought = 'gesucht';

    /**
     * The scope for a URL path segment, falling back to {@see self::All} for a
     * missing or unknown value (so `/karte` and a stray `/karte/foo` both work).
     */
    public static function fromPath(?string $segment): self
    {
        return $segment === null ? self::All : (self::tryFrom($segment) ?? self::All);
    }

    /**
     * The drinks this scope places on the map, already ordered for the side list.
     *
     * @return list<RatedDrink>
     */
    public function selectDrinks(RatedDrinkCollection $collection): array
    {
        return match ($this) {
            self::All => $collection->byName(),
            self::Tested => $collection->tested(),
            self::Sought => $collection->identified(),
        };
    }
}
