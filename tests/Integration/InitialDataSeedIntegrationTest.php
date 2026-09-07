<?php

declare(strict_types=1);

namespace Spezitest\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Spezitest\Database\Migration\Migrator;
use Spezitest\Tests\Support\InteractsWithTestDatabase;

final class InitialDataSeedIntegrationTest extends TestCase
{
    use InteractsWithTestDatabase;

    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = $this->connectToTestDatabase();
        $this->dropAllTables();
        (new Migrator($this->connection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
    }

    public function testReviewedInitialDataLoadsIntoFreshMigratedDatabase(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/resources/initial-data/spezitest-data.sql');
        self::assertIsString($sql);
        self::assertIsInt($this->connection->exec($sql));

        self::assertSame(196, $this->tableCount('drinks'));
        self::assertSame(3, $this->tableCount('testers'));
        self::assertSame(125, $this->tableCount('drink_tests'));
        self::assertSame(375, $this->tableCount('ratings'));
        self::assertSame(195, $this->tableCount('drink_images'));
        self::assertSame(1, $this->tableCount('legacy_import_runs'));
        self::assertSame(5, $this->tableCount('test_runs'));
        self::assertSame(0, $this->queryCount(
            "SELECT COUNT(*) FROM test_runs WHERE status <> 'completed' OR stream_url IS NULL",
        ));
        self::assertSame(0, $this->queryCount(
            'SELECT COUNT(*) FROM test_runs r LEFT JOIN drink_tests t ON t.stream_reference = r.number WHERE t.id IS NULL',
        ));
        self::assertSame(186, $this->queryCount("SELECT COUNT(*) FROM drink_images WHERE mime_type = 'image/webp' AND width = 640 AND height = 1024"));
        self::assertSame(10, $this->queryCount('SELECT COUNT(*) FROM drinks WHERE needs_new_photo = 1'));

        $lifecycle = $this->connection->query(
            'SELECT lifecycle_status, COUNT(*) total FROM drinks GROUP BY lifecycle_status ORDER BY lifecycle_status',
        );
        self::assertNotFalse($lifecycle);
        $lifecycleCounts = array_map(static function (mixed $value): int {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            self::assertIsInt($validated);

            return $validated;
        }, $lifecycle->fetchAll(PDO::FETCH_KEY_PAIR));
        self::assertSame([
            'acquired' => 17,
            'identified' => 54,
            'tested' => 125,
        ], $lifecycleCounts);
        self::assertSame(0, $this->queryCount(
            "SELECT COUNT(*) FROM drink_tests t LEFT JOIN (SELECT test_id, COUNT(*) total FROM ratings GROUP BY test_id) r ON r.test_id = t.id WHERE t.status <> 'completed' OR r.total <> 3",
        ));
    }

    public function testReviewedInitialDataRefusesANonEmptyTarget(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/resources/initial-data/spezitest-data.sql');
        self::assertIsString($sql);
        $this->connection->exec("INSERT INTO drinks (name, lifecycle_status) VALUES ('Existing', 'identified')");

        try {
            $this->connection->exec($sql);
            self::fail('The seed unexpectedly accepted a non-empty target.');
        } catch (PDOException) {
            self::assertSame(1, $this->tableCount('drinks'));
            self::assertSame(0, $this->tableCount('drink_tests'));
            self::assertSame(0, $this->tableCount('ratings'));
            self::assertSame(0, $this->tableCount('drink_images'));
            self::assertSame(0, $this->tableCount('test_runs'));
        }
    }

    private function tableCount(string $table): int
    {
        self::assertContains($table, ['drinks', 'testers', 'drink_tests', 'ratings', 'drink_images', 'legacy_import_runs', 'test_runs']);

        return $this->queryCount("SELECT COUNT(*) FROM $table");
    }

    private function queryCount(string $sql): int
    {
        $statement = $this->connection->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function dropAllTables(): void
    {
        $this->connection->exec(
            <<<'SQL'
                DROP TABLE IF EXISTS
                    ratings,
                    drink_images,
                    drink_tests,
                    test_runs,
                    legacy_import_runs,
                    testers,
                    drinks,
                    schema_migrations
                SQL,
        );
    }
}
