#!/usr/bin/env php
<?php

declare(strict_types=1);

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Database\DatabaseConfigurationException;
use Spezitest\Database\DatabaseConnectionException;
use Spezitest\Database\Migration\MigrationException;
use Spezitest\Database\Migration\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    /** @var non-empty-string $rootDirectory */
    $rootDirectory = require dirname(__DIR__) . '/config/environment.php';

    $configuration = DatabaseConfiguration::fromEnvironment();
    $connection = (new ConnectionFactory($configuration))->create();
    $migrator = new Migrator($connection, $rootDirectory . '/database/migrations');
    $appliedMigrations = $migrator->migrate();

    if ($appliedMigrations === []) {
        fwrite(STDOUT, "No pending migrations.\n");
        exit(0);
    }

    foreach ($appliedMigrations as $version) {
        fwrite(STDOUT, 'Applied migration: ' . $version . "\n");
    }

    fwrite(STDOUT, sprintf("Applied %d migration(s).\n", count($appliedMigrations)));
} catch (DatabaseConfigurationException | DatabaseConnectionException | MigrationException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Migration command failed without exposing internal details.\n");
    exit(1);
}
