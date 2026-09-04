<?php

declare(strict_types=1);

namespace Spezitest\Admin;

use PDO;
use Spezitest\Admin\Persistence\DrinkRepository;
use Spezitest\Admin\Persistence\TestRepository;
use Spezitest\Admin\Testing\TestEntryInput;
use Spezitest\Admin\Validation\ValidationException;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterRatingFactory;
use Throwable;

/**
 * Coordinates test drafting and completion in single database transactions.
 *
 * Completing a test uses the verified rating engine only: the nine raw grades
 * are stored, {@see RatingCalculator} is asked for an official result, and the
 * drink transitions to `tested` in the same transaction. An incomplete rating
 * set can never complete a test. Category averages and Gesamt are derived on
 * read, never written.
 */
final readonly class TestService
{
    public function __construct(
        private PDO $connection,
        private DrinkRepository $drinks,
        private TestRepository $tests,
        private RatingCalculator $calculator = new RatingCalculator(),
    ) {
    }

    /**
     * Persist a partial or full set of grades without changing the drink
     * lifecycle. Returns the test id.
     */
    public function saveDraft(int $drinkId, TestEntryInput $input): int
    {
        return $this->transactional(function () use ($drinkId, $input): int {
            $drink = $this->requireTestableDrink($drinkId);
            $test = $this->tests->currentTest($drinkId, true);

            if ($test !== null && $test['status'] === 'completed') {
                throw new ValidationException(
                    'Dieser Test ist bereits abgeschlossen. Bitte im Testformular alle neun Noten speichern.',
                );
            }

            $testId = $test['id'] ?? $this->tests->createDraft($drinkId);
            $this->replaceRatings($testId, $input);
            $this->tests->updateDraftDetails($testId, $input->priceAmount, $input->notes);

            unset($drink);

            return $testId;
        });
    }

    /**
     * Store all nine grades, compute the official result and move the drink to
     * `tested`.
     */
    public function complete(int $drinkId, TestEntryInput $input): void
    {
        if (!$input->isComplete()) {
            throw new ValidationException('Der Test kann erst abgeschlossen werden, wenn alle neun Noten gesetzt sind.');
        }

        $this->transactional(function () use ($drinkId, $input): void {
            $drink = $this->requireTestableDrink($drinkId);
            $test = $this->tests->currentTest($drinkId, true);
            $testId = $test['id'] ?? $this->tests->createDraft($drinkId);

            $this->replaceRatings($testId, $input);

            $result = $this->calculator->calculate(
                TesterRatingFactory::fromMap($this->tests->ratingsByTesterCode($testId)),
            );

            if ($result === null) {
                throw new ValidationException('Es liegen nicht für alle drei Tester vollständige Noten vor.');
            }

            $this->tests->markCompleted($testId, $input->priceAmount, $input->notes);

            if ($drink['lifecycle_status'] !== 'tested') {
                $this->drinks->updateStatus($drinkId, 'tested');
            }
        });
    }

    private function replaceRatings(int $testId, TestEntryInput $input): void
    {
        $testerIds = $this->tests->testerIdsByCode();
        $this->tests->deleteRatings($testId);

        foreach ($input->ratings as $code => $grades) {
            $testerId = $testerIds[$code] ?? null;

            if ($testerId === null) {
                throw new ValidationException('Ein Tester-Datensatz fehlt in der Datenbank.');
            }

            $this->tests->insertRating(
                $testId,
                $testerId,
                $grades['optik'],
                $grades['sueffigkeit'],
                $grades['geschmack'],
            );
        }
    }

    /**
     * @return array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string}
     */
    private function requireTestableDrink(int $drinkId): array
    {
        $drink = $this->drinks->find($drinkId, true);

        if ($drink === null) {
            throw new ValidationException('Das Getränk wurde nicht gefunden.');
        }

        if ($drink['lifecycle_status'] === 'identified') {
            throw new ValidationException(
                'Bitte das Getränk zuerst auf „Erworben“ setzen, bevor ein Test erfasst wird.',
            );
        }

        return $drink;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        try {
            $this->connection->beginTransaction();
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
