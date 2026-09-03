<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Slim\App;
use Spezitest\Application\AppFactory;
use Spezitest\Configuration\AppConfiguration;

$rootDirectory = dirname(__DIR__);

require $rootDirectory . '/vendor/autoload.php';

Dotenv::createImmutable($rootDirectory)->safeLoad();

/** @var App<\Psr\Container\ContainerInterface|null> $app */
$app = AppFactory::create(AppConfiguration::fromEnvironment());

return $app;
