<?php

declare(strict_types=1);

use Slim\App;

error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    /** @var App<\Psr\Container\ContainerInterface|null> $app */
    $app = require dirname(__DIR__) . '/config/bootstrap.php';
    $app->run();
} catch (\Throwable $exception) {
    error_log((string) $exception);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo 'Internal Server Error';
}
