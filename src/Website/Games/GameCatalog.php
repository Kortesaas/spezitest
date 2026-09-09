<?php

declare(strict_types=1);

namespace Spezitest\Website\Games;

use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\RatedDrinkCollection;

/**
 * Read-only, privacy-neutral projections of catalogue data for the games.
 *
 * The browser receives only data that is already public on the catalogue. No
 * play event, guess, score, timer, or device identifier is sent back.
 */
final class GameCatalog
{
    /**
     * Geography rounds require both a bottle and an exact map coordinate.
     * Prefix-centroid guesses are deliberately excluded: scoring a player
     * against a location we only know approximately would be unfair.
     *
     * @return list<array{id: int, name: string, image: string, location: string, latitude: float, longitude: float}>
     */
    public static function geography(RatedDrinkCollection $collection, PostalGeocoder $geocoder): array
    {
        $rounds = [];

        foreach ($collection->byName() as $drink) {
            if (!$drink->hasImage || $drink->originLocation === null) {
                continue;
            }

            $point = $geocoder->locate($drink->originLocation, $drink->originRegion);

            if ($point === null || $point->approximate) {
                continue;
            }

            $rounds[] = [
                'id' => $drink->id,
                'name' => $drink->name,
                'image' => '/spezi/' . $drink->id . '/bild',
                'location' => $drink->originLocation,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
            ];
        }

        return $rounds;
    }

    /** @return list<array{id: int, name: string, image: string}> */
    public static function memory(RatedDrinkCollection $collection): array
    {
        $cards = [];

        foreach ($collection->byName() as $drink) {
            if (!$drink->hasImage) {
                continue;
            }

            $cards[] = [
                'id' => $drink->id,
                'name' => $drink->name,
                'image' => '/spezi/' . $drink->id . '/bild',
            ];
        }

        return $cards;
    }

    /** @return list<array{id: int, name: string, slug: string, image: ?string}> */
    public static function realNames(RatedDrinkCollection $collection): array
    {
        $seen = [];
        $drinks = [];

        foreach ($collection->byName() as $drink) {
            $key = FakeNameGenerator::normalise($drink->name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $drinks[] = [
                'id' => $drink->id,
                'name' => $drink->name,
                'slug' => $drink->slug(),
                'image' => $drink->hasImage ? '/spezi/' . $drink->id . '/bild' : null,
            ];
        }

        return $drinks;
    }
}
