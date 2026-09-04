<?php

declare(strict_types=1);

namespace Spezitest\Admin\Persistence;

use PDO;
use RuntimeException;

/**
 * Native PDO persistence for drink tests and their raw tester ratings.
 *
 * The application treats a drink as having at most one current test. Category
 * averages, Gesamt and rank are never stored here — only the nine raw grades,
 * the optional test price and the optional note.
 */
final readonly class TestRepository
{
    public function __construct(private PDO $connection)
    {
    }

    /**
     * @return array{id: int, drink_id: int, status: string, price_amount: ?string, notes: ?string, completed_at: ?string}|null
     */
    public function currentTest(int $drinkId, bool $forUpdate = false): ?array
    {
        $sql = <<<'SQL'
            SELECT id, drink_id, status, price_amount, notes, completed_at
            FROM drink_tests
            WHERE drink_id = :drink_id
            ORDER BY (status = 'completed') DESC, id DESC
            LIMIT 1
            SQL;

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute(['drink_id' => $drinkId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new RuntimeException('The test query returned invalid data.');
        }

        $id = $row['id'] ?? null;
        $drink = $row['drink_id'] ?? null;
        $status = $row['status'] ?? null;

        if ((!is_int($id) && !is_string($id)) || (!is_int($drink) && !is_string($drink)) || !is_string($status)) {
            throw new RuntimeException('The test query returned invalid data.');
        }

        return [
            'id' => (int) $id,
            'drink_id' => (int) $drink,
            'status' => $status,
            'price_amount' => $this->nullableString($row, 'price_amount'),
            'notes' => $this->nullableString($row, 'notes'),
            'completed_at' => $this->nullableString($row, 'completed_at'),
        ];
    }

    public function createDraft(int $drinkId): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO drink_tests (drink_id, status) VALUES (:drink_id, 'draft')",
        );
        $statement->execute(['drink_id' => $drinkId]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, array{optik: string, sueffigkeit: string, geschmack: string}>
     *         Keyed by tester code.
     */
    public function ratingsByTesterCode(int $testId): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT t.code, r.optik, r.sueffigkeit, r.geschmack
                FROM ratings r
                INNER JOIN testers t ON t.id = r.tester_id
                WHERE r.test_id = :test_id
                SQL,
        );
        $statement->execute(['test_id' => $testId]);
        $ratings = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The ratings query returned invalid data.');
            }

            $code = $row['code'] ?? null;
            $optik = $row['optik'] ?? null;
            $sueffigkeit = $row['sueffigkeit'] ?? null;
            $geschmack = $row['geschmack'] ?? null;

            if (
                !is_string($code)
                || (!is_string($optik) && !is_int($optik) && !is_float($optik))
                || (!is_string($sueffigkeit) && !is_int($sueffigkeit) && !is_float($sueffigkeit))
                || (!is_string($geschmack) && !is_int($geschmack) && !is_float($geschmack))
            ) {
                throw new RuntimeException('The ratings query returned invalid data.');
            }

            $ratings[$code] = [
                'optik' => (string) $optik,
                'sueffigkeit' => (string) $sueffigkeit,
                'geschmack' => (string) $geschmack,
            ];
        }

        return $ratings;
    }

    /**
     * @return array<string, int> Map of tester code to tester id.
     */
    public function testerIdsByCode(): array
    {
        $statement = $this->connection->query('SELECT code, id FROM testers');

        if ($statement === false) {
            throw new RuntimeException('The tester lookup failed.');
        }

        $map = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The tester lookup returned invalid data.');
            }

            $code = $row['code'] ?? null;
            $id = $row['id'] ?? null;

            if (!is_string($code) || (!is_int($id) && !is_string($id))) {
                throw new RuntimeException('The tester lookup returned invalid data.');
            }

            $map[$code] = (int) $id;
        }

        return $map;
    }

    public function deleteRatings(int $testId): void
    {
        $statement = $this->connection->prepare('DELETE FROM ratings WHERE test_id = :test_id');
        $statement->execute(['test_id' => $testId]);
    }

    public function insertRating(int $testId, int $testerId, int $optik, int $sueffigkeit, int $geschmack): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO ratings (test_id, tester_id, optik, sueffigkeit, geschmack)
                VALUES (:test_id, :tester_id, :optik, :sueffigkeit, :geschmack)
                SQL,
        );
        $statement->execute([
            'test_id' => $testId,
            'tester_id' => $testerId,
            'optik' => $optik,
            'sueffigkeit' => $sueffigkeit,
            'geschmack' => $geschmack,
        ]);
    }

    public function updateDraftDetails(int $testId, ?string $priceAmount, ?string $notes): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                UPDATE drink_tests
                SET price_amount = :price_amount, notes = :notes
                WHERE id = :id AND status = 'draft'
                SQL,
        );
        $statement->execute([
            'price_amount' => $priceAmount,
            'notes' => $notes,
            'id' => $testId,
        ]);
    }

    public function markCompleted(int $testId, ?string $priceAmount, ?string $notes): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                UPDATE drink_tests
                SET status = 'completed',
                    completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP(6)),
                    price_amount = :price_amount,
                    notes = :notes
                WHERE id = :id
                SQL,
        );
        $statement->execute([
            'price_amount' => $priceAmount,
            'notes' => $notes,
            'id' => $testId,
        ]);
    }

    /** @param array<array-key, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            throw new RuntimeException('A test query returned invalid text data.');
        }

        return $value;
    }
}
