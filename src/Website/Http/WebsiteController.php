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
use Spezitest\Website\Catalog\OriginMap;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\Slug;
use Spezitest\Website\Catalog\Statistics;
use Spezitest\Website\Catalog\StreamEpisode;
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
        private readonly string $siteUrl = 'https://www.spezitest.de',
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
     * results when it is submitted.
     */
    public function suggestions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $term = $request->getQueryParams()['q'] ?? '';
        $term = is_string($term) ? trim(mb_substr($term, 0, 120)) : '';
        $matches = [];

        if (mb_strlen($term) >= 2) {
            $needle = mb_strtolower($term);

            foreach ($this->catalogRepository()->ratedDrinks()->ranked() as $drink) {
                $haystack = mb_strtolower(
                    $drink->name . ' ' . ($drink->manufacturer ?? '') . ' ' . ($drink->displayOrigin() ?? ''),
                );

                if (!str_contains($haystack, $needle)) {
                    continue;
                }

                $matches[] = [
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
