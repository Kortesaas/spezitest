<?php

declare(strict_types=1);

namespace Spezitest\Admin;

use PDO;
use PDOException;
use Psr\Http\Message\UploadedFileInterface;
use Spezitest\Admin\Image\ImageStorage;
use Spezitest\Admin\Image\UploadedImageValidator;
use Spezitest\Admin\Persistence\DrinkRepository;
use Spezitest\Admin\Validation\DrinkInput;
use Spezitest\Admin\Validation\ValidationException;
use Throwable;

final readonly class DrinkService
{
    public function __construct(
        private PDO $connection,
        private DrinkRepository $repository,
        private UploadedImageValidator $imageValidator,
        private ImageStorage $imageStorage,
    ) {
    }

    public function create(DrinkInput $input, ?UploadedFileInterface $upload): int
    {
        $validatedImage = $this->imageValidator->validate($upload);
        $storedImage = $validatedImage === null ? null : $this->imageStorage->store($validatedImage);

        try {
            $this->connection->beginTransaction();
            $drinkId = $this->repository->create($input);

            if ($storedImage !== null) {
                $this->repository->replacePrimaryImage($drinkId, $storedImage);
            }

            $this->connection->commit();

            return $drinkId;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            if ($storedImage !== null) {
                $this->imageStorage->delete($storedImage->relativePath);
            }

            throw $exception;
        }
    }

    public function update(
        int $drinkId,
        DrinkInput $input,
        ?UploadedFileInterface $upload,
        bool $removeImage,
    ): bool {
        $validatedImage = $this->imageValidator->validate($upload);
        $storedImage = $validatedImage === null ? null : $this->imageStorage->store($validatedImage);
        $oldImagePath = null;

        try {
            $this->connection->beginTransaction();

            $currentDrink = $this->repository->find($drinkId, true);

            if ($currentDrink === null) {
                $this->connection->rollBack();

                if ($storedImage !== null) {
                    $this->imageStorage->delete($storedImage->relativePath);
                }

                return false;
            }

            if (
                $input->lifecycleStatus === 'tested'
                && $currentDrink['lifecycle_status'] !== 'tested'
                && !$this->repository->hasCompletedTest($drinkId)
            ) {
                throw new ValidationException('Getestet setzt einen abgeschlossenen Test voraus.');
            }

            $oldImage = $this->repository->primaryImage($drinkId, true);
            $this->repository->update($drinkId, $input);

            if ($storedImage !== null) {
                $this->repository->replacePrimaryImage($drinkId, $storedImage);
                $oldImagePath = $oldImage['storage_path'] ?? null;
            } elseif ($removeImage && $oldImage !== null) {
                $this->repository->deleteImages($drinkId);
                $oldImagePath = $oldImage['storage_path'];
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            if ($storedImage !== null) {
                $this->imageStorage->delete($storedImage->relativePath);
            }

            throw $exception;
        }

        if (is_string($oldImagePath) && !$this->imageStorage->delete($oldImagePath)) {
            error_log('Admin image cleanup could not remove a replaced file.');
        }

        return true;
    }

    public function changeStatus(int $drinkId, string $status): bool
    {
        try {
            $this->connection->beginTransaction();
            $drink = $this->repository->find($drinkId, true);

            if ($drink === null) {
                $this->connection->rollBack();

                return false;
            }

            if (
                $status === 'tested'
                && $drink['lifecycle_status'] !== 'tested'
                && !$this->repository->hasCompletedTest($drinkId)
            ) {
                throw new ValidationException('Getestet setzt einen abgeschlossenen Test voraus.');
            }

            $updated = $this->repository->updateStatus($drinkId, $status);
            $this->connection->commit();

            return $updated;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function delete(int $drinkId): bool
    {
        $paths = [];

        try {
            $this->connection->beginTransaction();

            if ($this->repository->find($drinkId, true) === null) {
                $this->connection->rollBack();

                return false;
            }

            $paths = $this->repository->imagePaths($drinkId);
            $this->repository->deleteImages($drinkId);

            if (!$this->repository->delete($drinkId)) {
                $this->connection->rollBack();

                return false;
            }

            $this->connection->commit();
        } catch (PDOException $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            if ((string) $exception->getCode() === '23000') {
                throw new ValidationException('Dieses Getränk hat abhängige Testdaten und kann nicht gelöscht werden.');
            }

            throw $exception;
        }

        foreach ($paths as $path) {
            if (!$this->imageStorage->delete($path)) {
                error_log('Admin image cleanup could not remove a deleted drink image.');
            }
        }

        return true;
    }
}
