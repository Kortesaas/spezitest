<?php

declare(strict_types=1);

namespace Spezitest\Admin;

use PDO;
use Psr\Http\Message\UploadedFileInterface;
use Spezitest\Admin\Image\ImageStorage;
use Spezitest\Admin\Image\UploadedImageValidator;
use Spezitest\Admin\Persistence\TestRunRepository;
use Spezitest\Admin\Testing\TestRun;
use Spezitest\Admin\Validation\ValidationException;
use Throwable;

/** Coordinates run planning and private wheel artwork with transaction safety. */
final readonly class TestRunService
{
    public function __construct(
        private PDO $connection,
        private TestRunRepository $runs,
        private UploadedImageValidator $images,
        private ImageStorage $storage,
    ) {
    }

    /** @param list<int> $drinkIds */
    public function start(int $number, array $drinkIds): void
    {
        $this->transactional(function () use ($number, $drinkIds): void {
            $open = $this->runs->openRun();

            if ($open !== null && $open->number !== $number) {
                throw new ValidationException(
                    'Spezistream #' . $open->number . ' läuft noch. Bitte zuerst abschließen.',
                );
            }

            $existing = $this->runs->find($number);

            if ($existing !== null && !$existing->isOpen()) {
                throw new ValidationException('Diese Spezistream-Nummer ist bereits abgeschlossen.');
            }

            if ($existing === null && $number !== $this->runs->nextNumber()) {
                throw new ValidationException('Bitte den vorgeschlagenen nächsten Spezistream starten.');
            }

            $this->runs->open($number);
            $this->runs->addToLineup($number, $drinkIds);
        });
    }

    /** @param list<int> $drinkIds */
    public function addDrinks(int $number, array $drinkIds): void
    {
        $this->transactional(function () use ($number, $drinkIds): void {
            $this->requireOpen($number);
            $this->runs->addToLineup($number, $drinkIds);
        });
    }

    public function removeDrink(int $number, int $drinkId): void
    {
        $this->transactional(function () use ($number, $drinkId): void {
            $this->requireOpen($number);

            if (!$this->runs->removeFromLineup($number, $drinkId)) {
                throw new ValidationException('Diese Spezi ist nicht für den Spezistream ausgewählt.');
            }
        });
    }

    public function replaceWheelImage(int $number, ?UploadedFileInterface $upload): void
    {
        $run = $this->requireOpen($number);
        $validated = $this->images->validate($upload);

        if ($validated === null) {
            throw new ValidationException('Bitte ein Bild auswählen.');
        }

        $stored = $this->storage->storeWheel($validated);

        try {
            $this->transactional(fn () => $this->runs->saveWheelImage(
                $number,
                $stored->relativePath,
                $stored->mimeType,
            ));
        } catch (Throwable $exception) {
            $this->storage->delete($stored->relativePath);
            throw $exception;
        }

        if ($run->wheelImagePath !== null) {
            $this->storage->delete($run->wheelImagePath);
        }
    }

    public function removeWheelImage(int $number): void
    {
        $run = $this->requireOpen($number);
        $this->transactional(fn () => $this->runs->saveWheelImage($number, null, null));

        if ($run->wheelImagePath !== null) {
            $this->storage->delete($run->wheelImagePath);
        }
    }

    private function requireOpen(int $number): TestRun
    {
        $run = $this->runs->find($number);

        if ($run === null) {
            throw new ValidationException('Der Spezistream wurde nicht gefunden.');
        }

        if (!$run->isOpen()) {
            throw new ValidationException('Ein abgeschlossener Spezistream kann nicht mehr geplant werden.');
        }

        return $run;
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
