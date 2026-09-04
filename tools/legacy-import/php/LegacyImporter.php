<?php

declare(strict_types=1);

namespace Spezitest\LegacyImport;

use JsonException;
use PDO;
use Spezitest\Domain\Rating\CompetitionRanking;
use Spezitest\Domain\Rating\ExactNumber;
use Spezitest\Domain\Rating\PricePerformanceCalculator;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterCode;
use Spezitest\Domain\Rating\TesterRating;
use Throwable;

final class LegacyImporter
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed> $verification
     * @return array<string, mixed>
     */
    public function apply(
        LegacyImportPlan $plan,
        array $verification,
        string $storageRoot,
        string $projectRoot,
    ): array {
        if (!$plan->applyReady()) {
            throw new LegacyImportException(
                'Import apply refused: unresolved duplicate decisions: ' . implode(', ', $plan->unresolvedReviewIds()),
            );
        }

        $this->assertEmptyDomain();
        $testerIds = $this->testerIds();
        $storageRoot = $this->prepareStorageRoot($storageRoot, $projectRoot);
        $legacyRoot = $storageRoot . DIRECTORY_SEPARATOR . 'legacy';
        if (!is_dir($legacyRoot) && !mkdir($legacyRoot, 0770, true) && !is_dir($legacyRoot)) {
            throw new LegacyImportException('Could not create the configured legacy image directory.');
        }
        $finalDirectory = $legacyRoot . DIRECTORY_SEPARATOR . $plan->runId();
        if (file_exists($finalDirectory)) {
            throw new LegacyImportException('Import image destination already exists for this run.');
        }
        $temporaryDirectory = $storageRoot . DIRECTORY_SEPARATOR . '.legacy-import-' . $plan->runId() . '-' . bin2hex(random_bytes(6));
        if (!mkdir($temporaryDirectory, 0770, false)) {
            throw new LegacyImportException('Could not create temporary legacy image storage.');
        }

        $movedToFinal = false;
        try {
            $this->stageImages($plan, $temporaryDirectory);
            $this->connection->beginTransaction();
            $counts = $this->insertPlan($plan, $testerIds);
            if (!rename($temporaryDirectory, $finalDirectory)) {
                throw new LegacyImportException('Could not publish the verified legacy image directory.');
            }
            $movedToFinal = true;
            $this->recordRun($plan, $counts, $verification);
            $this->connection->commit();

            return [
                ...$counts,
                'run_recorded' => true,
                'image_storage_relative_root' => 'legacy/' . $plan->runId(),
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            if (is_dir($temporaryDirectory)) {
                $this->removeGeneratedDirectory($temporaryDirectory);
            }
            if ($movedToFinal && is_dir($finalDirectory)) {
                $this->removeGeneratedDirectory($finalDirectory);
            }
            if ($exception instanceof LegacyImportException) {
                throw $exception;
            }
            throw new LegacyImportException('Legacy import apply failed and database changes were rolled back.', 0, $exception);
        }
    }

    private function assertEmptyDomain(): void
    {
        foreach (['drinks', 'drink_tests', 'ratings', 'drink_images', 'legacy_import_runs'] as $table) {
            $statement = $this->connection->query('SELECT COUNT(*) FROM ' . $table);
            if ($statement === false || (int) $statement->fetchColumn() !== 0) {
                throw new LegacyImportException('Import apply requires empty domain/import tables; found data in ' . $table . '.');
            }
        }
    }

    /** @return array<string, int> */
    private function testerIds(): array
    {
        $statement = $this->connection->query('SELECT id, code FROM testers ORDER BY code');
        if ($statement === false) {
            throw new LegacyImportException('Could not inspect canonical testers.');
        }
        $ids = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row) || !is_string($row['code'] ?? null)) {
                throw new LegacyImportException('Canonical tester data is invalid.');
            }
            $id = $row['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
                throw new LegacyImportException('Canonical tester ID is invalid.');
            }
            $ids[$row['code']] = (int) $id;
        }
        if (array_keys($ids) !== ['fabi', 'manu', 'schorsch']) {
            throw new LegacyImportException('Exactly the three canonical testers must exist before import.');
        }

        return $ids;
    }

    private function prepareStorageRoot(string $storageRoot, string $projectRoot): string
    {
        if ($storageRoot === '') {
            throw new LegacyImportException('LEGACY_IMAGE_STORAGE_ROOT must not be empty.');
        }
        if (!str_starts_with($storageRoot, DIRECTORY_SEPARATOR)) {
            $storageRoot = $projectRoot . DIRECTORY_SEPARATOR . $storageRoot;
        }
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
            throw new LegacyImportException('Could not create LEGACY_IMAGE_STORAGE_ROOT.');
        }
        $resolvedStorage = realpath($storageRoot);
        $resolvedPublic = realpath($projectRoot . DIRECTORY_SEPARATOR . 'public');
        if ($resolvedStorage === false || $resolvedPublic === false) {
            throw new LegacyImportException('Could not resolve configured storage paths.');
        }
        if ($resolvedStorage === $resolvedPublic || str_starts_with($resolvedStorage, $resolvedPublic . DIRECTORY_SEPARATOR)) {
            throw new LegacyImportException('Legacy images must be stored outside the public document root.');
        }

        return $resolvedStorage;
    }

    private function stageImages(LegacyImportPlan $plan, string $temporaryDirectory): void
    {
        $seen = [];
        foreach ($plan->drinks() as $drink) {
            foreach ($plan->requiredList($drink, 'images') as $imageValue) {
                if (!is_array($imageValue)) {
                    throw new LegacyImportException('Planned image must be an object.');
                }
                /** @var array<string, mixed> $image */
                $image = $imageValue;
                $relativePath = $plan->requiredString($image, 'staged_path');
                $filename = basename($relativePath);
                if (isset($seen[$filename])) {
                    continue;
                }
                $seen[$filename] = true;
                $source = $plan->directory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $destination = $temporaryDirectory . DIRECTORY_SEPARATOR . $filename;
                if (!copy($source, $destination)) {
                    throw new LegacyImportException('Could not stage verified legacy image: ' . $filename);
                }
                $actualHash = hash_file('sha256', $destination);
                if ($actualHash === false || !hash_equals($plan->requiredString($image, 'sha256'), $actualHash)) {
                    throw new LegacyImportException('Copied legacy image failed its content-hash check.');
                }
            }
        }
    }

    /**
     * @param array<string, int> $testerIds
     * @return array<string, int>
     */
    private function insertPlan(LegacyImportPlan $plan, array $testerIds): array
    {
        $drinkStatement = $this->connection->prepare(
            'INSERT INTO drinks (name, lifecycle_status, manufacturer, origin_location, origin_region, notes) VALUES (:name, :status, :manufacturer, :location, :region, :notes)',
        );
        $testStatement = $this->connection->prepare(
            "INSERT INTO drink_tests (drink_id, status, price_amount, recorded_time, duration_value, stream_reference) VALUES (:drink_id, 'completed', :price, :recorded_time, :duration_value, :stream_reference)",
        );
        $ratingStatement = $this->connection->prepare(
            'INSERT INTO ratings (test_id, tester_id, optik, sueffigkeit, geschmack) VALUES (:test_id, :tester_id, :optik, :sueffigkeit, :geschmack)',
        );
        $imageStatement = $this->connection->prepare(
            'INSERT INTO drink_images (drink_id, storage_path, mime_type, width, height, display_order) VALUES (:drink_id, :storage_path, :mime_type, :width, :height, :display_order)',
        );
        $counts = ['drinks' => 0, 'tests' => 0, 'ratings' => 0, 'images' => 0, 'prices' => 0];
        $testIds = [];

        foreach ($plan->drinks() as $drink) {
            $drinkStatement->execute([
                'name' => $plan->requiredString($drink, 'name'),
                'status' => $plan->requiredString($drink, 'lifecycle_status'),
                'manufacturer' => $plan->optionalString($drink, 'manufacturer'),
                'location' => $plan->optionalString($drink, 'origin_location'),
                'region' => $plan->optionalString($drink, 'origin_region'),
                'notes' => $plan->optionalString($drink, 'notes'),
            ]);
            $drinkId = (int) $this->connection->lastInsertId();
            ++$counts['drinks'];

            foreach ($plan->requiredList($drink, 'tests') as $testValue) {
                if (!is_array($testValue)) {
                    throw new LegacyImportException('Planned test must be an object.');
                }
                /** @var array<string, mixed> $test */
                $test = $testValue;
                $price = $plan->optionalString($test, 'price_amount');
                $testStatement->execute([
                    'drink_id' => $drinkId,
                    'price' => $price,
                    'recorded_time' => $plan->optionalString($test, 'recorded_time'),
                    'duration_value' => $test['duration_value'] ?? null,
                    'stream_reference' => $test['stream_reference'] ?? null,
                ]);
                $testId = (int) $this->connection->lastInsertId();
                $testIds[$plan->requiredString($test, 'source')] = $testId;
                ++$counts['tests'];
                if ($price !== null) {
                    ++$counts['prices'];
                }
                $ratings = $plan->requiredMap($test, 'ratings');
                foreach ($testerIds as $code => $testerId) {
                    $rating = $plan->requiredMap($ratings, $code);
                    $ratingStatement->execute([
                        'test_id' => $testId,
                        'tester_id' => $testerId,
                        'optik' => $plan->requiredString($rating, 'optik'),
                        'sueffigkeit' => $plan->requiredString($rating, 'sueffigkeit'),
                        'geschmack' => $plan->requiredString($rating, 'geschmack'),
                    ]);
                    ++$counts['ratings'];
                }
            }

            $displayOrder = 0;
            foreach ($plan->requiredList($drink, 'images') as $imageValue) {
                if (!is_array($imageValue)) {
                    throw new LegacyImportException('Planned image must be an object.');
                }
                /** @var array<string, mixed> $image */
                $image = $imageValue;
                $filename = basename($plan->requiredString($image, 'staged_path'));
                $imageStatement->execute([
                    'drink_id' => $drinkId,
                    'storage_path' => 'legacy/' . $plan->runId() . '/' . $filename,
                    'mime_type' => $plan->requiredString($image, 'mime_type'),
                    'width' => $plan->requiredInt($image, 'width'),
                    'height' => $plan->requiredInt($image, 'height'),
                    'display_order' => $displayOrder++,
                ]);
                ++$counts['images'];
            }
        }

        $postInsert = $this->verifyStoredTests($plan, $testIds);

        return [...$counts, ...$postInsert];
    }

    /**
     * @param array<string, int> $testIds
     * @return array<string, int>
     */
    private function verifyStoredTests(LegacyImportPlan $plan, array $testIds): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT t.price_amount, s.code, r.optik, r.sueffigkeit, r.geschmack
                FROM drink_tests t
                JOIN ratings r ON r.test_id = t.id
                JOIN testers s ON s.id = r.tester_id
                WHERE t.id = :test_id
                ORDER BY s.code
                SQL,
        );
        $scores = [];
        $prices = [];
        $expectedRanks = [];
        $calculator = new RatingCalculator();

        foreach ($plan->drinks() as $drink) {
            foreach ($plan->requiredList($drink, 'tests') as $testValue) {
                if (!is_array($testValue)) {
                    throw new LegacyImportException('Planned test must be an object.');
                }
                /** @var array<string, mixed> $test */
                $test = $testValue;
                $source = $plan->requiredString($test, 'source');
                $testId = $testIds[$source] ?? null;
                if ($testId === null) {
                    throw new LegacyImportException('Could not locate inserted test for post-import verification.');
                }
                $statement->execute(['test_id' => $testId]);
                $ratings = [];
                $storedPrice = null;
                while (($row = $statement->fetch()) !== false) {
                    if (!is_array($row)) {
                        throw new LegacyImportException('Stored rating query returned invalid data.');
                    }
                    $code = $row['code'] ?? null;
                    $tester = match ($code) {
                        'manu' => TesterCode::Manu,
                        'fabi' => TesterCode::Fabi,
                        'schorsch' => TesterCode::Schorsch,
                        default => throw new LegacyImportException('Stored rating has an unknown tester.'),
                    };
                    foreach (['optik', 'sueffigkeit', 'geschmack'] as $field) {
                        if (!is_string($row[$field] ?? null)) {
                            throw new LegacyImportException('Stored rating value is not a decimal string.');
                        }
                    }
                    $ratings[] = new TesterRating($tester, $row['optik'], $row['sueffigkeit'], $row['geschmack']);
                    $storedPrice = is_string($row['price_amount'] ?? null) ? $row['price_amount'] : null;
                }
                $result = $calculator->calculate($ratings);
                $historical = $plan->requiredMap($test, 'historical');
                $expectedGesamt = (float) $plan->requiredString($historical, 'gesamt');
                if ($result === null || abs($result->gesamt() - $expectedGesamt) > 1.0E-12) {
                    throw new LegacyImportException('Post-import stored rating mismatch for ' . $source . '.');
                }
                $scores[$source] = $result->gesamt();
                $expectedRanks[$source] = $plan->requiredInt($historical, 'rank');
                if ($storedPrice !== null && ExactNumber::from($storedPrice)->isPositive()) {
                    $prices[$source] = $storedPrice;
                }
            }
        }

        $ranks = (new CompetitionRanking())->rank($scores);
        foreach ($expectedRanks as $source => $expectedRank) {
            if (($ranks[$source] ?? null) !== $expectedRank) {
                throw new LegacyImportException('Post-import stored rank mismatch for ' . $source . '.');
            }
        }

        $intermediates = [];
        foreach ($prices as $source => $price) {
            $intermediates[$source] = ExactNumber::from($scores[$source])->divideBy(ExactNumber::from($price))->toFloat();
        }
        $priceVerified = 0;
        $priceCalculator = new PricePerformanceCalculator();
        foreach ($prices as $source => $price) {
            if ($priceCalculator->calculate($scores[$source], $price, $intermediates) === null) {
                throw new LegacyImportException('Post-import dynamic price/performance unavailable for ' . $source . '.');
            }
            ++$priceVerified;
        }

        return [
            'post_insert_ratings_verified' => count($scores),
            'post_insert_ranks_verified' => count($ranks),
            'post_insert_price_performance_verified' => $priceVerified,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, mixed> $verification
     */
    private function recordRun(LegacyImportPlan $plan, array $counts, array $verification): void
    {
        try {
            $summary = json_encode(['counts' => $counts, 'verification' => $verification], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new LegacyImportException('Could not encode the legacy import-run summary.', 0, $exception);
        }
        $statement = $this->connection->prepare(
            'INSERT INTO legacy_import_runs (run_id, plan_sha256, primaerliste_sha256, beschaffungsliste_sha256, summary_json) VALUES (:run_id, :plan_sha256, :prima_hash, :beschaffung_hash, :summary_json)',
        );
        $statement->execute([
            'run_id' => $plan->runId(),
            'plan_sha256' => $plan->sha256(),
            'prima_hash' => $plan->requiredString($plan->source('primaerliste'), 'sha256_before'),
            'beschaffung_hash' => $plan->requiredString($plan->source('beschaffungsliste'), 'sha256_before'),
            'summary_json' => $summary,
        ]);
    }

    private function removeGeneratedDirectory(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
