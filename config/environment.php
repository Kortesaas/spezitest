<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$rootDirectory = dirname(__DIR__);

require_once $rootDirectory . '/vendor/autoload.php';

// The deployed .env is the authoritative per-site configuration on Plesk.
// Shared-hosting scheduler jobs can inherit unrelated DB_* variables from the
// subscription environment; allowing the app-root .env to override those
// values keeps migrations and the web application on the same database.
Dotenv::createMutable($rootDirectory)->safeLoad();

return $rootDirectory;
