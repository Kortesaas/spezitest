<?php

declare(strict_types=1);

namespace Spezitest\Tests\Integration;

use JsonException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Database\Migration\Migrator;
use Spezitest\LegacyImport\LegacyImporter;
use Spezitest\LegacyImport\LegacyImportException;
use Spezitest\LegacyImport\LegacyImportPlan;

final class LegacyImporterIntegrationTest extends TestCase
{
    private PDO $connection;

    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(DatabaseConfiguration::fromEnvironment()))->create();
        $this->dropAllTables();
        (new Migrator($this->connection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->temporaryRoot = sys_get_temp_dir() . '/spezitest-legacy-import-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryRoot . '/plan/images', 0770, true));
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
        $this->removeTree($this->temporaryRoot);
    }

    /** @throws JsonException */
    public function testApplyPreservesRelationshipsAndRefusesDuplicateRun(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $hash = hash('sha256', $png);
        $imageName = $hash . '.png';
        self::assertNotFalse(file_put_contents($this->temporaryRoot . '/plan/images/' . $imageName, $png));

        $runId = str_repeat('a', 64);
        $planData = [
            'schema_version' => 1,
            'run_id' => $runId,
            'apply_ready' => true,
            'unresolved_review_ids' => [],
            'sources' => [
                'primaerliste' => ['sha256_before' => str_repeat('b', 64)],
                'beschaffungsliste' => ['sha256_before' => str_repeat('c', 64)],
            ],
            'counts' => [
                'drinks' => 1,
                'lifecycle' => ['identified' => 0, 'acquired' => 0, 'tested' => 1],
                'tests' => 1,
                'ratings' => 3,
                'images_attached' => 1,
            ],
            'drinks' => [[
                'plan_key' => 'primaerliste:2',
                'name' => 'Fixture Cola-Mix',
                'lifecycle_status' => 'tested',
                'manufacturer' => null,
                'origin_location' => null,
                'origin_region' => null,
                'notes' => null,
                'tests' => [[
                    'source' => 'primaerliste:2',
                    'status' => 'completed',
                    'price_amount' => null,
                    'recorded_time' => null,
                    'duration_value' => null,
                    'stream_reference' => 1,
                    'ratings' => [
                        'manu' => ['optik' => '9', 'sueffigkeit' => '10', 'geschmack' => '10'],
                        'fabi' => ['optik' => '9', 'sueffigkeit' => '10', 'geschmack' => '10'],
                        'schorsch' => ['optik' => '8', 'sueffigkeit' => '8', 'geschmack' => '8'],
                    ],
                    'historical' => ['gesamt' => '55.33', 'rank' => 1],
                ]],
                'images' => [[
                    'staged_path' => 'images/' . $imageName,
                    'sha256' => $hash,
                    'mime_type' => 'image/png',
                    'width' => 1,
                    'height' => 1,
                ]],
            ]],
        ];
        $encoded = json_encode($planData, JSON_THROW_ON_ERROR);
        $planPath = $this->temporaryRoot . '/plan/import-plan.json';
        self::assertNotFalse(file_put_contents($planPath, $encoded));
        $plan = LegacyImportPlan::load($planPath);
        $importer = new LegacyImporter($this->connection);
        $result = $importer->apply($plan, ['fixture' => true], $this->temporaryRoot . '/storage', dirname(__DIR__, 2));

        self::assertSame(['drinks' => 1, 'tests' => 1, 'ratings' => 3, 'images' => 1, 'prices' => 0], array_intersect_key($result, array_flip(['drinks', 'tests', 'ratings', 'images', 'prices'])));
        foreach (['drinks' => 1, 'drink_tests' => 1, 'ratings' => 3, 'drink_images' => 1, 'legacy_import_runs' => 1] as $table => $expected) {
            $statement = $this->connection->query('SELECT COUNT(*) FROM ' . $table);
            self::assertNotFalse($statement);
            self::assertSame($expected, (int) $statement->fetchColumn());
        }
        self::assertFileExists($this->temporaryRoot . '/storage/legacy/' . $runId . '/' . $imageName);

        $foreignKeyRejected = false;
        try {
            $this->connection->exec('DELETE FROM drinks');
        } catch (PDOException) {
            $foreignKeyRejected = true;
        }
        self::assertTrue($foreignKeyRejected, 'The drink foreign key should prevent deletion.');

        $this->expectException(LegacyImportException::class);
        $this->expectExceptionMessage('requires empty domain/import tables');
        $importer->apply($plan, ['fixture' => true], $this->temporaryRoot . '/storage', dirname(__DIR__, 2));
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

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
