<?php

declare(strict_types=1);

use Slim\App;
use Spezitest\Application\AdminRuntime;
use Spezitest\Application\AppFactory;
use Spezitest\Configuration\AppConfiguration;

/** @var non-empty-string $rootDirectory */
$rootDirectory = require __DIR__ . '/environment.php';
$configuration = AppConfiguration::fromEnvironment();

/** @var App<\Psr\Container\ContainerInterface|null> $app */
$app = AppFactory::create(
    $configuration,
    null,
    AdminRuntime::fromEnvironment($configuration, $rootDirectory),
);

return $app;
