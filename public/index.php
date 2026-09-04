<?php

declare(strict_types=1);

use Slim\App;

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Local development only: when running under `php -S` (composer serve), let the
// built-in server deliver existing static assets in public/ directly. Production
// serves those through Apache and never reaches this branch.
if (PHP_SAPI === 'cli-server') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : null;

    if (is_string($path) && $path !== '/' && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
        return false;
    }
}

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
