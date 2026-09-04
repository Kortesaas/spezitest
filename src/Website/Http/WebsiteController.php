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
use Spezitest\Website\Catalog\Slug;
use Spezitest\Website\Catalog\Statistics;
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
        return $this->html(
            $response,
            $this->renderer->statistik(Statistics::fromCollection($this->catalogRepository()->ratedDrinks())),
        );
    }

    public function ueber(ServerRequestInterface $_request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($response, $this->renderer->ueber($this->catalogRepository()->ratedDrinks()));
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
}
