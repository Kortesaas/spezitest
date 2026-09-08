<?php

declare(strict_types=1);

namespace Spezitest\Website\Http;

use Closure;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spezitest\Admin\Image\ImageStorage;
use Spezitest\Admin\Persistence\DrinkRepository;
use Spezitest\Media\ImageResponder;
use Spezitest\Website\Catalog\CatalogPage;
use Spezitest\Website\Catalog\CatalogQuery;
use Spezitest\Website\Catalog\CatalogRepository;
use Spezitest\Website\Catalog\Geo\LocationSearch;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\HuntMap;
use Spezitest\Website\Catalog\OriginMap;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\Slug;
use Spezitest\Website\Catalog\Statistics;
use Spezitest\Website\Catalog\StreamEpisode;
use Spezitest\Website\Map\GeoJsonFeed;
use Spezitest\Website\Map\GpxDocument;
use Spezitest\Website\Map\TileProxy;
use Spezitest\Website\Seo\FeedBuilder;
use Spezitest\Website\Seo\SitemapBuilder;
use Spezitest\Website\View\WebsiteRenderer;

/**
 * The public Spezitest website: homepage, Spezi browser, detail pages, ranking,
 * statistics and the about page, plus the controlled public image route.
 *
 * All pages are read-only and require no session. The database connection is
 * created lazily so a request for a static asset or an error page never opens
 * one.
 */
final class WebsiteController
{
    private ?PDO $connection = null;

    /**
     * @param Closure(): PDO $connectionFactory
     */
    public function __construct(
        private readonly Closure $connectionFactory,
        private readonly ImageStorage $imageStorage,
        private readonly WebsiteRenderer $renderer,
        private readonly string $siteUrl,
        private readonly TileProxy $tileProxy,
    ) {
    }

    public function home(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->home($this->catalogRepository()->ratedDrinks()));
    }

    public function catalog(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = CatalogQuery::fromQueryParams($request->getQueryParams());
        $page = CatalogPage::build($this->catalogRepository()->ratedDrinks(), $query);

        return $this->html($response, $this->renderer->catalog($page));
    }

    /** @param array<string, string> $arguments */
    public function detail(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $slug = Slug::fromReference($arguments['ref'] ?? '');

        if ($slug === null) {
            return $this->notFound($response);
        }

        $collection = $this->catalogRepository()->ratedDrinks();
        $drink = $collection->find($slug->id);

        if ($drink === null) {
            return $this->notFound($response);
        }

        $canonical = $drink->slug();

        if ($slug->canonical !== $canonical) {
            return $response->withStatus(301)->withHeader('Location', '/spezi/' . $canonical);
        }

        return $this->html($response, $this->renderer->detail($drink, $collection));
    }

    public function ranking(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->ranking($this->catalogRepository()->ratedDrinks()));
    }

    public function statistik(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        $collection = $this->catalogRepository()->ratedDrinks();

        return $this->html(
            $response,
            $this->renderer->statistik(
                Statistics::fromCollection($collection),
                OriginMap::fromCollection($collection),
            ),
        );
    }

    public function streams(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->streams(
            $this->catalogRepository()->ratedDrinks(),
            $this->recordingDates(),
        ));
    }

    /** @param array<string, string> $arguments */
    public function stream(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $number = $arguments['number'] ?? '';
        $page = ctype_digit($number)
            ? $this->renderer->stream(
                (int) $number,
                $this->catalogRepository()->ratedDrinks(),
                $this->recordingDates(),
            )
            : null;

        if ($page === null) {
            return $this->html($response, $this->renderer->notFound(), 404);
        }

        return $this->html($response, $page);
    }

    /**
     * The recording date of each Spezistream, keyed by episode number. Kept out
     * of the catalog query because it belongs to the episode, not the test.
     *
     * @return array<int, ?string>
     */
    private function recordingDates(): array
    {
        $statement = $this->connection()->query('SELECT number, recorded_on FROM test_runs');

        if ($statement === false) {
            return [];
        }

        $dates = [];

        foreach ($statement as $row) {
            if (!is_array($row)) {
                continue;
            }

            $number = $row['number'] ?? null;
            $date = $row['recorded_on'] ?? null;

            if (is_int($number) || is_string($number)) {
                $dates[(int) $number] = is_string($date) ? $date : null;
            }
        }

        return $dates;
    }

    public function karte(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->karte($this->huntMap()));
    }

    /**
     * The live public map feed, as GeoJSON, for the uMap map viewer. It is the
     * same set of pins as {@see karte()} — `identified` drinks whose origin
     * resolves to a coordinate — rebuilt from the database on every request and
     * cached briefly. See {@see GeoJsonFeed}.
     */
    public function mapSpezisGeoJson(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        $collection = GeoJsonFeed::fromHuntMap($this->huntMap(), $this->siteUrl)->toFeatureCollection();

        return $this->geoJson($response, $collection, 'public, max-age=60');
    }

    /**
     * A fixed three-pin GeoJSON file, used once to confirm that uMap can load
     * remote GeoJSON from this origin before the live feed is wired in.
     */
    public function mapTestGeoJson(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        $collection = [
            'type' => 'FeatureCollection',
            'features' => [
                self::testFeature('test-1', 'TEST Spezi Berlin', 13.4050, 52.5200),
                self::testFeature('test-2', 'TEST Spezi München', 11.5820, 48.1351),
                self::testFeature('test-3', 'TEST Spezi Hamburg', 9.9937, 53.5511),
            ],
        ];

        return $this->geoJson($response, $collection, 'public, max-age=300');
    }

    /**
     * @return array{type: 'Feature', id: string, properties: array{name: string}, geometry: array{type: 'Point', coordinates: array{0: float, 1: float}}}
     */
    private static function testFeature(string $id, string $name, float $longitude, float $latitude): array
    {
        return [
            'type' => 'Feature',
            'id' => $id,
            'properties' => ['name' => $name],
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function geoJson(ResponseInterface $response, array $document, string $cacheControl): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $response
            ->withHeader('Content-Type', 'application/geo+json; charset=UTF-8')
            ->withHeader('Cache-Control', $cacheControl);
    }

    /**
     * The whole hunt map as a GPX waypoint file — one `<wpt>` per place, each
     * carrying the Spezi name, so a phone's "open with" sheet can hand it to any
     * map or GPS app. Read-only, built from the same data as the page.
     */
    public function karteGpx(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        $waypoints = $this->huntMap()->waypoints();

        if ($waypoints === []) {
            return $response->withStatus(404);
        }

        return $this->gpx($response, 'spezitest-spezikarte', $waypoints);
    }

    /**
     * Resolves a "PLZ oder Ort" search box entry to a coordinate, so the map
     * can jump there and sort the list by distance. Offline and first-party —
     * see {@see LocationSearch}. Read-only JSON, like the catalog suggestions.
     */
    public function karteSearch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $term = $request->getQueryParams()['q'] ?? '';
        $term = is_string($term) ? mb_substr($term, 0, 120) : '';

        $hit = LocationSearch::default()->search($term, $this->huntMap());

        $status = $hit === null ? 404 : 200;
        $payload = $hit === null
            ? ['error' => 'not_found']
            : ['lat' => $hit['latitude'], 'lon' => $hit['longitude'], 'label' => $hit['label']];

        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Type-ahead for the map search box: place names that start with what the
     * visitor has typed. Read-only JSON, matched offline against the same data
     * {@see karteSearch()} resolves.
     */
    public function karteSuggest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $term = $request->getQueryParams()['q'] ?? '';
        $term = is_string($term) ? mb_substr($term, 0, 120) : '';

        $items = LocationSearch::default()->suggest($term, $this->huntMap());

        $response->getBody()->write((string) json_encode(
            ['items' => $items],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * One place from the hunt map as a GPX file.
     *
     * @param array<string, string> $arguments
     */
    public function karteGpxForPlace(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $postalCode = $arguments['plz'] ?? '';

        if (preg_match('/^\d{5}$/', $postalCode) !== 1) {
            return $response->withStatus(404);
        }

        $map = $this->huntMap();
        $waypoints = $map->waypointsForPostalCode($postalCode);

        if ($waypoints === []) {
            return $response->withStatus(404);
        }

        return $this->gpx($response, GpxDocument::filename($waypoints[0]['name']), $waypoints);
    }

    /**
     * @param list<array{latitude: float, longitude: float, name: string, description: ?string}> $waypoints
     */
    private function gpx(ResponseInterface $response, string $filenameStem, array $waypoints): ResponseInterface
    {
        $response->getBody()->write((new GpxDocument($waypoints))->render());

        return $response
            ->withHeader('Content-Type', 'application/gpx+xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameStem . '.gpx"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function huntMap(): HuntMap
    {
        return HuntMap::fromCollection(
            $this->catalogRepository()->ratedDrinks(),
            PostalGeocoder::default(),
        );
    }

    /**
     * First-party OpenStreetMap tiles for the hunt map. The visitor's browser
     * only ever talks to this origin; {@see TileProxy} fetches and caches each
     * tile server-side. See its class docblock.
     *
     * @param array<string, string> $arguments
     */
    public function mapTile(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        foreach (['z', 'x', 'y'] as $key) {
            if (!ctype_digit($arguments[$key] ?? '')) {
                return $response->withStatus(404);
            }
        }

        $tile = $this->tileProxy->tile(
            (int) $arguments['z'],
            (int) $arguments['x'],
            (int) $arguments['y'],
        );

        if ($tile === null) {
            return $response->withStatus(404);
        }

        $response->getBody()->write($tile);

        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=1209600');
    }

    public function ueber(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->ueber($this->catalogRepository()->ratedDrinks()));
    }

    public function impressum(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->impressum());
    }

    public function datenschutz(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->datenschutz());
    }

    /**
     * `/sitemap.xml`: every public page plus one entry per Spezi and per
     * Spezistream, with `lastmod` from each record. Referenced from robots.txt.
     */
    public function sitemap(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        $collection = $this->catalogRepository()->ratedDrinks();
        $xml = (new SitemapBuilder($this->siteUrl))->build($collection, $this->streamEpisodes($collection));

        return $this->xml($response, $xml);
    }

    /**
     * `/feed.xml`: an Atom feed of the most recently tested Spezis, so the
     * verdicts can be followed in a reader.
     */
    public function feed(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        $xml = (new FeedBuilder($this->siteUrl))->build($this->catalogRepository()->ratedDrinks());

        return $this->xml($response, $xml);
    }

    /**
     * The Spezistreams as the streams pages see them, newest first, each carrying
     * its recording date.
     *
     * @return list<StreamEpisode>
     */
    private function streamEpisodes(RatedDrinkCollection $collection): array
    {
        $recordedOn = $this->recordingDates();

        return array_map(
            static fn (StreamEpisode $episode): StreamEpisode => $episode->withRecordedOn(
                $recordedOn[$episode->number] ?? null,
            ),
            StreamEpisode::fromCollection($collection),
        );
    }

    /**
     * Type-ahead for the catalog search box. Read-only JSON, matched against
     * the same fields as the catalog itself so a suggestion always yields
     * results when it is submitted. This deliberately covers the entire
     * catalogue: identified and acquired drinks are just as searchable as
     * completed tests.
     */
    public function suggestions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $term = $request->getQueryParams()['q'] ?? '';
        $term = is_string($term) ? trim(mb_substr($term, 0, 120)) : '';
        $matches = [];

        if (mb_strlen($term) >= 2) {
            $needle = mb_strtolower($term);

            foreach ($this->catalogRepository()->ratedDrinks()->all() as $drink) {
                $haystack = mb_strtolower(
                    $drink->name . ' ' . ($drink->manufacturer ?? '') . ' ' . ($drink->displayOrigin() ?? ''),
                );

                if (!str_contains($haystack, $needle)) {
                    continue;
                }

                $matches[] = [
                    'id' => $drink->id,
                    'name' => $drink->name,
                    'sub' => $drink->manufacturer ?? $drink->displayOrigin() ?? '',
                    'slug' => $drink->slug(),
                    'image' => $drink->hasImage ? '/spezi/' . $drink->id . '/bild' : null,
                    'rank' => $drink->rank,
                ];

                if (count($matches) === 8) {
                    break;
                }
            }
        }

        $response->getBody()->write((string) json_encode(
            ['items' => $matches],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $arguments */
    public function image(
        ServerRequestInterface $_request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $id = $arguments['id'] ?? '';

        if (!ctype_digit($id) || (int) $id < 1) {
            return $response->withStatus(404);
        }

        $image = (new DrinkRepository($this->connection()))->primaryImage((int) $id);

        if ($image === null) {
            return $response->withStatus(404);
        }

        return (new ImageResponder($this->imageStorage))->respond(
            $response,
            $image['storage_path'],
            $image['mime_type'],
            'public, max-age=86400',
        );
    }

    public function notFoundHandler(
        ServerRequestInterface $_request,
        ResponseInterface $response,
    ): ResponseInterface {
        return $this->notFound($response);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->notFound(), 404);
    }

    private function catalogRepository(): CatalogRepository
    {
        return new CatalogRepository($this->connection());
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }

    private function html(ResponseInterface $response, string $html, int $status = 200): ResponseInterface
    {
        $response->getBody()->write($html);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    private function xml(ResponseInterface $response, string $xml): ResponseInterface
    {
        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
