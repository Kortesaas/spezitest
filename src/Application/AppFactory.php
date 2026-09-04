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
use Spezitest\Website\Http\WebsiteController;
use Spezitest\Website\Http\WebsiteSecurityHeadersMiddleware;
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
        $adminRuntime ??= AdminRuntime::fromEnvironment(
            $configuration,
            dirname(__DIR__, 2),
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
        $websiteController = new WebsiteController(
            $connectionFactory,
            $imageStorage,
            new WebsiteRenderer(),
        );

        $responseFactory = $app->getResponseFactory();
        $csrfMiddleware = new CsrfMiddleware($csrfTokens, $responseFactory);
        $adminSecurityHeaders = new AdminSecurityHeadersMiddleware();
        $websiteSecurityHeaders = new WebsiteSecurityHeadersMiddleware();

        self::registerWebsiteRoutes($app, $websiteController, $websiteSecurityHeaders);
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
            $group->get('/ranking', [$controller, 'ranking']);
            $group->get('/statistik', [$controller, 'statistik']);
            $group->get('/ueber', [$controller, 'ueber']);
            $group->get('/spezi/{id:[0-9]+}/bild', [$controller, 'image']);
            $group->get('/spezi/{ref:[0-9][A-Za-z0-9-]*}', [$controller, 'detail']);
        })->add($securityHeaders);
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
            },
        )
            ->add(new AdminAuthenticationMiddleware($authenticator, $responseFactory))
            ->add($securityHeaders);
    }
}
