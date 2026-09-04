<?php

declare(strict_types=1);

namespace Spezitest\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Database\Migration\MigrationException;
use Spezitest\Database\Migration\Migrator;

final class DatabaseInfrastructureTest extends TestCase
{
    private PDO $connection;

    private string $migrationDirectory;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(DatabaseConfiguration::fromEnvironment()))->create();
        $this->dropTestTables();

        $this->migrationDirectory = sys_get_temp_dir()
            . '/spezitest-migrations-'
            . bin2hex(random_bytes(12));

        if (!mkdir($this->migrationDirectory, 0700)) {
            self::fail('Could not create the temporary migration directory.');
        }
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();

        $paths = glob($this->migrationDirectory . '/*');

        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        rmdir($this->migrationDirectory);
    }

    public function testConnectionUsesMariaDbAndRequiredPdoOptions(): void
    {
        $statement = $this->connection->query('SELECT VERSION()');

        if ($statement === false) {
            self::fail('Could not query the MariaDB version.');
        }

        $version = $statement->fetchColumn();

        self::assertIsString($version);
        self::assertStringStartsWith('10.11.', $version);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $this->connection->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $this->connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertFalse((bool) $this->connection->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function testMigratorCreatesTrackingTable(): void
    {
        $applied = $this->migrator()->migrate();

        self::assertSame([], $applied);
        self::assertTrue($this->tableExists('schema_migrations'));
    }

    public function testMigrationCanBeAppliedAndRecorded(): void
    {
        $version = $this->writeMigration(
            '20260101000000_create_migration_test_applied.sql',
            <<<'SQL'
                CREATE TABLE migration_test_applied (
                    id INT NOT NULL PRIMARY KEY
                ) ENGINE=InnoDB
                SQL,
        );

        self::assertSame([$version], $this->migrator()->migrate());
        self::assertTrue($this->tableExists('migration_test_applied'));
        self::assertSame(1, $this->trackingCount($version));
    }

    public function testSecondRunDoesNotReapplyMigration(): void
    {
        $version = $this->writeMigration(
            '20260101000001_create_migration_test_repeat.sql',
            <<<'SQL'
                CREATE TABLE migration_test_repeat (
                    id INT NOT NULL PRIMARY KEY
                ) ENGINE=InnoDB
                SQL,
        );
        $migrator = $this->migrator();

        self::assertSame([$version], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        self::assertSame(1, $this->trackingCount($version));
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $version = $this->writeMigration(
            '20260101000002_deliberately_fail.sql',
            'THIS IS DELIBERATELY INVALID SQL',
        );

        try {
            $this->migrator()->migrate();
            self::fail('The deliberately invalid migration unexpectedly succeeded.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString($version, $exception->getMessage());
        }

        self::assertSame(0, $this->trackingCount($version));
    }

    public function testMigrationsAreAppliedInDeterministicFilenameOrder(): void
    {
        $secondVersion = $this->writeMigration(
            '20260101000004_insert_order_test.sql',
            'INSERT INTO migration_order_test (step) VALUES (2)',
        );
        $firstVersion = $this->writeMigration(
            '20260101000003_create_order_test.sql',
            <<<'SQL'
                CREATE TABLE migration_order_test (
                    step INT NOT NULL
                ) ENGINE=InnoDB
                SQL,
        );

        self::assertSame(
            [$firstVersion, $secondVersion],
            $this->migrator()->migrate(),
        );

        $statement = $this->connection->query('SELECT step FROM migration_order_test');

        if ($statement === false) {
            self::fail('Could not query the migration ordering result.');
        }

        $step = $statement->fetchColumn();
        self::assertSame(2, (int) $step);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->connection, $this->migrationDirectory);
    }

    private function writeMigration(string $filename, string $sql): string
    {
        $bytesWritten = file_put_contents($this->migrationDirectory . '/' . $filename, $sql . "\n");

        if ($bytesWritten === false) {
            self::fail('Could not write a temporary migration file.');
        }

        return substr($filename, 0, -4);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                SQL,
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function trackingCount(string $version): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE version = :version',
        );
        $statement->execute(['version' => $version]);

        return (int) $statement->fetchColumn();
    }

    private function dropTestTables(): void
    {
        $this->connection->exec(
            <<<'SQL'
                DROP TABLE IF EXISTS
                    ratings,
                    drink_images,
                    drink_tests,
                    legacy_import_runs,
                    testers,
                    drinks,
                    migration_test_applied,
                    migration_test_repeat,
                    migration_order_test,
                    schema_migrations
                SQL,
        );
    }
}
