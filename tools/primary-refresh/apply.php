#!/usr/bin/env php
<?php

declare(strict_types=1);

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Admin\Configuration\AdminConfiguration;
use Spezitest\Configuration\AppConfiguration;
use Spezitest\Domain\Rating\CompetitionRanking;
use Spezitest\Domain\Rating\ExactNumber;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterRatingFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class PrimaryRefreshException extends RuntimeException
{
}

/**
 * @param array<string, mixed> $map
 * @return array<string, mixed>
 */
function requiredMap(array $map, string $key): array
{
    $value = $map[$key] ?? null;
    if (!is_array($value)) {
        throw new PrimaryRefreshException("Missing object: $key");
    }

    return objectMap($value, $key);
}

/**
 * @param array<string, mixed> $map
 * @return list<mixed>
 */
function requiredList(array $map, string $key): array
{
    $value = $map[$key] ?? null;
    if (!is_array($value) || !array_is_list($value)) {
        throw new PrimaryRefreshException("Missing list: $key");
    }

    return $value;
}

/**
 * @param array<array-key, mixed> $value
 * @return array<string, mixed>
 */
function objectMap(array $value, string $label): array
{
    if (array_is_list($value)) {
        throw new PrimaryRefreshException("Expected an object: $label");
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            throw new PrimaryRefreshException("Invalid object keys: $label");
        }
        $result[$key] = $item;
    }

    return $result;
}

/** @param array<string, mixed> $map */
function requiredString(array $map, string $key): string
{
    $value = $map[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new PrimaryRefreshException("Missing string: $key");
    }

    return $value;
}

/** @param array<string, mixed> $map */
function requiredInt(array $map, string $key): int
{
    $value = $map[$key] ?? null;
    if (!is_int($value)) {
        throw new PrimaryRefreshException("Missing integer: $key");
    }

    return $value;
}

/** @param array<string, mixed> $map */
function optionalString(array $map, string $key): ?string
{
    $value = $map[$key] ?? null;
    if ($value !== null && !is_string($value)) {
        throw new PrimaryRefreshException("Invalid optional string: $key");
    }

    return $value;
}

/** @param array<string, mixed> $map */
function optionalInt(array $map, string $key): ?int
{
    $value = $map[$key] ?? null;
    if ($value !== null && !is_int($value)) {
        throw new PrimaryRefreshException("Invalid optional integer: $key");
    }

    return $value;
}

/** @param array<string, mixed> $map */
function databaseInt(array $map, string $key): int
{
    $value = $map[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/\A\d+(?:\.0+)?\z/D', $value) === 1) {
        return (int) $value;
    }

    throw new PrimaryRefreshException("Invalid database integer: $key");
}

function resolvePath(string $root, string $path): string
{
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        ? $path
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

/**
 * @param array<string, mixed> $plan
 * @return array<string, int>
 */
function verifyPlan(array $plan, string $root): array
{
    if (($plan['schema_version'] ?? null) !== 1) {
        throw new PrimaryRefreshException('Unsupported primary refresh plan schema.');
    }
    $workbook = requiredMap($plan, 'workbook');
    $workbookPath = resolvePath($root, requiredString($workbook, 'path'));
    $actualWorkbookHash = hash_file('sha256', $workbookPath);
    if ($actualWorkbookHash === false || !hash_equals(requiredString($workbook, 'sha256'), $actualWorkbookHash)) {
        throw new PrimaryRefreshException('The source workbook is missing or its hash changed.');
    }

    $basePlan = requiredMap($plan, 'base_plan');
    $basePlanPath = resolvePath($root, requiredString($basePlan, 'path'));
    $actualBaseHash = hash_file('sha256', $basePlanPath);
    if ($actualBaseHash === false || !hash_equals(requiredString($basePlan, 'sha256'), $actualBaseHash)) {
        throw new PrimaryRefreshException('The reviewed base import plan is missing or changed.');
    }

    $records = requiredList($plan, 'primary_records');
    if (count($records) !== 166) {
        throw new PrimaryRefreshException('The refresh plan must contain 166 Primärliste records.');
    }

    $calculator = new RatingCalculator();
    $scores = [];
    $expectedRanks = [];
    $seenKeys = [];
    foreach ($records as $value) {
        if (!is_array($value)) {
            throw new PrimaryRefreshException('A primary record is invalid.');
        }
        $value = objectMap($value, 'primary record');
        $key = requiredString($value, 'plan_key');
        if (isset($seenKeys[$key])) {
            throw new PrimaryRefreshException("Duplicate primary record: $key");
        }
        $seenKeys[$key] = true;
        $status = requiredString($value, 'lifecycle_status');
        if (!in_array($status, ['identified', 'acquired', 'tested'], true)) {
            throw new PrimaryRefreshException("Invalid lifecycle status: $key");
        }
        $test = $value['test'] ?? null;
        if ($status === 'tested' && !is_array($test)) {
            throw new PrimaryRefreshException("Tested record has no test: $key");
        }
        if ($status !== 'tested' && $test !== null) {
            throw new PrimaryRefreshException("Untested record has test data: $key");
        }
        if (!is_array($test)) {
            continue;
        }
        $test = objectMap($test, "test $key");
        $ratingsValue = requiredMap($test, 'ratings');
        $ratings = [];
        foreach (['manu', 'fabi', 'schorsch'] as $tester) {
            $rating = requiredMap($ratingsValue, $tester);
            foreach (['optik', 'sueffigkeit', 'geschmack'] as $category) {
                $grade = requiredInt($rating, $category);
                if ($grade < 0 || $grade > 10) {
                    throw new PrimaryRefreshException("Grade outside 0–10: $key");
                }
            }
            $ratings[$tester] = [
                'optik' => requiredInt($rating, 'optik'),
                'sueffigkeit' => requiredInt($rating, 'sueffigkeit'),
                'geschmack' => requiredInt($rating, 'geschmack'),
            ];
        }
        $result = $calculator->calculate(TesterRatingFactory::fromMap($ratings));
        if ($result === null || ExactNumber::from(requiredString($test, 'historical_gesamt'))->compare($result->exactGesamt()) !== 0) {
            throw new PrimaryRefreshException("Verified rating formula mismatch: $key");
        }
        $scores[$key] = $result->gesamt();
        $expectedRanks[$key] = requiredInt($test, 'historical_rank');
    }
    if (count($scores) !== 125) {
        throw new PrimaryRefreshException('Expected exactly 125 completed tests.');
    }
    $ranks = (new CompetitionRanking())->rank($scores);
    foreach ($expectedRanks as $key => $expectedRank) {
        if (($ranks[$key] ?? null) !== $expectedRank) {
            throw new PrimaryRefreshException("Verified competition-rank mismatch: $key");
        }
    }

    $images = requiredList($plan, 'image_updates');
    if (count($images) !== 186) {
        throw new PrimaryRefreshException('Expected exactly 186 replacement images.');
    }
    $seenStoragePaths = [];
    foreach ($images as $value) {
        if (!is_array($value)) {
            throw new PrimaryRefreshException('An image update is invalid.');
        }
        $value = objectMap($value, 'image update');
        $sourcePath = resolvePath($root, requiredString($value, 'source_path'));
        $storagePath = requiredString($value, 'storage_path');
        $mimeType = requiredString($value, 'mime_type');
        $width = requiredInt($value, 'width');
        $height = requiredInt($value, 'height');
        if (
            preg_match('~\Aadmin/640x1024/[a-f0-9]{64}\.webp\z~D', $storagePath) !== 1
            || $mimeType !== 'image/webp'
            || $width !== 640
            || $height !== 1024
            || isset($seenStoragePaths[$storagePath])
        ) {
            throw new PrimaryRefreshException('A replacement image path is unsafe or duplicated.');
        }
        $seenStoragePaths[$storagePath] = true;
        $hash = hash_file('sha256', $sourcePath);
        $dimensions = is_file($sourcePath) ? getimagesize($sourcePath) : false;
        $mime = is_file($sourcePath) ? (new finfo(FILEINFO_MIME_TYPE))->file($sourcePath) : false;
        if (
            $hash === false
            || !hash_equals(requiredString($value, 'source_sha256'), $hash)
            || !is_array($dimensions)
            || $dimensions[0] !== $width
            || $dimensions[1] !== $height
            || $mime !== $mimeType
        ) {
            throw new PrimaryRefreshException("Replacement image verification failed: $storagePath");
        }
    }
    if (count(requiredList($plan, 'photo_needed_plan_keys')) !== 10) {
        throw new PrimaryRefreshException('Expected exactly 10 photo follow-up markers.');
    }

    return ['ratings' => count($scores), 'ranks' => count($ranks), 'images' => count($images)];
}

/**
 * @param array<string, mixed> $plan
 * @return array<string, int>
 */
function mapDatabaseRecords(PDO $pdo, array $plan): array
{
    $rows = queryStatement($pdo, 'SELECT id, name, manufacturer, origin_location FROM drinks ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 196) {
        throw new PrimaryRefreshException('The target database must contain the reviewed 196-drink dataset.');
    }
    $newIdentities = [];
    foreach (requiredList($plan, 'primary_records') as $value) {
        if (is_array($value)) {
            $value = objectMap($value, 'primary database identity');
            $newIdentities[requiredString($value, 'plan_key')] = $value;
        }
    }
    $mapped = [];
    foreach (requiredList($plan, 'database_records') as $value) {
        if (!is_array($value)) {
            throw new PrimaryRefreshException('A database match record is invalid.');
        }
        $value = objectMap($value, 'database match record');
        $key = requiredString($value, 'plan_key');
        $identities = [$value];
        if (isset($newIdentities[$key])) {
            $identities[] = $newIdentities[$key];
        }
        $matches = array_values(array_filter($rows, static function (mixed $row) use ($identities): bool {
            if (!is_array($row)) {
                return false;
            }
            foreach ($identities as $identity) {
                if (
                    $row['name'] === ($identity['name'] ?? null)
                    && $row['manufacturer'] === ($identity['manufacturer'] ?? null)
                    && $row['origin_location'] === ($identity['origin_location'] ?? null)
                ) {
                    return true;
                }
            }

            return false;
        }));
        if (count($matches) !== 1) {
            throw new PrimaryRefreshException("Database identity did not resolve uniquely: $key");
        }
        $matchedRow = $matches[0];
        if (!is_array($matchedRow)) {
            throw new PrimaryRefreshException("Database identity is invalid: $key");
        }
        $matchedId = $matchedRow['id'] ?? null;
        if (!is_int($matchedId) && !is_string($matchedId)) {
            throw new PrimaryRefreshException("Database identity has an invalid id: $key");
        }
        $mapped[$key] = (int) $matchedId;
    }
    if (count($mapped) !== 196 || count(array_unique($mapped)) !== 196) {
        throw new PrimaryRefreshException('The refresh plan did not map one-to-one to the database.');
    }

    return $mapped;
}

/** @return array<string, int> */
function databaseCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['drinks', 'drink_tests', 'ratings', 'drink_images'] as $table) {
        $counts[$table] = (int) queryStatement($pdo, "SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    return $counts;
}

/**
 * @param array<string, mixed> $plan
 * @param array<string, int> $ids
 * @return array<string, int>
 */
function verifyRefreshedDatabase(PDO $pdo, array $plan, array $ids): array
{
    $drinkStatement = $pdo->prepare(
        'SELECT name, manufacturer, origin_location, lifecycle_status FROM drinks WHERE id = :id',
    );
    $testStatement = $pdo->prepare(
        'SELECT id, price_amount, recorded_time, duration_value, stream_reference FROM drink_tests WHERE drink_id = :drink_id ORDER BY id',
    );
    $ratingsStatement = $pdo->prepare(
        'SELECT s.code, r.optik, r.sueffigkeit, r.geschmack FROM ratings r INNER JOIN testers s ON s.id = r.tester_id WHERE r.test_id = :test_id ORDER BY s.code',
    );
    $verifiedTests = 0;
    $verifiedRatings = 0;
    foreach (requiredList($plan, 'primary_records') as $value) {
        if (!is_array($value)) {
            throw new PrimaryRefreshException('A primary verification record is invalid.');
        }
        $record = objectMap($value, 'primary verification record');
        $key = requiredString($record, 'plan_key');
        $drinkStatement->execute(['id' => $ids[$key]]);
        $storedValue = $drinkStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($storedValue)) {
            throw new PrimaryRefreshException("A refreshed drink is missing: $key");
        }
        $stored = objectMap($storedValue, "stored drink $key");
        if (
            ($stored['name'] ?? null) !== requiredString($record, 'name')
            || ($stored['manufacturer'] ?? null) !== optionalString($record, 'manufacturer')
            || ($stored['origin_location'] ?? null) !== optionalString($record, 'origin_location')
            || ($stored['lifecycle_status'] ?? null) !== requiredString($record, 'lifecycle_status')
        ) {
            throw new PrimaryRefreshException("Stored drink values differ from the refresh plan: $key");
        }

        $testStatement->execute(['drink_id' => $ids[$key]]);
        $storedTests = $testStatement->fetchAll(PDO::FETCH_ASSOC);
        $testValue = $record['test'] ?? null;
        if ($testValue === null) {
            if ($storedTests !== []) {
                throw new PrimaryRefreshException("An untested record has stored test data: $key");
            }
            continue;
        }
        if (!is_array($testValue) || count($storedTests) !== 1 || !is_array($storedTests[0])) {
            throw new PrimaryRefreshException("A tested record does not have exactly one stored test: $key");
        }
        $test = objectMap($testValue, "planned test $key");
        $storedTest = objectMap($storedTests[0], "stored test $key");
        $storedTestId = $storedTest['id'] ?? null;
        if (!is_int($storedTestId) && !is_string($storedTestId)) {
            throw new PrimaryRefreshException("A stored test id is invalid: $key");
        }
        $storedPrice = $storedTest['price_amount'] ?? null;
        if (
            !is_string($storedPrice)
            || ExactNumber::from($storedPrice)->compare(ExactNumber::from(requiredString($test, 'price_amount'))) !== 0
            || ($storedTest['recorded_time'] ?? null) !== optionalString($test, 'recorded_time')
            || (($storedTest['duration_value'] ?? null) === null ? null : databaseInt($storedTest, 'duration_value')) !== optionalInt($test, 'duration_value')
            || databaseInt($storedTest, 'stream_reference') !== requiredInt($test, 'stream_reference')
        ) {
            throw new PrimaryRefreshException("Stored test values differ from the refresh plan: $key");
        }
        $ratingsStatement->execute(['test_id' => (int) $storedTestId]);
        $storedRatings = [];
        foreach ($ratingsStatement->fetchAll(PDO::FETCH_ASSOC) as $ratingValue) {
            if (!is_array($ratingValue)) {
                throw new PrimaryRefreshException("A stored rating is invalid: $key");
            }
            $rating = objectMap($ratingValue, "stored rating $key");
            $code = $rating['code'] ?? null;
            if (!is_string($code)) {
                throw new PrimaryRefreshException("A stored tester code is invalid: $key");
            }
            $storedRatings[$code] = [
                'optik' => databaseInt($rating, 'optik'),
                'sueffigkeit' => databaseInt($rating, 'sueffigkeit'),
                'geschmack' => databaseInt($rating, 'geschmack'),
            ];
        }
        $expectedRatings = requiredMap($test, 'ratings');
        ksort($storedRatings);
        ksort($expectedRatings);
        if ($storedRatings !== $expectedRatings) {
            throw new PrimaryRefreshException("Stored ratings differ from the refresh plan: $key");
        }
        ++$verifiedTests;
        $verifiedRatings += count($storedRatings);
    }

    $expectedImages = [];
    foreach (requiredList($plan, 'image_updates') as $value) {
        if (!is_array($value)) {
            throw new PrimaryRefreshException('An image verification record is invalid.');
        }
        $image = objectMap($value, 'image verification record');
        $expectedImages[requiredString($image, 'plan_key')] = $image;
    }
    $imageStatement = $pdo->prepare('SELECT storage_path, mime_type, width, height FROM drink_images WHERE drink_id = :drink_id AND display_order = 0');
    foreach ($expectedImages as $key => $image) {
        $imageStatement->execute(['drink_id' => $ids[$key]]);
        $storedValue = $imageStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($storedValue)) {
            throw new PrimaryRefreshException("A refreshed image row is missing: $key");
        }
        $stored = objectMap($storedValue, "stored image $key");
        if (
            ($stored['storage_path'] ?? null) !== requiredString($image, 'storage_path')
            || ($stored['mime_type'] ?? null) !== requiredString($image, 'mime_type')
            || databaseInt($stored, 'width') !== requiredInt($image, 'width')
            || databaseInt($stored, 'height') !== requiredInt($image, 'height')
        ) {
            throw new PrimaryRefreshException("Stored image values differ from the refresh plan: $key");
        }
    }

    $photoNeeded = [];
    foreach (requiredList($plan, 'photo_needed_plan_keys') as $keyValue) {
        if (!is_string($keyValue)) {
            throw new PrimaryRefreshException('A photo verification key is invalid.');
        }
        $photoNeeded[$keyValue] = true;
    }
    $flagStatement = $pdo->prepare('SELECT needs_new_photo FROM drinks WHERE id = :id');
    foreach ($ids as $key => $drinkId) {
        $flagStatement->execute(['id' => $drinkId]);
        $actual = (int) $flagStatement->fetchColumn();
        $expected = isset($photoNeeded[$key]) ? 1 : 0;
        if ($actual !== $expected) {
            throw new PrimaryRefreshException("Stored photo marker differs from the refresh plan: $key");
        }
    }

    return [
        'drinks' => 166,
        'tests' => $verifiedTests,
        'ratings' => $verifiedRatings,
        'images' => count($expectedImages),
        'photo_needed' => count($photoNeeded),
    ];
}

function queryStatement(PDO $pdo, string $sql): PDOStatement
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new PrimaryRefreshException('A required database query failed.');
    }

    return $statement;
}

function writeBackup(PDO $pdo, string $root, string $workbookHash): string
{
    $directory = $root . '/var/primary-refresh/backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new PrimaryRefreshException('Could not create the private refresh backup directory.');
    }
    $tables = [];
    foreach (['drinks', 'testers', 'drink_tests', 'ratings', 'drink_images', 'legacy_import_runs', 'schema_migrations'] as $table) {
        $tables[$table] = queryStatement($pdo, "SELECT * FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    $payload = json_encode([
        'created_at' => gmdate(DATE_ATOM),
        'workbook_sha256' => $workbookHash,
        'tables' => $tables,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $path = $directory . '/' . gmdate('Ymd-His') . '-' . substr($workbookHash, 0, 12) . '.json';
    if (file_put_contents($path, $payload . "\n", LOCK_EX) === false || !chmod($path, 0600)) {
        throw new PrimaryRefreshException('Could not write the private database backup.');
    }

    return $path;
}

/** @param array<string, mixed> $plan */
function publishImages(array $plan, string $root, string $adminStorageRoot): int
{
    $published = 0;
    foreach (requiredList($plan, 'image_updates') as $value) {
        if (!is_array($value)) {
            throw new PrimaryRefreshException('An image publication entry is invalid.');
        }
        $image = objectMap($value, 'image publication entry');
        $sourcePath = resolvePath($root, requiredString($image, 'source_path'));
        $storagePath = requiredString($image, 'storage_path');
        $destinationPath = rtrim($adminStorageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        $destinationDirectory = dirname($destinationPath);
        if (
            !is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0770, true)
            && !is_dir($destinationDirectory)
        ) {
            throw new PrimaryRefreshException('Could not create the private normalized-image directory.');
        }
        if (is_file($destinationPath)) {
            $existingHash = hash_file('sha256', $destinationPath);
            if ($existingHash === false || !hash_equals(requiredString($image, 'source_sha256'), $existingHash)) {
                throw new PrimaryRefreshException('An existing published image does not match the verified source.');
            }
            ++$published;
            continue;
        }
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'xb');
        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            throw new PrimaryRefreshException('Could not publish a normalized image.');
        }
        $copied = stream_copy_to_stream($source, $destination);
        fclose($source);
        fclose($destination);
        if ($copied === false || !chmod($destinationPath, 0640)) {
            if (is_file($destinationPath)) {
                unlink($destinationPath);
            }
            throw new PrimaryRefreshException('Could not securely publish a normalized image.');
        }
        $publishedHash = hash_file('sha256', $destinationPath);
        if ($publishedHash === false || !hash_equals(requiredString($image, 'source_sha256'), $publishedHash)) {
            unlink($destinationPath);
            throw new PrimaryRefreshException('A published image failed its content-hash check.');
        }
        ++$published;
    }

    return $published;
}

try {
    $rootValue = require dirname(__DIR__, 2) . '/config/environment.php';
    if (!is_string($rootValue) || $rootValue === '') {
        throw new PrimaryRefreshException('The project root could not be resolved.');
    }
    $root = $rootValue;
    $command = $argv[1] ?? '';
    if (!in_array($command, ['verify', 'apply'], true)) {
        throw new PrimaryRefreshException('Usage: php tools/primary-refresh/apply.php verify|apply [PLAN]');
    }
    $environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
    if (!is_string($environment) || !in_array(strtolower($environment), ['local', 'development', 'testing'], true)) {
        throw new PrimaryRefreshException('Primary refresh is allowed only in local, development, or testing environments.');
    }
    $planPath = $argv[2] ?? $root . '/var/primary-refresh/current/refresh-plan.json';
    if (!str_starts_with($planPath, DIRECTORY_SEPARATOR)) {
        $planPath = $root . DIRECTORY_SEPARATOR . $planPath;
    }
    $contents = file_get_contents($planPath);
    $decoded = is_string($contents) ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new PrimaryRefreshException('Could not read the primary refresh plan.');
    }
    $plan = objectMap($decoded, 'refresh plan');
    $verification = verifyPlan($plan, $root);
    $databaseConfiguration = DatabaseConfiguration::fromEnvironment();
    if (preg_match('/prod(?:uction)?/i', $databaseConfiguration->databaseName()) === 1) {
        throw new PrimaryRefreshException('Refusing to refresh a database whose name looks like production.');
    }
    $pdo = (new ConnectionFactory($databaseConfiguration))->create();
    $migration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = :version');
    $migration->execute(['version' => '20260906000000_add_needs_new_photo_flag']);
    if ((int) $migration->fetchColumn() !== 1) {
        throw new PrimaryRefreshException('Apply the needs_new_photo migration before refreshing data.');
    }
    $ids = mapDatabaseRecords($pdo, $plan);
    $before = databaseCounts($pdo);
    if (!in_array($before['drink_tests'], [108, 125], true) || !in_array($before['ratings'], [324, 375], true) || $before['drink_images'] !== 195) {
        throw new PrimaryRefreshException('The database counts do not match the reviewed pre/post-refresh states.');
    }
    if ($command === 'verify') {
        $stored = $before['drink_tests'] === 125
            ? verifyRefreshedDatabase($pdo, $plan, $ids)
            : null;
        fwrite(STDOUT, json_encode(['stage' => 'verify', 'verification' => $verification, 'database' => $before, 'stored_data' => $stored], JSON_PRETTY_PRINT) . "\n");
        exit(0);
    }

    $workbook = requiredMap($plan, 'workbook');
    $adminConfiguration = AdminConfiguration::fromEnvironment(AppConfiguration::fromEnvironment(), $root);
    $publishedImages = publishImages($plan, $root, $adminConfiguration->imageStorageRoot());
    $backup = writeBackup($pdo, $root, requiredString($workbook, 'sha256'));
    $testerIds = [];
    foreach (queryStatement($pdo, 'SELECT code, id FROM testers')->fetchAll(PDO::FETCH_ASSOC) as $tester) {
        if (!is_array($tester)) {
            throw new PrimaryRefreshException('A canonical tester row is invalid.');
        }
        $tester = objectMap($tester, 'canonical tester row');
        $code = $tester['code'] ?? null;
        $testerId = $tester['id'] ?? null;
        if (!is_string($code) || (!is_int($testerId) && !is_string($testerId))) {
            throw new PrimaryRefreshException('A canonical tester row is invalid.');
        }
        $testerIds[$code] = (int) $testerId;
    }
    ksort($testerIds);
    if (array_keys($testerIds) !== ['fabi', 'manu', 'schorsch']) {
        throw new PrimaryRefreshException('The canonical tester rows are unavailable.');
    }

    $updateDrink = $pdo->prepare('UPDATE drinks SET name = :name, manufacturer = :manufacturer, origin_location = :location, lifecycle_status = :status WHERE id = :id');
    $findTest = $pdo->prepare('SELECT id FROM drink_tests WHERE drink_id = :drink_id ORDER BY id');
    $insertTest = $pdo->prepare("INSERT INTO drink_tests (drink_id, status, price_amount, recorded_time, duration_value, stream_reference, completed_at) VALUES (:drink_id, 'completed', :price, :recorded_time, :duration, :stream, NULL)");
    $updateTest = $pdo->prepare("UPDATE drink_tests SET status = 'completed', price_amount = :price, recorded_time = :recorded_time, duration_value = :duration, stream_reference = :stream WHERE id = :id");
    $deleteRatings = $pdo->prepare('DELETE FROM ratings WHERE test_id = :test_id');
    $insertRating = $pdo->prepare('INSERT INTO ratings (test_id, tester_id, optik, sueffigkeit, geschmack) VALUES (:test_id, :tester_id, :optik, :sueffigkeit, :geschmack)');
    $updateImage = $pdo->prepare('UPDATE drink_images SET storage_path = :path, mime_type = :mime, width = :width, height = :height WHERE drink_id = :drink_id AND display_order = 0');
    $setPhotoNeeded = $pdo->prepare('UPDATE drinks SET needs_new_photo = 1 WHERE id = :id');

    $pdo->beginTransaction();
    try {
        foreach (requiredList($plan, 'primary_records') as $value) {
            if (!is_array($value)) {
                throw new PrimaryRefreshException('A primary record is invalid during apply.');
            }
            $value = objectMap($value, 'primary apply record');
            $key = requiredString($value, 'plan_key');
            $drinkId = $ids[$key];
            $updateDrink->execute([
                'name' => requiredString($value, 'name'),
                'manufacturer' => optionalString($value, 'manufacturer'),
                'location' => optionalString($value, 'origin_location'),
                'status' => requiredString($value, 'lifecycle_status'),
                'id' => $drinkId,
            ]);
            $test = $value['test'] ?? null;
            if (!is_array($test)) {
                continue;
            }
            $test = objectMap($test, "apply test $key");
            $findTest->execute(['drink_id' => $drinkId]);
            $testIds = $findTest->fetchAll(PDO::FETCH_COLUMN);
            if (count($testIds) > 1) {
                throw new PrimaryRefreshException("More than one test exists for $key.");
            }
            $testParameters = [
                'price' => requiredString($test, 'price_amount'),
                'recorded_time' => optionalString($test, 'recorded_time'),
                'duration' => optionalInt($test, 'duration_value'),
                'stream' => requiredInt($test, 'stream_reference'),
            ];
            if ($testIds === []) {
                $insertTest->execute(['drink_id' => $drinkId, ...$testParameters]);
                $testId = (int) $pdo->lastInsertId();
            } else {
                $existingTestId = $testIds[0];
                if (!is_int($existingTestId) && !is_string($existingTestId)) {
                    throw new PrimaryRefreshException("A stored test id is invalid for $key.");
                }
                $testId = (int) $existingTestId;
                $updateTest->execute(['id' => $testId, ...$testParameters]);
            }
            $deleteRatings->execute(['test_id' => $testId]);
            foreach (requiredMap($test, 'ratings') as $tester => $ratingValue) {
                if (!is_array($ratingValue) || !isset($testerIds[$tester])) {
                    throw new PrimaryRefreshException("Invalid tester rating for $key.");
                }
                $ratingValue = objectMap($ratingValue, "rating $key/$tester");
                $insertRating->execute([
                    'test_id' => $testId,
                    'tester_id' => $testerIds[$tester],
                    'optik' => requiredInt($ratingValue, 'optik'),
                    'sueffigkeit' => requiredInt($ratingValue, 'sueffigkeit'),
                    'geschmack' => requiredInt($ratingValue, 'geschmack'),
                ]);
            }
        }

        foreach (requiredList($plan, 'image_updates') as $value) {
            if (!is_array($value)) {
                throw new PrimaryRefreshException('An image update is invalid during apply.');
            }
            $value = objectMap($value, 'image apply update');
            $updateImage->execute([
                'path' => requiredString($value, 'storage_path'),
                'mime' => requiredString($value, 'mime_type'),
                'width' => requiredInt($value, 'width'),
                'height' => requiredInt($value, 'height'),
                'drink_id' => $ids[requiredString($value, 'plan_key')],
            ]);
            if ($updateImage->rowCount() !== 1) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM drink_images WHERE drink_id = :drink_id AND display_order = 0 AND storage_path = :path');
                $check->execute(['drink_id' => $ids[requiredString($value, 'plan_key')], 'path' => requiredString($value, 'storage_path')]);
                if ((int) $check->fetchColumn() !== 1) {
                    throw new PrimaryRefreshException('A primary image row could not be updated.');
                }
            }
        }
        $pdo->exec('UPDATE drinks SET needs_new_photo = 0');
        foreach (requiredList($plan, 'photo_needed_plan_keys') as $keyValue) {
            if (!is_string($keyValue) || !isset($ids[$keyValue])) {
                throw new PrimaryRefreshException('A photo follow-up key is invalid.');
            }
            $setPhotoNeeded->execute(['id' => $ids[$keyValue]]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $after = databaseCounts($pdo);
    if ($after !== ['drinks' => 196, 'drink_tests' => 125, 'ratings' => 375, 'drink_images' => 195]) {
        throw new PrimaryRefreshException('Post-refresh database counts are unexpected; use the backup before further work.');
    }
    $storedData = verifyRefreshedDatabase($pdo, $plan, $ids);
    $lifecycle = queryStatement($pdo, 'SELECT lifecycle_status, COUNT(*) total FROM drinks GROUP BY lifecycle_status ORDER BY lifecycle_status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $photoNeeded = (int) queryStatement($pdo, 'SELECT COUNT(*) FROM drinks WHERE needs_new_photo = 1')->fetchColumn();
    fwrite(STDOUT, json_encode([
        'stage' => 'apply',
        'verification' => $verification,
        'published_images' => $publishedImages,
        'backup' => $backup,
        'before' => $before,
        'after' => $after,
        'stored_data' => $storedData,
        'lifecycle' => $lifecycle,
        'photo_needed' => $photoNeeded,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
} catch (PrimaryRefreshException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Primary refresh failed without exposing internal details.\n");
    exit(1);
}
