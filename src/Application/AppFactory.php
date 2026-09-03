<?php

declare(strict_types=1);

namespace Spezitest\Application;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Spezitest\Configuration\AppConfiguration;

final class AppFactory
{
    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(
        AppConfiguration $configuration,
        ?LoggerInterface $logger = null,
    ): App {
        $app = SlimAppFactory::create();

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
