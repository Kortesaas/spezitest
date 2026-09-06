#!/usr/bin/env php
<?php

declare(strict_types=1);

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;

const EXPECTED_COUNTS = [
    'drinks' => 196,
    'testers' => 3,
    'drink_tests' => 125,
    'ratings' => 375,
    'drink_images' => 195,
    'legacy_import_runs' => 1,
];
const EXPECTED_LIFECYCLE = ['identified' => 54, 'acquired' => 17, 'tested' => 125];
const EXPECTED_WEBP_IMAGES = 186;
const EXPECTED_FALLBACK_IMAGES = 9;
const EXPECTED_PHOTO_FLAGS = 10;
const EXPECTED_SOURCE_WORKBOOK_SHA256 = '39b7d954dc3b39dabe71852d841ac66f02e7564390af55b5cf1eb413cf0ca096';
const EXPECTED_REFRESH_PLAN_SHA256 = '4cb41e75aaed1123003f531cb4c05eb3680263be85300ae63fb8bce8495d602a';

final class InitialDataException extends RuntimeException
{
}

/** @return list<array<string, mixed>> */
function fetchRows(PDO $pdo, string $sql): array
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new InitialDataException('A seed export query failed.');
    }
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new InitialDataException('A seed export row is invalid.');
        }
    }

    /** @var list<array<string, mixed>> $rows */
    return $rows;
}

function fetchCount(PDO $pdo, string $sql): int
{
    $statement = $pdo->query($sql);
    $value = $statement === false ? false : $statement->fetchColumn();
    if (!is_int($value) && !is_string($value)) {
        throw new InitialDataException('A seed export count query failed.');
    }

    return (int) $value;
}

function sqlText(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (!is_string($value)) {
        throw new InitialDataException('Expected a textual database value.');
    }
    if ($value === '') {
        return "''";
    }

    return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4)';
}

function sqlInteger(mixed $value, bool $nullable = false): string
{
    if ($value === null && $nullable) {
        return 'NULL';
    }
    if ((is_int($value) || is_string($value)) && preg_match('/\A\d+\z/D', (string) $value) === 1) {
        return (string) (int) $value;
    }

    throw new InitialDataException('Expected an unsigned integer database value.');
}

function sqlDecimal(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_string($value) && preg_match('/\A\d+(?:\.\d+)?\z/D', $value) === 1) {
        return $value;
    }

    throw new InitialDataException('Expected a non-negative decimal database value.');
}

/**
 * @param list<string> $columns
 * @param list<list<string>> $rows
 */
function insertSql(string $table, array $columns, array $rows): string
{
    if ($rows === []) {
        return '';
    }
    $parts = [];
    foreach (array_chunk($rows, 100) as $chunk) {
        $values = array_map(static fn (array $row): string => '    (' . implode(', ', $row) . ')', $chunk);
        $parts[] = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ") VALUES\n"
            . implode(",\n", $values) . ";\n";
    }

    return implode("\n", $parts);
}

/** @param array<mixed> $row */
function requiredString(array $row, string $key): string
{
    $value = $row[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new InitialDataException("Missing string field: $key");
    }

    return $value;
}

function requiredUnsignedInteger(mixed $value, string $field): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/\A\d+\z/D', $value) === 1) {
        return (int) $value;
    }

    throw new InitialDataException("Invalid unsigned integer field: $field");
}

/**
 * @param list<array<string, mixed>> $imageRows
 * @return list<array{storage_path: string, repository_path: string, sha256: string, mime_type: string, width: int, height: int, bytes: int}>
 */
function verifyImageSources(string $root, array $imageRows): array
{
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $entries = [];
    $repositoryPaths = [];
    $webpCount = 0;
    $fallbackCount = 0;

    foreach ($imageRows as $row) {
        $storagePath = requiredString($row, 'storage_path');
        if (preg_match('~\Aadmin/640x1024/([a-f0-9]{64}\.webp)\z~D', $storagePath, $matches) === 1) {
            $repositoryPath = 'resources/primary-images/640x1024/' . $matches[1];
            ++$webpCount;
        } elseif (preg_match('~\Alegacy/[a-f0-9]{64}/[a-f0-9]{64}\.(?:png|jpg)\z~D', $storagePath) === 1) {
            $repositoryPath = 'resources/primary-images/' . $storagePath;
            ++$fallbackCount;
        } else {
            throw new InitialDataException("Unsupported seed image path: $storagePath");
        }

        $absolutePath = $root . '/' . $repositoryPath;
        $dimensions = is_file($absolutePath) ? getimagesize($absolutePath) : false;
        $mime = is_file($absolutePath) ? $fileInfo->file($absolutePath) : false;
        $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
        $bytes = is_file($absolutePath) ? filesize($absolutePath) : false;
        if (
            !is_array($dimensions)
            || !is_string($mime)
            || !is_string($hash)
            || !is_int($bytes)
            || $mime !== requiredString($row, 'mime_type')
            || $dimensions[0] !== requiredUnsignedInteger($row['width'] ?? null, 'width')
            || $dimensions[1] !== requiredUnsignedInteger($row['height'] ?? null, 'height')
        ) {
            throw new InitialDataException("Seed image verification failed: $storagePath");
        }
        if (isset($repositoryPaths[$repositoryPath])) {
            throw new InitialDataException("Duplicate seed image source: $repositoryPath");
        }
        $repositoryPaths[$repositoryPath] = true;
        $entries[] = [
            'storage_path' => $storagePath,
            'repository_path' => $repositoryPath,
            'sha256' => $hash,
            'mime_type' => $mime,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'bytes' => $bytes,
        ];
    }

    if ($webpCount !== EXPECTED_WEBP_IMAGES || $fallbackCount !== EXPECTED_FALLBACK_IMAGES) {
        throw new InitialDataException('Seed image coverage must be exactly 186 WebPs and 9 retained fallbacks.');
    }
    usort($entries, static fn (array $left, array $right): int => $left['storage_path'] <=> $right['storage_path']);

    return $entries;
}

/** @param array<mixed> $manifest */
function verifyTrackedPackage(string $root, string $sqlPath, string $planPath, array $manifest): void
{
    if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['counts'] ?? null) !== EXPECTED_COUNTS) {
        throw new InitialDataException('The initial-data manifest has unexpected counts or schema.');
    }
    if (($manifest['lifecycle'] ?? null) !== EXPECTED_LIFECYCLE || ($manifest['photo_needed'] ?? null) !== EXPECTED_PHOTO_FLAGS) {
        throw new InitialDataException('The initial-data lifecycle or photo marker counts are unexpected.');
    }
    $actualPlanHash = is_file($planPath) ? hash_file('sha256', $planPath) : false;
    if (
        ($manifest['source_workbook_sha256'] ?? null) !== EXPECTED_SOURCE_WORKBOOK_SHA256
        || ($manifest['refresh_plan_sha256'] ?? null) !== EXPECTED_REFRESH_PLAN_SHA256
        || $actualPlanHash !== EXPECTED_REFRESH_PLAN_SHA256
    ) {
        throw new InitialDataException('The reviewed refresh-plan provenance is missing or changed.');
    }
    $expectedSqlHash = $manifest['sql_sha256'] ?? null;
    $actualSqlHash = is_file($sqlPath) ? hash_file('sha256', $sqlPath) : false;
    $sql = is_file($sqlPath) ? file_get_contents($sqlPath) : false;
    if (!is_string($expectedSqlHash) || !is_string($actualSqlHash) || !is_string($sql)) {
        throw new InitialDataException('The tracked initial-data SQL is missing or changed.');
    }
    if (!hash_equals($expectedSqlHash, $actualSqlHash)) {
        $lfSql = str_replace("\r\n", "\n", $sql);
        if ($lfSql !== $sql && hash_equals($expectedSqlHash, hash('sha256', $lfSql))) {
            throw new InitialDataException('The initial-data SQL has CRLF line endings. Restore the tracked LF version; .gitattributes prevents this on new checkouts.');
        }
        throw new InitialDataException('The tracked initial-data SQL is missing or changed.');
    }
    if (str_contains($sql, 'DB_PASSWORD') || preg_match('/\bDROP\s+TABLE\b/i', $sql) === 1) {
        throw new InitialDataException('The initial-data SQL contains a forbidden secret marker or destructive table drop.');
    }

    $imagesValue = $manifest['images'] ?? null;
    if (!is_array($imagesValue) || !array_is_list($imagesValue) || count($imagesValue) !== EXPECTED_COUNTS['drink_images']) {
        throw new InitialDataException('The initial-data image manifest is incomplete.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $listed = [];
    foreach ($imagesValue as $value) {
        if (!is_array($value) || array_is_list($value)) {
            throw new InitialDataException('An initial-data image manifest entry is invalid.');
        }
        $path = requiredString($value, 'repository_path');
        if (!str_starts_with($path, 'resources/primary-images/') || str_contains($path, '..') || isset($listed[$path])) {
            throw new InitialDataException('An initial-data repository image path is unsafe or duplicated.');
        }
        $listed[$path] = true;
        $absolutePath = $root . '/' . $path;
        $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
        $dimensions = is_file($absolutePath) ? getimagesize($absolutePath) : false;
        $mime = is_file($absolutePath) ? $fileInfo->file($absolutePath) : false;
        $bytes = is_file($absolutePath) ? filesize($absolutePath) : false;
        if (
            !is_string($hash)
            || !hash_equals(requiredString($value, 'sha256'), $hash)
            || !is_array($dimensions)
            || $dimensions[0] !== requiredUnsignedInteger($value['width'] ?? null, 'width')
            || $dimensions[1] !== requiredUnsignedInteger($value['height'] ?? null, 'height')
            || $mime !== requiredString($value, 'mime_type')
            || $bytes !== requiredUnsignedInteger($value['bytes'] ?? null, 'bytes')
        ) {
            throw new InitialDataException("Tracked initial-data image failed verification: $path");
        }
    }

    $actual = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/resources/primary-images', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || !in_array(strtolower($file->getExtension()), ['webp', 'png', 'jpg'], true)) {
            continue;
        }
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $actual[$path] = true;
    }
    $listedPaths = array_keys($listed);
    $actualPaths = array_keys($actual);
    sort($listedPaths);
    sort($actualPaths);
    if ($listedPaths !== $actualPaths) {
        throw new InitialDataException('Tracked image files differ from the initial-data manifest.');
    }

    fwrite(STDOUT, json_encode([
        'stage' => 'verify',
        'sql_sha256' => $actualSqlHash,
        'counts' => EXPECTED_COUNTS,
        'lifecycle' => EXPECTED_LIFECYCLE,
        'photo_needed' => EXPECTED_PHOTO_FLAGS,
        'images' => count($listed),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function exportInitialData(string $root, string $sqlPath, string $manifestPath): void
{
    require $root . '/config/environment.php';
    $environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
    if (!is_string($environment) || !in_array(strtolower($environment), ['local', 'development', 'testing'], true)) {
        throw new InitialDataException('Initial data may be exported only from a non-production environment.');
    }
    $configuration = DatabaseConfiguration::fromEnvironment();
    if (preg_match('/prod(?:uction)?/i', $configuration->databaseName()) === 1) {
        throw new InitialDataException('Refusing to export from a database whose name looks like production.');
    }
    $pdo = (new ConnectionFactory($configuration))->create();

    $tables = [
        'drinks' => fetchRows($pdo, 'SELECT id, name, lifecycle_status, manufacturer, origin_location, origin_region, notes, needs_new_photo, price_amount, price_volume_ml, created_at, updated_at FROM drinks ORDER BY id'),
        'testers' => fetchRows($pdo, 'SELECT id, code FROM testers ORDER BY id'),
        'drink_tests' => fetchRows($pdo, 'SELECT id, drink_id, status, price_amount, recorded_time, duration_value, stream_reference, completed_at, notes, created_at, updated_at FROM drink_tests ORDER BY id'),
        'ratings' => fetchRows($pdo, 'SELECT r.id, r.test_id, t.code tester_code, r.optik, r.sueffigkeit, r.geschmack, r.created_at, r.updated_at FROM ratings r INNER JOIN testers t ON t.id = r.tester_id ORDER BY r.id'),
        'drink_images' => fetchRows($pdo, 'SELECT id, drink_id, storage_path, mime_type, width, height, display_order, created_at FROM drink_images ORDER BY id'),
        'legacy_import_runs' => fetchRows($pdo, 'SELECT run_id, plan_sha256, primaerliste_sha256, beschaffungsliste_sha256, summary_json, applied_at FROM legacy_import_runs ORDER BY run_id'),
    ];
    foreach (EXPECTED_COUNTS as $table => $expected) {
        $actual = $table === 'testers' ? count($tables['testers']) : count($tables[$table]);
        if ($actual !== $expected) {
            throw new InitialDataException("Unexpected $table row count: $actual");
        }
    }
    $lifecycle = [];
    foreach (fetchRows($pdo, 'SELECT lifecycle_status, COUNT(*) total FROM drinks GROUP BY lifecycle_status') as $row) {
        $lifecycle[requiredString($row, 'lifecycle_status')] = requiredUnsignedInteger($row['total'] ?? null, 'total');
    }
    ksort($lifecycle);
    $expectedLifecycle = EXPECTED_LIFECYCLE;
    ksort($expectedLifecycle);
    if ($lifecycle !== $expectedLifecycle) {
        throw new InitialDataException('Unexpected lifecycle counts in the export database.');
    }
    $photoNeeded = fetchCount($pdo, 'SELECT COUNT(*) FROM drinks WHERE needs_new_photo = 1');
    if ($photoNeeded !== EXPECTED_PHOTO_FLAGS) {
        throw new InitialDataException('Unexpected photo follow-up count in the export database.');
    }
    if (fetchCount($pdo, "SELECT COUNT(*) FROM drink_tests WHERE status = 'completed'") !== EXPECTED_COUNTS['drink_tests']) {
        throw new InitialDataException('The export database contains a non-completed test.');
    }
    if (fetchCount($pdo, 'SELECT COUNT(*) FROM (SELECT test_id FROM ratings GROUP BY test_id HAVING COUNT(*) <> 3) invalid') !== 0) {
        throw new InitialDataException('A completed test does not have exactly three ratings.');
    }

    $testerCodes = array_map(static fn (array $row): string => requiredString($row, 'code'), $tables['testers']);
    sort($testerCodes);
    if ($testerCodes !== ['fabi', 'manu', 'schorsch']) {
        throw new InitialDataException('The canonical tester set is invalid.');
    }
    $images = verifyImageSources($root, $tables['drink_images']);

    $drinkValues = array_map(static fn (array $row): array => [
        sqlInteger($row['id'] ?? null), sqlText($row['name'] ?? null), sqlText($row['lifecycle_status'] ?? null),
        sqlText($row['manufacturer'] ?? null), sqlText($row['origin_location'] ?? null), sqlText($row['origin_region'] ?? null),
        sqlText($row['notes'] ?? null), sqlInteger($row['needs_new_photo'] ?? null), sqlDecimal($row['price_amount'] ?? null),
        sqlInteger($row['price_volume_ml'] ?? null, true), sqlText($row['created_at'] ?? null), sqlText($row['updated_at'] ?? null),
    ], $tables['drinks']);
    $testValues = array_map(static fn (array $row): array => [
        sqlInteger($row['id'] ?? null), sqlInteger($row['drink_id'] ?? null), sqlText($row['status'] ?? null),
        sqlDecimal($row['price_amount'] ?? null), sqlText($row['recorded_time'] ?? null), sqlInteger($row['duration_value'] ?? null, true),
        sqlInteger($row['stream_reference'] ?? null, true), sqlText($row['completed_at'] ?? null), sqlText($row['notes'] ?? null),
        sqlText($row['created_at'] ?? null), sqlText($row['updated_at'] ?? null),
    ], $tables['drink_tests']);
    $ratingValues = array_map(static function (array $row): array {
        $code = requiredString($row, 'tester_code');
        if (!in_array($code, ['manu', 'fabi', 'schorsch'], true)) {
            throw new InitialDataException('A rating uses an unknown tester code.');
        }

        return [
            sqlInteger($row['id'] ?? null), sqlInteger($row['test_id'] ?? null), '@tester_' . $code,
            sqlDecimal($row['optik'] ?? null), sqlDecimal($row['sueffigkeit'] ?? null), sqlDecimal($row['geschmack'] ?? null),
            sqlText($row['created_at'] ?? null), sqlText($row['updated_at'] ?? null),
        ];
    }, $tables['ratings']);
    $imageValues = array_map(static fn (array $row): array => [
        sqlInteger($row['id'] ?? null), sqlInteger($row['drink_id'] ?? null), sqlText($row['storage_path'] ?? null),
        sqlText($row['mime_type'] ?? null), sqlInteger($row['width'] ?? null), sqlInteger($row['height'] ?? null),
        sqlInteger($row['display_order'] ?? null), sqlText($row['created_at'] ?? null),
    ], $tables['drink_images']);
    $runValues = array_map(static fn (array $row): array => [
        sqlText($row['run_id'] ?? null), sqlText($row['plan_sha256'] ?? null), sqlText($row['primaerliste_sha256'] ?? null),
        sqlText($row['beschaffungsliste_sha256'] ?? null), sqlText($row['summary_json'] ?? null), sqlText($row['applied_at'] ?? null),
    ], $tables['legacy_import_runs']);

    $sql = <<<'SQL'
-- Spezitest reviewed initial data, data-only and safe for a freshly migrated empty database.
-- Generated by tools/initial-data.php; do not edit by hand.
-- Reviewed refresh plan SHA-256: 4cb41e75aaed1123003f531cb4c05eb3680263be85300ae63fb8bce8495d602a
SET NAMES utf8mb4;

CREATE TEMPORARY TABLE spezitest_seed_guard (
    must_be_zero BIGINT NOT NULL,
    CONSTRAINT chk_spezitest_seed_guard CHECK (must_be_zero = 0)
);
INSERT INTO spezitest_seed_guard (must_be_zero)
SELECT
    (SELECT COUNT(*) FROM drinks)
    + (SELECT COUNT(*) FROM drink_tests)
    + (SELECT COUNT(*) FROM ratings)
    + (SELECT COUNT(*) FROM drink_images)
    + (SELECT COUNT(*) FROM legacy_import_runs)
    + ABS(3 - (SELECT COUNT(*) FROM testers))
    + ABS(3 - (SELECT COUNT(*) FROM testers WHERE code IN ('manu', 'fabi', 'schorsch')));
DROP TEMPORARY TABLE spezitest_seed_guard;

SET @tester_manu = (SELECT id FROM testers WHERE code = 'manu');
SET @tester_fabi = (SELECT id FROM testers WHERE code = 'fabi');
SET @tester_schorsch = (SELECT id FROM testers WHERE code = 'schorsch');

START TRANSACTION;

SQL;
    $sql .= insertSql('drinks', ['id', 'name', 'lifecycle_status', 'manufacturer', 'origin_location', 'origin_region', 'notes', 'needs_new_photo', 'price_amount', 'price_volume_ml', 'created_at', 'updated_at'], $drinkValues) . "\n";
    $sql .= insertSql('drink_tests', ['id', 'drink_id', 'status', 'price_amount', 'recorded_time', 'duration_value', 'stream_reference', 'completed_at', 'notes', 'created_at', 'updated_at'], $testValues) . "\n";
    $sql .= insertSql('ratings', ['id', 'test_id', 'tester_id', 'optik', 'sueffigkeit', 'geschmack', 'created_at', 'updated_at'], $ratingValues) . "\n";
    $sql .= insertSql('drink_images', ['id', 'drink_id', 'storage_path', 'mime_type', 'width', 'height', 'display_order', 'created_at'], $imageValues) . "\n";
    $sql .= insertSql('legacy_import_runs', ['run_id', 'plan_sha256', 'primaerliste_sha256', 'beschaffungsliste_sha256', 'summary_json', 'applied_at'], $runValues) . "\n";
    $sql .= <<<'SQL'
CREATE TEMPORARY TABLE spezitest_seed_result_guard (
    difference_count BIGINT NOT NULL,
    CONSTRAINT chk_spezitest_seed_result_guard CHECK (difference_count = 0)
);
INSERT INTO spezitest_seed_result_guard (difference_count)
SELECT
    ABS(196 - (SELECT COUNT(*) FROM drinks))
    + ABS(125 - (SELECT COUNT(*) FROM drink_tests))
    + ABS(375 - (SELECT COUNT(*) FROM ratings))
    + ABS(195 - (SELECT COUNT(*) FROM drink_images))
    + ABS(1 - (SELECT COUNT(*) FROM legacy_import_runs))
    + ABS(3 - (SELECT COUNT(*) FROM testers))
    + ABS(3 - (SELECT COUNT(*) FROM testers WHERE code IN ('manu', 'fabi', 'schorsch')))
    + ABS(54 - (SELECT COUNT(*) FROM drinks WHERE lifecycle_status = 'identified'))
    + ABS(17 - (SELECT COUNT(*) FROM drinks WHERE lifecycle_status = 'acquired'))
    + ABS(125 - (SELECT COUNT(*) FROM drinks WHERE lifecycle_status = 'tested'))
    + ABS(10 - (SELECT COUNT(*) FROM drinks WHERE needs_new_photo = 1))
    + (SELECT COUNT(*) FROM drink_tests WHERE status <> 'completed')
    + (SELECT COUNT(*) FROM drink_tests t
        LEFT JOIN (SELECT test_id, COUNT(*) total FROM ratings GROUP BY test_id) r ON r.test_id = t.id
        WHERE r.total IS NULL OR r.total <> 3);
DROP TEMPORARY TABLE spezitest_seed_result_guard;

COMMIT;
SET @tester_manu = NULL;
SET @tester_fabi = NULL;
SET @tester_schorsch = NULL;
SQL;
    $sql .= "\n";

    $planPath = $root . '/resources/initial-data/refresh-plan.json';
    $planContents = is_file($planPath) ? file_get_contents($planPath) : false;
    $plan = is_string($planContents) ? json_decode($planContents, true) : null;
    $workbookHash = is_array($plan) && is_array($plan['workbook'] ?? null) ? ($plan['workbook']['sha256'] ?? null) : null;
    if (
        !is_string($planContents)
        || hash('sha256', $planContents) !== EXPECTED_REFRESH_PLAN_SHA256
        || $workbookHash !== EXPECTED_SOURCE_WORKBOOK_SHA256
    ) {
        throw new InitialDataException('The verified primary refresh plan is required for export provenance.');
    }

    $directory = dirname($sqlPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new InitialDataException('Could not create the initial-data resource directory.');
    }
    if (file_put_contents($sqlPath, $sql, LOCK_EX) === false) {
        throw new InitialDataException('Could not write the initial-data SQL.');
    }
    $manifest = [
        'schema_version' => 1,
        'source_workbook_sha256' => EXPECTED_SOURCE_WORKBOOK_SHA256,
        'refresh_plan_sha256' => EXPECTED_REFRESH_PLAN_SHA256,
        'sql_path' => 'resources/initial-data/spezitest-data.sql',
        'sql_sha256' => hash('sha256', $sql),
        'counts' => EXPECTED_COUNTS,
        'lifecycle' => EXPECTED_LIFECYCLE,
        'photo_needed' => EXPECTED_PHOTO_FLAGS,
        'images' => $images,
    ];
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) === false) {
        throw new InitialDataException('Could not write the initial-data manifest.');
    }
    verifyTrackedPackage($root, $sqlPath, $planPath, $manifest);
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new InitialDataException('Initial-data tooling is CLI-only.');
    }
    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    $command = $argv[1] ?? 'verify';
    if (!in_array($command, ['export', 'verify'], true)) {
        throw new InitialDataException('Usage: php tools/initial-data.php export|verify');
    }
    $sqlPath = $root . '/resources/initial-data/spezitest-data.sql';
    $manifestPath = $root . '/resources/initial-data/manifest.json';
    $planPath = $root . '/resources/initial-data/refresh-plan.json';
    if ($command === 'export') {
        exportInitialData($root, $sqlPath, $manifestPath);
        exit(0);
    }
    $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
    $manifest = is_string($contents) ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($manifest) || array_is_list($manifest)) {
        throw new InitialDataException('Could not read the initial-data manifest.');
    }
    verifyTrackedPackage($root, $sqlPath, $planPath, $manifest);
} catch (InitialDataException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Initial-data tooling failed without exposing internal details.\n");
    exit(1);
}
