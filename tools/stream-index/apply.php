<?php

declare(strict_types=1);

/**
 * Stream index: write the reviewed plan from `tools/stream-index/plan.php` to a
 * non-production database.
 *
 * Usage:  php tools/stream-index/apply.php verify|apply [PLAN]
 *
 * `verify` reports what would change and touches nothing. `apply` writes the
 * episode rows and the per-test segment offsets in one transaction. Both refuse
 * to run outside a local/development/testing environment, and refuse a database
 * whose name looks like production.
 *
 * The plan is rebuilt, not trusted: this tool re-runs the same matching over
 * the same reviewed source file, so an edited plan file cannot smuggle in an
 * assignment the matcher would not make.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/config/environment.php';

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;

$command = $argv[1] ?? '';

if (!in_array($command, ['verify', 'apply'], true)) {
    fwrite(STDERR, "Usage: php tools/stream-index/apply.php verify|apply [PLAN]\n");
    exit(1);
}

$environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

if (!is_string($environment) || !in_array(strtolower($environment), ['local', 'development', 'testing'], true)) {
    fwrite(STDERR, "The stream index import is allowed only in local, development, or testing environments.\n");
    exit(1);
}

$configuration = DatabaseConfiguration::fromEnvironment();

if (preg_match('/prod(?:uction)?/i', $configuration->databaseName()) === 1) {
    fwrite(STDERR, "Refusing to write to a database whose name looks like production.\n");
    exit(1);
}

$planPath = $argv[2] ?? 'var/stream-index-plan.json';

if (!str_starts_with($planPath, DIRECTORY_SEPARATOR) && !preg_match('/\A[A-Za-z]:/', $planPath)) {
    $planPath = $root . '/' . $planPath;
}

if (!is_file($planPath)) {
    fwrite(STDERR, "Plan not found: $planPath\nRun: php tools/stream-index/plan.php --json=var/stream-index-plan.json\n");
    exit(1);
}

$contents = file_get_contents($planPath);
$plan = is_string($contents) ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null;

if (!is_array($plan) || !isset($plan['streams'], $plan['assignments'])) {
    fwrite(STDERR, "The plan file is not a stream index plan.\n");
    exit(1);
}

/**
 * Re-derive the plan from the reviewed source so an edited JSON file cannot
 * introduce an assignment the matcher itself would not produce.
 */
$rebuilt = [];
$output = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/plan.php')
    . ' --json=' . escapeshellarg('var/stream-index-plan.rebuilt.json') . ' 2>&1', $output, $status);

if ($status !== 0) {
    fwrite(STDERR, "The plan does not match the source cleanly; resolve it before applying.\n");
    fwrite(STDERR, implode("\n", array_slice($output, -20)) . "\n");
    exit(1);
}

$rebuiltRaw = file_get_contents($root . '/var/stream-index-plan.rebuilt.json');
$rebuilt = is_string($rebuiltRaw) ? json_decode($rebuiltRaw, true, 512, JSON_THROW_ON_ERROR) : null;

if ($rebuilt !== $plan) {
    fwrite(STDERR, "The plan file differs from the plan rebuilt from the source. Re-run plan.php.\n");
    exit(1);
}

$pdo = (new ConnectionFactory($configuration))->create();

$runMigration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = :version');
$runMigration->execute(['version' => '20260906140000_create_test_runs']);

if ((int) $runMigration->fetchColumn() !== 1) {
    fwrite(STDERR, "Apply the test_runs migration before importing the stream index.\n");
    exit(1);
}

$existing = [];

foreach ($pdo->query('SELECT drink_id, id, stream_reference, recorded_time, duration_value FROM drink_tests') as $row) {
    $existing[(int) $row['drink_id']] = [
        'test_id' => (int) $row['id'],
        'stream' => $row['stream_reference'] === null ? null : (int) $row['stream_reference'],
        'time' => $row['recorded_time'],
        'duration' => $row['duration_value'] === null ? null : (int) $row['duration_value'],
    ];
}

$changes = ['runs' => 0, 'offsets' => 0, 'durations' => 0, 'streams' => 0, 'missing' => []];

foreach ($plan['assignments'] as $assignment) {
    $drinkId = (int) $assignment['drink_id'];
    $current = $existing[$drinkId] ?? null;

    if ($current === null) {
        $changes['missing'][] = $drinkId . ' ' . $assignment['name'];

        continue;
    }

    if ($current['time'] !== $assignment['offset']) {
        ++$changes['offsets'];
    }

    if (($current['duration'] ?? null) !== ($assignment['duration'] ?? null)) {
        ++$changes['durations'];
    }

    if ($current['stream'] !== (int) $assignment['stream']) {
        ++$changes['streams'];
    }
}

printf(
    "%s: %d episodes, %d assignments — %d offsets, %d durations, %d stream changes, %d without a test\n",
    $command,
    count($plan['streams']),
    count($plan['assignments']),
    $changes['offsets'],
    $changes['durations'],
    $changes['streams'],
    count($changes['missing']),
);

foreach ($changes['missing'] as $missing) {
    echo "  no test row: $missing\n";
}

if ($command === 'verify') {
    exit(0);
}

$pdo->beginTransaction();

try {
    $upsertRun = $pdo->prepare(
        <<<'SQL'
            INSERT INTO test_runs (number, title, recorded_on, stream_url, status, completed_at)
            VALUES (:number, :title, :recorded_on, :stream_url, 'completed', CURRENT_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                recorded_on = COALESCE(VALUES(recorded_on), test_runs.recorded_on),
                stream_url = VALUES(stream_url)
            SQL,
    );

    foreach ($plan['streams'] as $stream) {
        $upsertRun->execute([
            'number' => (int) $stream['number'],
            'title' => $stream['title'] ?? ('Testabend #' . (int) $stream['number']),
            'recorded_on' => $stream['date'] ?? null,
            'stream_url' => (string) $stream['url'],
        ]);
        ++$changes['runs'];
    }

    $updateTest = $pdo->prepare(
        'UPDATE drink_tests SET stream_reference = :stream, recorded_time = :offset, '
        . 'duration_value = :duration WHERE id = :id',
    );

    foreach ($plan['assignments'] as $assignment) {
        $current = $existing[(int) $assignment['drink_id']] ?? null;

        if ($current === null) {
            continue;
        }

        $updateTest->execute([
            'stream' => (int) $assignment['stream'],
            'offset' => (string) $assignment['offset'],
            'duration' => $assignment['duration'] ?? null,
            'id' => $current['test_id'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}

printf("applied: %d episodes, %d assignments\n", $changes['runs'], count($plan['assignments']));
