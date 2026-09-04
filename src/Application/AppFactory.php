<?php

declare(strict_types=1);

namespace Spezitest\Application;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Routing\RouteCollectorProxy;
use Spezitest\Admin\Http\AdminAuthenticationMiddleware;
use Spezitest\Admin\Http\AdminController;
use Spezitest\Admin\Http\AdminSecurityHeadersMiddleware;
use Spezitest\Admin\Http\CsrfMiddleware;
use Spezitest\Admin\Http\HtmlRenderer;
use Spezitest\Admin\Security\AdminAuthenticator;
use Spezitest\Admin\Security\CsrfTokenManager;
use Spezitest\Configuration\AppConfiguration;

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
        $authenticator = new AdminAuthenticator(
            $adminRuntime->configuration(),
            $adminRuntime->session(),
        );
        $csrfTokens = new CsrfTokenManager($adminRuntime->session());
        $adminController = new AdminController(
            $adminRuntime,
            $authenticator,
            $csrfTokens,
            new HtmlRenderer(),
        );
        $responseFactory = $app->getResponseFactory();
        $csrfMiddleware = new CsrfMiddleware($csrfTokens, $responseFactory);
        $securityHeaders = new AdminSecurityHeadersMiddleware();

        $app->get(
            '/',
            static function (
                ServerRequestInterface $_request,
                ResponseInterface $response,
            ): ResponseInterface {
                $response->getBody()->write(
                    <<<'HTML'
                    <!doctype html>
                    <html lang="de">
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <title>Spezitest</title>
                    </head>
                    <body>
                        <main>
                            <h1>Spezitest</h1>
                            <p>Die neue Spezitest-Anwendung befindet sich im Aufbau.</p>
                        </main>
                    </body>
                    </html>
                    HTML,
                );

                return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
            },
        );

        $app->get('/admin/login', [$adminController, 'loginForm'])
            ->add($securityHeaders);
        $app->post('/admin/login', [$adminController, 'login'])
            ->add($csrfMiddleware)
            ->add($securityHeaders);

        $app->group(
            '/admin',
            static function (RouteCollectorProxy $group) use (
                $adminController,
                $csrfMiddleware,
            ): void {
                $group->get('', [$adminController, 'dashboard']);
                $group->post('/logout', [$adminController, 'logout'])
                    ->add($csrfMiddleware);
                $group->get('/drinks', [$adminController, 'drinks']);
                $group->get('/drinks/new', [$adminController, 'createForm']);
                $group->post('/drinks', [$adminController, 'create'])
                    ->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/edit', [$adminController, 'editForm']);
                $group->post('/drinks/{id:[1-9][0-9]*}', [$adminController, 'update'])
                    ->add($csrfMiddleware);
                $group->post('/drinks/{id:[1-9][0-9]*}/status', [$adminController, 'changeStatus'])
                    ->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/delete', [$adminController, 'deleteConfirmation']);
                $group->post('/drinks/{id:[1-9][0-9]*}/delete', [$adminController, 'delete'])
                    ->add($csrfMiddleware);
                $group->get('/drinks/{id:[1-9][0-9]*}/image', [$adminController, 'image']);
            },
        )
            ->add(new AdminAuthenticationMiddleware($authenticator, $responseFactory))
            ->add($securityHeaders);

        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(
            $configuration->debug(),
            true,
            true,
            $logger,
        );

        return $app;
    }
}
