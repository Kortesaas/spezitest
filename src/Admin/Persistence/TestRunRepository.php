<?php

declare(strict_types=1);

namespace Spezitest\Admin\Persistence;

use PDO;
use RuntimeException;
use Spezitest\Admin\Testing\TestRun;

/**
 * Persistence for Spezistreams (livestream episodes) and their per-episode
 * details.
 *
 * A run is identified by its number, which is the same value each test stores
 * in `drink_tests.stream_reference`. Because the historical runs were imported
 * as bare numbers, a run can exist in `drink_tests` without a `test_runs` row;
 * listings therefore merge both sides and mark such runs as lacking details.
 */
final readonly class TestRunRepository
{
    public function __construct(private PDO $connection)
    {
    }

    /**
     * Every known run, newest number first, with the number of tests recorded
     * against it.
     *
     * @return list<TestRun>
     */
    public function all(): array
    {
        $statement = $this->connection->query(
            <<<'SQL'
                SELECT
                    numbers.number,
                    r.title,
                    r.recorded_on,
                    r.stream_url,
                    r.status,
                    r.notes,
                    r.completed_at,
                    r.number AS detail_number,
                    COALESCE(counts.total, 0) AS test_count,
                    COALESCE(counts.timed, 0) AS timed_count
                FROM (
                    SELECT number FROM test_runs
                    UNION
                    SELECT DISTINCT stream_reference FROM drink_tests WHERE stream_reference IS NOT NULL
                ) AS numbers
                LEFT JOIN test_runs r ON r.number = numbers.number
                LEFT JOIN (
                    SELECT stream_reference,
                           COUNT(*) AS total,
                           SUM(recorded_time IS NOT NULL) AS timed
                    FROM drink_tests
                    WHERE stream_reference IS NOT NULL
                    GROUP BY stream_reference
                ) AS counts ON counts.stream_reference = numbers.number
                ORDER BY numbers.number DESC
                SQL,
        );

        if ($statement === false) {
            throw new RuntimeException('The test run query failed.');
        }

        $runs = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The test run query returned invalid data.');
            }

            $runs[] = $this->hydrate($row);
        }

        return $runs;
    }

    public function find(int $number): ?TestRun
    {
        foreach ($this->all() as $run) {
            if ($run->number === $number) {
                return $run;
            }
        }

        return null;
    }

    /**
     * The Spezistream currently in progress. At most one run is open at a time;
     * if several ever were, the highest number wins so the newest evening is
     * the one being recorded into.
     */
    public function openRun(): ?TestRun
    {
        foreach ($this->all() as $run) {
            if ($run->isOpen()) {
                return $run;
            }
        }

        return null;
    }

    /** The number a brand-new Spezistream would get. */
    public function nextNumber(): int
    {
        $highest = 0;

        foreach ($this->all() as $run) {
            $highest = max($highest, $run->number);
        }

        return $highest + 1;
    }

    /**
     * Create the run's detail row, or update it in place. Only the metadata is
     * written here; which tests belong to the run stays in `drink_tests`.
     *
     * A row created by this method is `completed`: documenting an evening is
     * not the same as running one. Starting a Spezistream is the separate,
     * explicit {@see self::open()}, and editing an evening already in progress
     * leaves its status alone.
     */
    public function save(
        int $number,
        ?string $title,
        ?string $recordedOn,
        ?string $streamUrl,
        ?string $notes,
    ): void {
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO test_runs (number, title, recorded_on, stream_url, notes, status, completed_at)
                VALUES (:number, :title, :recorded_on, :stream_url, :notes, 'completed', CURRENT_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    recorded_on = VALUES(recorded_on),
                    stream_url = VALUES(stream_url),
                    notes = VALUES(notes)
                SQL,
        );
        $statement->execute([
            'number' => $number,
            'title' => $title,
            'recorded_on' => $recordedOn,
            'stream_url' => $streamUrl,
            'notes' => $notes,
        ]);
    }

    /** Start a Spezistream: create the row in `open` state if it does not exist. */
    public function open(int $number): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO test_runs (number, status)
                VALUES (:number, 'open')
                ON DUPLICATE KEY UPDATE status = 'open', completed_at = NULL
                SQL,
        );
        $statement->execute(['number' => $number]);
    }

    /**
     * Close a Spezistream. The row is created first when the run only existed as
     * a number on imported tests, so historical evenings can be closed too.
     */
    public function complete(int $number): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO test_runs (number, status, completed_at)
                VALUES (:number, 'completed', CURRENT_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE status = 'completed', completed_at = CURRENT_TIMESTAMP(6)
                SQL,
        );
        $statement->execute(['number' => $number]);
    }

    /**
     * Every test recorded in one run, in the order the segments appear in the
     * stream (tests without a timestamp last, by name).
     *
     * @return list<array{drink_id: int, name: string, manufacturer: ?string, lifecycle_status: string, has_primary_image: bool, status: string, recorded_time: ?string, duration_value: ?int, notes: ?string, completed_at: ?string}>
     */
    public function tests(int $number): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT
                    d.id AS drink_id,
                    d.name,
                    d.manufacturer,
                    d.lifecycle_status,
                    t.status,
                    t.recorded_time,
                    t.duration_value,
                    t.notes,
                    t.completed_at,
                    CASE WHEN di.id IS NULL THEN 0 ELSE 1 END AS has_primary_image
                FROM drink_tests t
                INNER JOIN drinks d ON d.id = t.drink_id
                LEFT JOIN drink_images di ON di.drink_id = d.id AND di.display_order = 0
                WHERE t.stream_reference = :number
                ORDER BY t.recorded_time IS NULL, t.recorded_time, d.name
                SQL,
        );
        $statement->execute(['number' => $number]);
        $rows = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The test run detail query returned invalid data.');
            }

            $rows[] = [
                'drink_id' => $this->intValue($row, 'drink_id'),
                'name' => $this->stringValue($row, 'name'),
                'manufacturer' => $this->nullableString($row, 'manufacturer'),
                'lifecycle_status' => $this->stringValue($row, 'lifecycle_status'),
                'has_primary_image' => $this->intValue($row, 'has_primary_image') === 1,
                'status' => $this->stringValue($row, 'status'),
                'recorded_time' => $this->nullableString($row, 'recorded_time'),
                'duration_value' => $this->nullableInt($row, 'duration_value'),
                'notes' => $this->nullableString($row, 'notes'),
                'completed_at' => $this->nullableString($row, 'completed_at'),
            ];
        }

        return $rows;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): TestRun
    {
        return new TestRun(
            $this->intValue($row, 'number'),
            $this->nullableString($row, 'title'),
            $this->nullableString($row, 'recorded_on'),
            $this->nullableString($row, 'stream_url'),
            $this->nullableString($row, 'status') ?? 'completed',
            $this->nullableString($row, 'notes'),
            $this->nullableString($row, 'completed_at'),
            $this->intValue($row, 'test_count'),
            $this->intValue($row, 'timed_count'),
            ($row['detail_number'] ?? null) !== null,
        );
    }

    /** @param array<array-key, mixed> $row */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('A test run query returned invalid numeric data.');
        }

        return (int) $value;
    }

    /** @param array<array-key, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException('A test run query returned invalid text data.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('A test run query returned invalid text data.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('A test run query returned invalid numeric data.');
        }

        return (int) $value;
    }
}
