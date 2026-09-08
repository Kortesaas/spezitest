<?php

declare(strict_types=1);

namespace Spezitest\Application;

use Closure;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Routing\RouteCollectorProxy;
use Spezitest\Admin\Http\AdminAuthenticationMiddleware;
use Spezitest\Admin\Http\AdminController;
use Spezitest\Admin\Http\AdminSecurityHeadersMiddleware;
use Spezitest\Admin\Http\CsrfMiddleware;
use Spezitest\Admin\Http\HtmlRenderer;
use Spezitest\Admin\Image\ImageStorage;
use Spezitest\Admin\Security\AdminAuthenticator;
use Spezitest\Admin\Security\CsrfTokenManager;
use Spezitest\Configuration\AppConfiguration;
use Spezitest\Development\LiveReloadMiddleware;
use Spezitest\Website\Http\PublicMapApiMiddleware;
use Spezitest\Website\Http\WebsiteController;
use Spezitest\Website\Http\WebsiteSecurityHeadersMiddleware;
use Spezitest\Website\Map\TileProxy;
use Spezitest\Website\View\WebsiteRenderer;

final class AppFactory
{
    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(
        AppConfiguration $configuration,
        ?LoggerInterface $logger = null,
        ?AdminRuntime $adminRuntime = null,
    ): App {
        $app = SlimAppFactory::create();
        $root = dirname(__DIR__, 2);
        $adminRuntime ??= AdminRuntime::fromEnvironment(
            $configuration,
            $root,
        );
        $adminConfiguration = $adminRuntime->configuration();
        $authenticator = new AdminAuthenticator($adminConfiguration, $adminRuntime->session());
        $csrfTokens = new CsrfTokenManager($adminRuntime->session());
        $adminController = new AdminController(
            $adminRuntime,
            $authenticator,
            $csrfTokens,
            new HtmlRenderer(),
        );

        $imageStorage = new ImageStorage(
            $adminConfiguration->imageStorageRoot(),
            $adminConfiguration->legacyImageStorageRoot(),
        );
        /** @var Closure(): PDO $connectionFactory */
        $connectionFactory = static fn (): PDO => $adminRuntime->connection();
        $siteUrl = $configuration->siteUrl();
        $websiteRenderer = new WebsiteRenderer($siteUrl);
        $websiteController = new WebsiteController(
            $connectionFactory,
            $imageStorage,
            $websiteRenderer,
            $siteUrl,
            new TileProxy($root . '/var/tile-cache'),
        );

        $responseFactory = $app->getResponseFactory();
        $csrfMiddleware = new CsrfMiddleware($csrfTokens, $responseFactory);
        $adminSecurityHeaders = new AdminSecurityHeadersMiddleware();
        $websiteSecurityHeaders = new WebsiteSecurityHeadersMiddleware();

        self::registerWebsiteRoutes($app, $websiteController, $websiteSecurityHeaders);
        self::registerMapApiRoutes($app, $websiteController);
        self::registerAdminRoutes(
            $app,
            $adminController,
            $authenticator,
            $csrfMiddleware,
            $adminSecurityHeaders,
            $responseFactory,
        );

        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware($configuration->debug(), true, true, $logger);
        $errorMiddleware->setErrorHandler(
            HttpNotFoundException::class,
            static function (
                ServerRequestInterface $request,
            ) use ($websiteController, $responseFactory): ResponseInterface {
                return WebsiteSecurityHeadersMiddleware::apply(
                    $websiteController->notFoundHandler($request, $responseFactory->createResponse()),
                );
            },
        );

        // In production every other failure renders the branded 500 page rather
        // than Slim's bare text. In debug the framework's detailed handler is
        // kept so a developer still sees the stack trace.
        if (!$configuration->debug()) {
            $errorMiddleware->setDefaultErrorHandler(
                static function () use ($websiteRenderer, $responseFactory): ResponseInterface {
                    $response = $responseFactory->createResponse(500);
                    $response->getBody()->write($websiteRenderer->serverError());

                    return WebsiteSecurityHeadersMiddleware::apply(
                        $response->withHeader('Content-Type', 'text/html; charset=UTF-8'),
                    );
                },
            );
        }

        // Local-development live reload. Never active in production: it requires
        // a non-production environment AND APP_DEBUG=true. Added last so it is
        // the outermost middleware and can patch every HTML response, 404s
        // included.
        if ($configuration->environment() !== 'production' && $configuration->debug()) {
            $app->add(new LiveReloadMiddleware([
                $root . '/src',
                $root . '/config',
                $root . '/database/migrations',
                $root . '/public/index.php',
                $root . '/public/assets',
                $root . '/public/.htaccess',
            ]));
        }

        return $app;
    }

    /**
     * @param App<ContainerInterface|null> $app
     */
    private static function registerWebsiteRoutes(
        App $app,
        WebsiteController $controller,
        WebsiteSecurityHeadersMiddleware $securityHeaders,
    ): void {
        $app->group('', static function (RouteCollectorProxy $group) use ($controller): void {
            $group->get('/', [$controller, 'home']);
            $group->get('/spezis', [$controller, 'catalog']);
            $group->get('/spezis/vorschlaege', [$controller, 'suggestions']);
            $group->get('/karte', [$controller, 'karte']);
            $group->get('/karte/suche', [$controller, 'karteSearch']);
            $group->get('/karte/vorschlaege', [$controller, 'karteSuggest']);
            $group->get('/karte/spezikarte.gpx', [$controller, 'karteGpx']);
            $group->get('/karte/ort/{key:[0-9]{5}|[a-z]{2}-[0-9]{4}}.gpx', [$controller, 'karteGpxForPlace']);
            $group->get('/karte/kachel/{z:[0-9]+}/{x:[0-9]+}/{y:[0-9]+}.png', [$controller, 'mapTile']);
            $group->get('/karte/spezi/{id:[0-9]+}', [$controller, 'karteSpezi']);
            $group->get('/karte/{scope:getestet|gesucht}', [$controller, 'karte']);
            $group->get('/impressum', [$controller, 'impressum']);
            $group->get('/datenschutz', [$controller, 'datenschutz']);
            $group->get('/ranking', [$controller, 'ranking']);
            $group->get('/statistik', [$controller, 'statistik']);
            $group->get('/streams', [$controller, 'streams']);
            $group->get('/streams/{number:[1-9][0-9]*}', [$controller, 'stream']);
            $group->get('/ueber', [$controller, 'ueber']);
            $group->get('/sitemap.xml', [$controller, 'sitemap']);
            $group->get('/feed.xml', [$controller, 'feed']);
            $group->get('/spezi/{id:[0-9]+}/bild', [$controller, 'image']);
            $group->get('/spezi/{ref:[0-9][A-Za-z0-9-]*}', [$controller, 'detail']);
        })->add($securityHeaders);
    }

    /**
     * The public, unauthenticated map API. It is read cross-origin by a
     * third-party map viewer (uMap), so it is served outside the website's
     * page-oriented security headers with `Access-Control-Allow-Origin: *`.
     *
     * @param App<ContainerInterface|null> $app
     */
    private static function registerMapApiRoutes(App $app, WebsiteController $controller): void
    {
        $app->group('/api/map', static function (RouteCollectorProxy $group) use ($controller): void {
            $group->get('/spezis.geojson', [$controller, 'mapSpezisGeoJson']);
            $group->get('/test-spezis.geojson', [$controller, 'mapTestGeoJson']);
        })->add(new PublicMapApiMiddleware());
    }

    /**
     * @param App<ContainerInterface|null> $app
     */
    private static function registerAdminRoutes(
        App $app,
        AdminController $adminController,
        AdminAuthenticator $authenticator,
        CsrfMiddleware $csrfMiddleware,
        AdminSecurityHeadersMiddleware $securityHeaders,
        \Psr\Http\Message\ResponseFactoryInterface $responseFactory,
    ): void {
        $app->get('/admin/login', [$adminController, 'loginForm'])->add($securityHeaders);
        $app->post('/admin/login', [$adminController, 'login'])
            ->add($csrfMiddleware)
            ->add($securityHeaders);

        $app->group(
            '/admin',
            static function (RouteCollectorProxy $group) use ($adminController, $csrfMiddleware): void {
                $group->get('', [$adminController, 'dashboard']);
                $group->post('/logout', [$adminController, 'logout'])->add($csrfMiddleware);
                $group->get('/test', [$adminController, 'testQueue']);
                $group->get('/testabende', [$adminController, 'testRuns']);
                $group->post('/testabende', [$adminController, 'startTestRun'])->add($csrfMiddleware);
                $group->get('/testabende/{number:[1-9][0-9]*}', [$adminController, 'testRun']);
                $group->post('/testabende/{number:[1-9][0-9]*}', [$adminController, 'updateTestRun'])->add($csrfMiddleware);
                $group->post('/testabende/{number:[1-9][0-9]*}/complete', [$adminController, 'completeTestRun'])->add($csrfMiddleware);
                $group->get('/drinks', [$adminController, 'drinks']);
                $group->get('/drinks/new', [$adminController, 'createForm']);
                $group->post('/drinks', [$adminController, 'create'])->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/edit', [$adminController, 'editForm']);
                $group->post('/drinks/{id:[1-9][0-9]*}', [$adminController, 'update'])->add($csrfMiddleware);
                $group->post('/drinks/{id:[1-9][0-9]*}/status', [$adminController, 'changeStatus'])->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/delete', [$adminController, 'deleteConfirmation']);
                $group->post('/drinks/{id:[1-9][0-9]*}/delete', [$adminController, 'delete'])->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/image', [$adminController, 'image']);
                $group->get('/drinks/{id:[1-9][0-9]*}/test', [$adminController, 'testForm']);
                $group->post('/drinks/{id:[1-9][0-9]*}/test', [$adminController, 'saveTestDraft'])->add($csrfMiddleware);
                $group->post('/drinks/{id:[1-9][0-9]*}/test/complete', [$adminController, 'completeTest'])->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/test/result', [$adminController, 'testResult']);
            },
        )
            ->add(new AdminAuthenticationMiddleware($authenticator, $responseFactory))
            ->add($securityHeaders);
    }
}
