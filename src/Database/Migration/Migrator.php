<?php

declare(strict_types=1);

namespace Spezitest\Database\Migration;

use PDO;
use PDOException;

final readonly class Migrator
{
    private const FILE_PATTERN = '/\A\d{14}_[a-z0-9]+(?:_[a-z0-9]+)*\.sql\z/D';

    public function __construct(
        private PDO $connection,
        private string $migrationDirectory,
    ) {
    }

    /**
     * @return list<string> Applied migration versions in execution order.
     */
    public function migrate(): array
    {
        $this->ensureTrackingTableExists();
        $appliedMigrations = $this->loadAppliedMigrations();
        $newlyApplied = [];

        foreach ($this->discoverMigrations() as $migration) {
            $version = $migration['version'];

            if (isset($appliedMigrations[$version])) {
                if (!hash_equals($appliedMigrations[$version], $migration['checksum'])) {
                    throw new MigrationException(
                        'Applied migration has been modified: ' . $version,
                    );
                }

                continue;
            }

            $this->applyMigration($migration);
            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }

    private function ensureTrackingTableExists(): void
    {
        $statement = <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;

        try {
            $this->connection->exec($statement);
        } catch (PDOException $exception) {
            throw new MigrationException('Could not create or access schema_migrations.', 0, $exception);
        }
    }

    /**
     * @return array<string, string> Map of migration version to checksum.
     */
    private function loadAppliedMigrations(): array
    {
        try {
            $statement = $this->connection->query(
                'SELECT version, checksum FROM schema_migrations ORDER BY version',
            );
        } catch (PDOException $exception) {
            throw new MigrationException('Could not read schema_migrations.', 0, $exception);
        }

        if ($statement === false) {
            throw new MigrationException('Could not read schema_migrations.');
        }

        $applied = [];

        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new MigrationException('schema_migrations returned invalid tracking data.');
            }

            $version = $row['version'] ?? null;
            $checksum = $row['checksum'] ?? null;

            if (!is_string($version) || !is_string($checksum)) {
                throw new MigrationException('schema_migrations contains invalid tracking data.');
            }

            $applied[$version] = $checksum;
        }

        return $applied;
    }

    /**
     * @return list<array{version: string, sql: string, checksum: string}>
     */
    private function discoverMigrations(): array
    {
        if (!is_dir($this->migrationDirectory) || !is_readable($this->migrationDirectory)) {
            throw new MigrationException('The migration directory is unavailable.');
        }

        $paths = glob($this->migrationDirectory . '/*.sql');

        if ($paths === false) {
            throw new MigrationException('The migration directory could not be read.');
        }

        sort($paths, SORT_STRING);
        $migrations = [];

        foreach ($paths as $path) {
            $filename = basename($path);

            if (!is_file($path) || is_link($path)) {
                throw new MigrationException('Migration is not a regular file: ' . $filename);
            }

            if (preg_match(self::FILE_PATTERN, $filename) !== 1) {
                throw new MigrationException('Invalid migration filename: ' . $filename);
            }

            $sql = file_get_contents($path);

            if ($sql === false || trim($sql) === '') {
                throw new MigrationException('Migration is empty or unreadable: ' . $filename);
            }

            $migrations[] = [
                'version' => substr($filename, 0, -4),
                'sql' => $sql,
                'checksum' => hash('sha256', $sql),
            ];
        }

        return $migrations;
    }

    /**
     * @param array{version: string, sql: string, checksum: string} $migration
     */
    private function applyMigration(array $migration): void
    {
        try {
            $result = $this->connection->exec($migration['sql']);

            if ($result === false) {
                throw new PDOException('PDO did not execute the migration.');
            }

            $statement = $this->connection->prepare(
                'INSERT INTO schema_migrations (version, checksum) VALUES (:version, :checksum)',
            );
            $statement->execute([
                'version' => $migration['version'],
                'checksum' => $migration['checksum'],
            ]);
        } catch (PDOException $exception) {
            throw new MigrationException(
                'Migration failed and was not recorded: ' . $migration['version'],
                0,
                $exception,
            );
        }
    }
}
