<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

use PDO;
use RuntimeException;
use Spezitest\Domain\Rating\CompetitionRanking;
use Spezitest\Domain\Rating\ExactNumber;
use Spezitest\Domain\Rating\PriceNormalizer;
use Spezitest\Domain\Rating\PricePerformanceCalculator;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\RatingResult;
use Spezitest\Domain\Rating\TesterRatingFactory;

/**
 * Read-only catalog gateway for the public website.
 *
 * A single {@see self::ratedDrinks()} call loads every drink, every completed
 * test and its raw grades with three prepared statements, then derives all
 * rating figures through the verified engine:
 *
 *  - {@see RatingCalculator} for category averages and Gesamt;
 *  - {@see CompetitionRanking} over the full set of completed official results;
 *  - {@see PricePerformanceCalculator} normalised across all eligible
 *    completed/tested drinks with a valid positive price.
 *
 * Nothing derived is read from or written to the database.
 */
final readonly class CatalogRepository
{
    public function __construct(
        private PDO $connection,
        private RatingCalculator $calculator = new RatingCalculator(),
        private CompetitionRanking $ranking = new CompetitionRanking(),
        private PricePerformanceCalculator $pricePerformance = new PricePerformanceCalculator(),
        private PriceNormalizer $priceNormalizer = new PriceNormalizer(),
    ) {
    }

    public function ratedDrinks(): RatedDrinkCollection
    {
        $drinkRows = $this->drinkRows();
        $completedTests = $this->completedTestRows();
        $gradesByTest = $this->gradeRowsByTest();

        /** @var array<int, RatingResult> $resultsByDrink */
        $resultsByDrink = [];
        /** @var array<int, array{notes: ?string, tested_at: ?string, grades: array<string, array{optik: string, sueffigkeit: string, geschmack: string}>, stream: ?StreamSegment}> $testMetaByDrink */
        $testMetaByDrink = [];

        foreach ($completedTests as $test) {
            $grades = $gradesByTest[$test['id']] ?? [];
            $result = $this->calculator->calculate(TesterRatingFactory::fromMap($grades));

            $testMetaByDrink[$test['drink_id']] = [
                'notes' => $test['notes'],
                'tested_at' => $test['completed_at'],
                'grades' => $grades,
                'stream' => $test['stream_reference'] === null
                    ? null
                    : new StreamSegment(
                        $test['stream_reference'],
                        $test['stream_title'],
                        $test['stream_url'],
                        $this->offsetSeconds($test['recorded_time']),
                        $test['duration_value'],
                    ),
            ];

            if ($result !== null) {
                $resultsByDrink[$test['drink_id']] = $result;
            }
        }

        $ranks = $this->ranking->rank(array_map(
            static fn (RatingResult $result): float => $result->gesamt(),
            $resultsByDrink,
        ));

        $normalizedPriceByDrink = $this->normalizedPricesByDrink($drinkRows);
        $population = $this->pricePerformancePopulation($resultsByDrink, $normalizedPriceByDrink);

        $drinks = [];

        foreach ($drinkRows as $row) {
            $id = $row['id'];
            $result = $resultsByDrink[$id] ?? null;
            $meta = $testMetaByDrink[$id] ?? null;
            $normalizedPrice = $normalizedPriceByDrink[$id] ?? null;

            $pricePerformance = null;

            if ($result !== null && $normalizedPrice !== null) {
                $pricePerformance = $this->pricePerformance->calculate($result->gesamt(), $normalizedPrice, $population);
            }

            $drinks[] = new RatedDrink(
                $id,
                $row['name'],
                $row['manufacturer'],
                $row['origin_location'],
                $row['origin_region'],
                $row['notes'],
                $row['lifecycle_status'],
                $row['has_image'],
                $row['updated_at'],
                $result,
                $result !== null ? ($ranks[$id] ?? null) : null,
                $row['price_amount'],
                $row['price_volume_ml'],
                $meta['notes'] ?? null,
                $meta['tested_at'] ?? null,
                $pricePerformance,
                $meta['grades'] ?? [],
                $meta['stream'] ?? null,
            );
        }

        return new RatedDrinkCollection($drinks);
    }

    /**
     * The application's Preis/Leistung basis is €-per-0.5 L, derived from the
     * drink's own raw entered price and container volume (see
     * {@see PriceNormalizer}). This only decides what "price" is fed into the
     * unmodified {@see PricePerformanceCalculator} formula below.
     *
     * @param list<array{id: int, price_amount: ?string, price_volume_ml: ?int}> $drinkRows
     * @return array<int, float>
     */
    private function normalizedPricesByDrink(array $drinkRows): array
    {
        $prices = [];

        foreach ($drinkRows as $row) {
            if ($row['price_amount'] === null || $row['price_volume_ml'] === null) {
                continue;
            }

            if (!ExactNumber::from($row['price_amount'])->isPositive()) {
                continue;
            }

            $prices[$row['id']] = $this->priceNormalizer->perReferenceVolume(
                $row['price_amount'],
                $row['price_volume_ml'],
            );
        }

        return $prices;
    }

    /**
     * @param array<int, RatingResult> $resultsByDrink
     * @param array<int, float> $normalizedPriceByDrink
     * @return list<float>
     */
    private function pricePerformancePopulation(array $resultsByDrink, array $normalizedPriceByDrink): array
    {
        $population = [];

        foreach ($resultsByDrink as $drinkId => $result) {
            $price = $normalizedPriceByDrink[$drinkId] ?? null;

            if ($price === null) {
                continue;
            }

            $population[] = $result->exactGesamt()->divideBy(ExactNumber::from($price))->toFloat();
        }

        return $population;
    }

    /**
     * @return list<array{id: int, name: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, lifecycle_status: string, has_image: bool, updated_at: string, price_amount: ?string, price_volume_ml: ?int}>
     */
    private function drinkRows(): array
    {
        $statement = $this->connection->query(
            <<<'SQL'
                SELECT
                    d.id,
                    d.name,
                    d.manufacturer,
                    d.origin_location,
                    d.origin_region,
                    d.notes,
                    d.lifecycle_status,
                    d.updated_at,
                    d.price_amount,
                    d.price_volume_ml,
                    CASE WHEN di.id IS NULL THEN 0 ELSE 1 END AS has_image
                FROM drinks d
                LEFT JOIN drink_images di ON di.drink_id = d.id AND di.display_order = 0
                ORDER BY d.name, d.id
                SQL,
        );

        if ($statement === false) {
            throw new RuntimeException('The catalog query failed.');
        }

        $rows = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The catalog query returned invalid data.');
            }

            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            $status = $row['lifecycle_status'] ?? null;
            $updatedAt = $row['updated_at'] ?? null;
            $hasImage = $row['has_image'] ?? null;

            if (
                (!is_int($id) && !is_string($id))
                || !is_string($name)
                || !is_string($status)
                || !is_string($updatedAt)
                || (!is_int($hasImage) && !is_string($hasImage))
            ) {
                throw new RuntimeException('The catalog query returned invalid data.');
            }

            $rows[] = [
                'id' => (int) $id,
                'name' => $name,
                'manufacturer' => $this->nullableString($row, 'manufacturer'),
                'origin_location' => $this->nullableString($row, 'origin_location'),
                'origin_region' => $this->nullableString($row, 'origin_region'),
                'notes' => $this->nullableString($row, 'notes'),
                'lifecycle_status' => $status,
                'has_image' => (int) $hasImage === 1,
                'updated_at' => $updatedAt,
                'price_amount' => $this->nullablePositiveDecimal($row, 'price_amount'),
                'price_volume_ml' => $this->nullableInt($row, 'price_volume_ml'),
            ];
        }

        return $rows;
    }

    /** A stored `HH:MM:SS` offset as whole seconds, for a `?t=…s` deep link. */
    private function offsetSeconds(?string $recordedTime): ?int
    {
        if ($recordedTime === null || $recordedTime === '') {
            return null;
        }

        $parts = explode(':', $recordedTime);

        if (count($parts) !== 3) {
            return null;
        }

        return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
    }

    /**
     * A whole-second column. MariaDB hands an INT back as an int through this
     * driver, so it must not go through the text reader.
     *
     * @param array<array-key, mixed> $row
     */
    private function nullableSeconds(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('A catalog query returned invalid numeric data.');
        }

        return (int) $value;
    }

    /**
     * @return list<array{id: int, drink_id: int, notes: ?string, completed_at: ?string, stream_reference: ?int, recorded_time: ?string, duration_value: ?int, stream_title: ?string, stream_url: ?string}>
     */
    private function completedTestRows(): array
    {
        $statement = $this->connection->query(
            <<<'SQL'
                SELECT t.id, t.drink_id, t.notes, t.completed_at,
                       t.stream_reference, t.recorded_time, t.duration_value,
                       r.title AS stream_title, r.stream_url
                FROM drink_tests t
                LEFT JOIN test_runs r ON r.number = t.stream_reference
                WHERE t.status = 'completed'
                ORDER BY t.drink_id, t.id DESC
                SQL,
        );

        if ($statement === false) {
            throw new RuntimeException('The completed-test query failed.');
        }

        $rows = [];
        $seenDrink = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The completed-test query returned invalid data.');
            }

            $id = $row['id'] ?? null;
            $drinkId = $row['drink_id'] ?? null;

            if ((!is_int($id) && !is_string($id)) || (!is_int($drinkId) && !is_string($drinkId))) {
                throw new RuntimeException('The completed-test query returned invalid data.');
            }

            $drinkId = (int) $drinkId;

            if (isset($seenDrink[$drinkId])) {
                continue;
            }

            $seenDrink[$drinkId] = true;
            $streamReference = $row['stream_reference'] ?? null;
            $rows[] = [
                'id' => (int) $id,
                'drink_id' => $drinkId,
                'notes' => $this->nullableString($row, 'notes'),
                'completed_at' => $this->nullableString($row, 'completed_at'),
                'stream_reference' => is_int($streamReference) || is_string($streamReference)
                    ? (int) $streamReference
                    : null,
                'recorded_time' => $this->nullableString($row, 'recorded_time'),
                'duration_value' => $this->nullableSeconds($row, 'duration_value'),
                'stream_title' => $this->nullableString($row, 'stream_title'),
                'stream_url' => $this->nullableString($row, 'stream_url'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, array{optik: string, sueffigkeit: string, geschmack: string}>>
     */
    private function gradeRowsByTest(): array
    {
        $statement = $this->connection->query(
            <<<'SQL'
                SELECT r.test_id, t.code, r.optik, r.sueffigkeit, r.geschmack
                FROM ratings r
                INNER JOIN testers t ON t.id = r.tester_id
                INNER JOIN drink_tests dt ON dt.id = r.test_id
                WHERE dt.status = 'completed'
                SQL,
        );

        if ($statement === false) {
            throw new RuntimeException('The ratings query failed.');
        }

        $grades = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The ratings query returned invalid data.');
            }

            $testId = $row['test_id'] ?? null;
            $code = $row['code'] ?? null;

            if ((!is_int($testId) && !is_string($testId)) || !is_string($code)) {
                throw new RuntimeException('The ratings query returned invalid data.');
            }

            $grades[(int) $testId][$code] = [
                'optik' => $this->decimalString($row, 'optik'),
                'sueffigkeit' => $this->decimalString($row, 'sueffigkeit'),
                'geschmack' => $this->decimalString($row, 'geschmack'),
            ];
        }

        return $grades;
    }

    /** @param array<array-key, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException('A catalog query returned invalid text data.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function nullablePositiveDecimal(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || preg_match('/\A\d+(?:\.\d+)?\z/D', $value) !== 1) {
            return null;
        }

        return ExactNumber::from($value)->isPositive() ? $value : null;
    }

    /** @param array<array-key, mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('A catalog query returned invalid numeric data.');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $row */
    private function decimalString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            throw new RuntimeException('A ratings query returned invalid numeric data.');
        }

        return $value;
    }
}
