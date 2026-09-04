<?php

declare(strict_types=1);

namespace Spezitest\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Database\Migration\Migrator;

final class DomainSchemaMigrationTest extends TestCase
{
    private PDO $connection;

    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(DatabaseConfiguration::fromEnvironment()))->create();
        $this->dropAllTables();
        $this->migrator = new Migrator(
            $this->connection,
            dirname(__DIR__, 2) . '/database/migrations',
        );
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
    }

    public function testDomainMigrationsApplyToEmptyDatabaseAndSecondRunIsNoOp(): void
    {
        self::assertSame([
            '20260904000000_create_domain_schema',
            '20260904000100_seed_canonical_testers',
            '20260904000200_prepare_legacy_import',
        ], $this->migrator->migrate());
        self::assertSame([], $this->migrator->migrate());

        self::assertSame([
            'drink_images',
            'drink_tests',
            'drinks',
            'legacy_import_runs',
            'ratings',
            'schema_migrations',
            'testers',
        ], $this->tableNames());

        $statement = $this->connection->query(
            'SELECT code, display_name, display_order FROM testers ORDER BY display_order',
        );
        self::assertNotFalse($statement);
        /** @var list<array{code: string, display_name: string, display_order: int|string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            ['code' => 'manu', 'display_name' => 'Manu', 'display_order' => 1],
            ['code' => 'fabi', 'display_name' => 'Fabi', 'display_order' => 2],
            ['code' => 'schorsch', 'display_name' => 'Schorsch', 'display_order' => 3],
        ], array_map(
            static fn (array $row): array => [
                'code' => $row['code'],
                'display_name' => $row['display_name'],
                'display_order' => (int) $row['display_order'],
            ],
            $rows,
        ));
    }

    public function testInvalidLifecycleStatusIsRejectedAndNamesAreNotUnique(): void
    {
        $this->migrator->migrate();
        $this->insertDrink('Same name', 'identified');
        $this->insertDrink('Same name', 'acquired');

        $statement = $this->connection->query("SELECT COUNT(*) FROM drinks WHERE name = 'Same name'");
        self::assertNotFalse($statement);
        self::assertSame(2, (int) $statement->fetchColumn());

        $this->expectException(PDOException::class);
        $this->insertDrink('Invalid lifecycle', 'unknown');
    }

    public function testForeignKeysRejectUnknownParents(): void
    {
        $this->migrator->migrate();
        $statement = $this->connection->prepare(
            "INSERT INTO drink_tests (drink_id, status) VALUES (:drink_id, 'draft')",
        );

        $this->expectException(PDOException::class);
        $statement->execute(['drink_id' => 999999]);
    }

    public function testDuplicateRatingForSameTestAndTesterIsRejected(): void
    {
        $this->migrator->migrate();
        $drinkId = $this->insertDrink('Constraint test', 'acquired');
        $testId = $this->insertTest($drinkId);
        $testerId = $this->testerId('manu');
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO ratings (test_id, tester_id, optik, sueffigkeit, geschmack)
                VALUES (:test_id, :tester_id, 1.2500, 2.5000, 3.7500)
                SQL,
        );
        $statement->execute(['test_id' => $testId, 'tester_id' => $testerId]);

        $this->expectException(PDOException::class);
        $statement->execute(['test_id' => $testId, 'tester_id' => $testerId]);
    }

    public function testSchemaStoresRawFactsWithExpectedDecimalPrecision(): void
    {
        $this->migrator->migrate();

        self::assertSame(
            ['data_type' => 'decimal', 'numeric_precision' => 8, 'numeric_scale' => 4],
            $this->numericColumn('ratings', 'optik'),
        );
        self::assertSame(
            ['data_type' => 'decimal', 'numeric_precision' => 12, 'numeric_scale' => 5],
            $this->numericColumn('drink_tests', 'price_amount'),
        );

        $statement = $this->connection->query(
            <<<'SQL'
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name IN ('drinks', 'drink_tests', 'ratings')
                ORDER BY column_name
                SQL,
        );
        self::assertNotFalse($statement);
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        foreach (['optik_average', 'sueffigkeit_average', 'geschmack_average', 'gesamt', 'rank', 'price_performance'] as $derivedColumn) {
            self::assertNotContains($derivedColumn, $columns);
        }
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $statement = $this->connection->query(
            <<<'SQL'
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_type = 'BASE TABLE'
                ORDER BY BINARY table_name
                SQL,
        );
        self::assertNotFalse($statement);

        /** @var list<string> $tables */
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $tables;
    }

    private function insertDrink(string $name, string $status): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO drinks (name, lifecycle_status) VALUES (:name, :lifecycle_status)',
        );
        $statement->execute(['name' => $name, 'lifecycle_status' => $status]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertTest(int $drinkId): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO drink_tests (drink_id, status) VALUES (:drink_id, 'draft')",
        );
        $statement->execute(['drink_id' => $drinkId]);

        return (int) $this->connection->lastInsertId();
    }

    private function testerId(string $code): int
    {
        $statement = $this->connection->prepare('SELECT id FROM testers WHERE code = :code');
        $statement->execute(['code' => $code]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{data_type: string, numeric_precision: int, numeric_scale: int} */
    private function numericColumn(string $table, string $column): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT data_type, numeric_precision, numeric_scale
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                  AND column_name = :column_name
                SQL,
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            self::fail('Could not inspect numeric column ' . $table . '.' . $column . '.');
        }

        /** @var array{data_type: string, numeric_precision: int|string, numeric_scale: int|string} $row */
        return [
            'data_type' => (string) $row['data_type'],
            'numeric_precision' => (int) $row['numeric_precision'],
            'numeric_scale' => (int) $row['numeric_scale'],
        ];
    }

    private function dropAllTables(): void
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
