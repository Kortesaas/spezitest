<?php

declare(strict_types=1);

namespace Spezitest\Admin;

use PDO;
use Spezitest\Admin\Persistence\DrinkRepository;
use Spezitest\Admin\Persistence\TestRepository;
use Spezitest\Admin\Persistence\TestRunRepository;
use Spezitest\Admin\Testing\TestEntryInput;
use Spezitest\Admin\Testing\TestStreamPosition;
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
        private TestRunRepository $runs,
        private RatingCalculator $calculator = new RatingCalculator(),
    ) {
    }

    /**
     * Persist a partial or full set of grades without changing the drink
     * lifecycle. Returns the test id.
     */
    public function saveDraft(int $drinkId, TestEntryInput $input, ?TestStreamPosition $position = null): int
    {
        return $this->transactional(function () use ($drinkId, $input, $position): int {
            $drink = $this->requireTestableDrink($drinkId);
            $test = $this->tests->currentTest($drinkId, true);

            if ($test !== null && $test['status'] === 'completed') {
                throw new ValidationException(
                    'Dieser Test ist bereits abgeschlossen. Bitte im Testformular alle neun Noten speichern.',
                );
            }

            $testId = $test['id'] ?? $this->tests->createDraft($drinkId);
            $this->replaceRatings($testId, $input);
            $this->tests->updateDraftDetails($testId, $input->notes);
            $this->applyStreamPosition($drinkId, $testId, $test, $position);

            unset($drink);

            return $testId;
        });
    }

    /**
     * Store all nine grades, compute the official result and move the drink to
     * `tested`.
     */
    public function complete(int $drinkId, TestEntryInput $input, ?TestStreamPosition $position = null): void
    {
        if (!$input->isComplete()) {
            throw new ValidationException('Der Test kann erst abgeschlossen werden, wenn alle neun Noten gesetzt sind.');
        }

        $this->transactional(function () use ($drinkId, $input, $position): void {
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

            $this->tests->markCompleted($testId, $input->notes);
            $this->applyStreamPosition($drinkId, $testId, $test, $position);

            if ($drink['lifecycle_status'] !== 'tested') {
                $this->drinks->updateStatus($drinkId, 'tested');
            }
        });
    }

    /**
     * File the test under a Spezistream and record where its segment sits in the
     * recording.
     *
     * With no explicit position from the form, a test that has no Spezistream yet
     * joins the evening currently in progress — that is the whole point of
     * having one open: whatever is tasted tonight belongs to tonight. A test
     * that already carries a number keeps it, so re-saving an old test never
     * moves it into the current evening.
     *
     * @param array{stream_reference?: ?int}|null $existing
     */
    private function applyStreamPosition(
        int $drinkId,
        int $testId,
        ?array $existing,
        ?TestStreamPosition $position,
    ): void
    {
        if ($position !== null) {
            $this->tests->updateStreamPosition(
                $testId,
                $position->runNumber,
                $position->offset,
                $position->duration,
            );

            if ($position->runNumber !== null && $this->runs->find($position->runNumber)?->isOpen() === true) {
                $this->runs->ensureSelected($position->runNumber, $drinkId);
            }

            return;
        }

        if (($existing['stream_reference'] ?? null) !== null) {
            return;
        }

        $open = $this->runs->openRun();

        if ($open === null) {
            return;
        }

        $this->tests->updateStreamPosition($testId, $open->number, null, null);
        $this->runs->ensureSelected($open->number, $drinkId);
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
     * @return array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, needs_new_photo: bool}
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
