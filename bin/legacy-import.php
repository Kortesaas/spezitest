#!/usr/bin/env php
<?php

declare(strict_types=1);

use Spezitest\Database\ConnectionFactory;
use Spezitest\Database\DatabaseConfiguration;
use Spezitest\Database\DatabaseConfigurationException;
use Spezitest\Database\DatabaseConnectionException;
use Spezitest\LegacyImport\LegacyImporter;
use Spezitest\LegacyImport\LegacyImportException;
use Spezitest\LegacyImport\LegacyImportPlan;
use Spezitest\LegacyImport\LegacyImportReportWriter;
use Spezitest\LegacyImport\LegacyImportVerifier;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @param list<string> $arguments */
function legacyImportOption(array $arguments, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $index => $argument) {
        if (str_starts_with($argument, $prefix)) {
            $value = substr($argument, strlen($prefix));

            return $value === '' ? $default : $value;
        }
        if ($argument === '--' . $name) {
            $value = $arguments[$index + 1] ?? null;

            return is_string($value) && !str_starts_with($value, '--') ? $value : $default;
        }
    }

    return $default;
}

try {
    /** @var non-empty-string $rootDirectory */
    $rootDirectory = require dirname(__DIR__) . '/config/environment.php';
    $command = $argv[1] ?? '';
    if (!in_array($command, ['verify', 'apply'], true)) {
        throw new LegacyImportException(
            'Usage: php bin/legacy-import.php verify|apply [--plan=PATH] [--output=PATH] [--storage-root=PATH]',
        );
    }

    /** @var list<string> $arguments */
    $arguments = array_slice($argv, 2);
    $planPath = legacyImportOption(
        $arguments,
        'plan',
        $rootDirectory . '/var/legacy-import-output/current/import-plan.json',
    );
    if ($planPath === null) {
        throw new LegacyImportException('A legacy import plan path is required.');
    }
    if (!str_starts_with($planPath, DIRECTORY_SEPARATOR)) {
        $planPath = $rootDirectory . DIRECTORY_SEPARATOR . $planPath;
    }
    $plan = LegacyImportPlan::load($planPath);
    $verification = (new LegacyImportVerifier())->verify($plan, $rootDirectory);
    $outputDirectory = legacyImportOption($arguments, 'output', $plan->directory());
    if ($outputDirectory === null) {
        throw new LegacyImportException('A report output directory is required.');
    }
    if (!str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)) {
        $outputDirectory = $rootDirectory . DIRECTORY_SEPARATOR . $outputDirectory;
    }
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
        throw new LegacyImportException('Could not create the legacy import report directory.');
    }
    if (!is_writable($outputDirectory)) {
        throw new LegacyImportException('The legacy import report directory is not writable.');
    }

    $application = null;
    if ($command === 'apply') {
        if (!$plan->applyReady()) {
            throw new LegacyImportException(
                'Import apply refused: unresolved duplicate decisions: ' . implode(', ', $plan->unresolvedReviewIds()),
            );
        }
        $environmentName = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
        if (!is_string($environmentName) || !in_array(strtolower($environmentName), ['local', 'development', 'testing'], true)) {
            throw new LegacyImportException('Import apply is allowed only when APP_ENV is explicitly local, development, or testing.');
        }
        $storageRoot = legacyImportOption($arguments, 'storage-root');
        if ($storageRoot === null) {
            $environmentStorage = $_ENV['LEGACY_IMAGE_STORAGE_ROOT'] ?? $_SERVER['LEGACY_IMAGE_STORAGE_ROOT'] ?? getenv('LEGACY_IMAGE_STORAGE_ROOT');
            $storageRoot = is_string($environmentStorage) ? $environmentStorage : null;
        }
        if ($storageRoot === null || trim($storageRoot) === '') {
            throw new LegacyImportException('Apply requires LEGACY_IMAGE_STORAGE_ROOT or --storage-root.');
        }
        $connection = (new ConnectionFactory(DatabaseConfiguration::fromEnvironment()))->create();
        $application = (new LegacyImporter($connection))->apply(
            $plan,
            $verification,
            $storageRoot,
            $rootDirectory,
        );
    }

    (new LegacyImportReportWriter())->write(
        $plan,
        $verification,
        $outputDirectory,
        $command === 'apply' ? 'apply' : 'dry-run',
        $application,
    );

    fwrite(STDOUT, json_encode([
        'stage' => $command,
        'run_id' => $plan->runId(),
        'apply_ready' => $plan->applyReady(),
        'unresolved_review_ids' => $plan->unresolvedReviewIds(),
        'verification' => $verification,
        'application' => $application,
        'reports' => [
            $outputDirectory . DIRECTORY_SEPARATOR . ($command === 'apply' ? 'apply-report.json' : 'dry-run-report.json'),
            $outputDirectory . DIRECTORY_SEPARATOR . ($command === 'apply' ? 'apply-report.md' : 'dry-run-report.md'),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
} catch (LegacyImportException | DatabaseConfigurationException | DatabaseConnectionException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Legacy import command failed without exposing internal details.\n");
    exit(1);
}
