<?php

declare(strict_types=1);

namespace Spezitest\Website\Map;

use Closure;

/**
 * Serves OpenStreetMap raster tiles for the hunt map through the site's own
 * origin.
 *
 * The visitor's browser only ever talks to spezitest.de: this class fetches a
 * missing tile from the upstream tile server once, stores it under
 * `var/tile-cache/`, and serves every later request from disk. The upstream
 * request carries the server's address, never the visitor's, so the map adds no
 * third-party request to the page and the privacy policy needs no tile-service
 * disclosure.
 *
 * Requests are bounded to Germany and its immediate surroundings at the zoom
 * levels the map actually uses, so the route cannot be turned into an open
 * world-wide tile proxy.
 */
final class TileProxy
{
    private const MIN_ZOOM = 5;

    private const MAX_ZOOM = 12;

    /**
     * Bounding box (lat/lon min-max) covering Germany, its neighbours and a
     * map margin — wide enough that the visible area is always tiled, tight
     * enough that the route is not a usable world-wide proxy.
     */
    private const LAT_MIN = 44.0;

    private const LAT_MAX = 58.0;

    private const LON_MIN = 2.0;

    private const LON_MAX = 19.0;

    private const UPSTREAM = 'https://tile.openstreetmap.org/%d/%d/%d.png';

    private const USER_AGENT = 'Spezitest/1.0 (+https://www.spezitest.de; first-party hunt-map tile cache)';

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /** @var Closure(string): ?string */
    private readonly Closure $fetcher;

    /**
     * @param Closure(string): ?string|null $fetcher Overridable upstream fetch, for tests.
     */
    public function __construct(
        private readonly string $cacheDir,
        ?Closure $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? self::defaultFetcher();
    }

    /**
     * PNG bytes for a tile, or null when the coordinates are out of range or the
     * tile is neither cached nor fetchable.
     */
    public function tile(int $zoom, int $x, int $y): ?string
    {
        if (!$this->inRange($zoom, $x, $y)) {
            return null;
        }

        $cached = $this->readCache($zoom, $x, $y);

        if ($cached !== null) {
            return $cached;
        }

        $bytes = ($this->fetcher)(sprintf(self::UPSTREAM, $zoom, $x, $y));

        if ($bytes === null || $bytes === '' || !str_starts_with($bytes, self::PNG_MAGIC)) {
            return null;
        }

        $this->writeCache($zoom, $x, $y, $bytes);

        return $bytes;
    }

    private function inRange(int $zoom, int $x, int $y): bool
    {
        if ($zoom < self::MIN_ZOOM || $zoom > self::MAX_ZOOM) {
            return false;
        }

        $tiles = 1 << $zoom;

        if ($x < 0 || $y < 0 || $x >= $tiles || $y >= $tiles) {
            return false;
        }

        [$xMin, $yMin] = self::lonLatToTile(self::LON_MIN, self::LAT_MAX, $zoom);
        [$xMax, $yMax] = self::lonLatToTile(self::LON_MAX, self::LAT_MIN, $zoom);

        return $x >= $xMin && $x <= $xMax && $y >= $yMin && $y <= $yMax;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function lonLatToTile(float $lon, float $lat, int $zoom): array
    {
        $tiles = 1 << $zoom;
        $x = (int) floor(($lon + 180.0) / 360.0 * $tiles);
        $latRad = deg2rad($lat);
        $y = (int) floor((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $tiles);

        return [max(0, min($tiles - 1, $x)), max(0, min($tiles - 1, $y))];
    }

    private function cachePath(int $zoom, int $x, int $y): string
    {
        return $this->cacheDir . '/' . $zoom . '/' . $x . '/' . $y . '.png';
    }

    private function readCache(int $zoom, int $x, int $y): ?string
    {
        $path = $this->cachePath($zoom, $x, $y);

        if (!is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    private function writeCache(int $zoom, int $x, int $y, string $bytes): void
    {
        $path = $this->cachePath($zoom, $x, $y);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temporary, $bytes, LOCK_EX) === false) {
            return;
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    /**
     * @return Closure(string): ?string
     */
    private static function defaultFetcher(): Closure
    {
        return static function (string $url): ?string {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 4.0,
                    'header' => "User-Agent: " . self::USER_AGENT . "\r\nAccept: image/png\r\n",
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $body = @file_get_contents($url, false, $context);

            if ($body === false || $body === '') {
                return null;
            }

            // Set by file_get_contents() for HTTP wrappers; `ignore_errors` means
            // an error page still comes back as a body, so check the status line.
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1 && $match[1] !== '200') {
                    return null;
                }
            }

            return $body;
        };
    }
}
