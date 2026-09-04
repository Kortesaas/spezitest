<?php

declare(strict_types=1);

namespace Spezitest\Tests\Support;

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Database\DatabaseConfigurationException;

use function str_ends_with;

/**
 * Guard for database integration tests.
 *
 * Refuses to run unless the process is explicitly in the testing environment
 * and the configured database is a disposable one (its name must end with
 * `_test`). This makes it impossible for the destructive integration suite to
 * accidentally touch a development or production database.
 */
trait InteractsWithTestDatabase
{
    private function testDatabaseConfiguration(): DatabaseConfiguration
    {
        $environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

        if ($environment !== 'testing') {
            self::fail(
                'Integration tests require APP_ENV=testing. Copy .env.testing.example to '
                . '.env.testing (git-ignored) or export the testing DB_* variables. '
                . 'Refusing to continue against a non-testing environment.',
            );
        }

        try {
            $configuration = DatabaseConfiguration::fromEnvironment();
        } catch (DatabaseConfigurationException $exception) {
            self::markTestSkipped('No test database configured: ' . $exception->getMessage());
        }

        $name = $configuration->databaseName();

        if (!str_ends_with($name, '_test')) {
            self::fail(sprintf(
                'Refusing to run destructive integration tests against database "%s": '
                . 'the integration-test database name must end with "_test".',
                $name,
            ));
        }

        return $configuration;
    }

    private function connectToTestDatabase(): \PDO
    {
        return (new ConnectionFactory($this->testDatabaseConfiguration()))->create();
    }
}
