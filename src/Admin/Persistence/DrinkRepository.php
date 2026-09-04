<?php

declare(strict_types=1);

namespace Spezitest\Admin\Persistence;

use PDO;
use RuntimeException;
use Spezitest\Admin\Image\StoredImage;
use Spezitest\Admin\Validation\DrinkInput;

final readonly class DrinkRepository
{
    public function __construct(private PDO $connection)
    {
    }

    /** @return array{identified: int, acquired: int, tested: int} */
    public function lifecycleCounts(): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT lifecycle_status, COUNT(*) AS total
                FROM drinks
                GROUP BY lifecycle_status
                SQL,
        );
        $statement->execute();
        $counts = ['identified' => 0, 'acquired' => 0, 'tested' => 0];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The lifecycle count query returned invalid data.');
            }

            $status = $row['lifecycle_status'] ?? null;
            $total = $row['total'] ?? null;

            if (is_string($status) && (is_int($total) || is_string($total)) && array_key_exists($status, $counts)) {
                $counts[$status] = (int) $total;
            }
        }

        return $counts;
    }

    /**
     * @return list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, has_primary_image: bool}>
     */
    public function search(string $search, ?string $status): array
    {
        $where = [];
        $parameters = [];

        if ($search !== '') {
            $where[] = '(d.name LIKE :search_name OR d.manufacturer LIKE :search_manufacturer)';
            $parameters['search_name'] = '%' . $search . '%';
            $parameters['search_manufacturer'] = '%' . $search . '%';
        }

        if ($status !== null) {
            $where[] = 'd.lifecycle_status = :status';
            $parameters['status'] = $status;
        }

        $sql = <<<'SQL'
            SELECT
                d.id,
                d.name,
                d.lifecycle_status,
                d.manufacturer,
                CASE WHEN di.id IS NULL THEN 0 ELSE 1 END AS has_primary_image
            FROM drinks d
            LEFT JOIN drink_images di
                ON di.drink_id = d.id
               AND di.display_order = 0
            SQL;

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY d.name, d.id LIMIT 500';
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $rows = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('The drink search returned invalid data.');
            }

            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            $lifecycleStatus = $row['lifecycle_status'] ?? null;
            $manufacturer = $row['manufacturer'] ?? null;
            $hasPrimaryImage = $row['has_primary_image'] ?? null;

            if (
                (!is_int($id) && !is_string($id))
                || !is_string($name)
                || !is_string($lifecycleStatus)
                || ($manufacturer !== null && !is_string($manufacturer))
                || (!is_int($hasPrimaryImage) && !is_string($hasPrimaryImage))
            ) {
                throw new RuntimeException('The drink search returned invalid data.');
            }

            $rows[] = [
                'id' => (int) $id,
                'name' => $name,
                'lifecycle_status' => $lifecycleStatus,
                'manufacturer' => $manufacturer,
                'has_primary_image' => (int) $hasPrimaryImage === 1,
            ];
        }

        return $rows;
    }

    /**
     * @return array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string}|null
     */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $sql = <<<'SQL'
            SELECT id, name, lifecycle_status, manufacturer, origin_location, origin_region, notes
            FROM drinks
            WHERE id = :id
            SQL;

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new RuntimeException('The drink query returned invalid data.');
        }

        $idValue = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $status = $row['lifecycle_status'] ?? null;

        if ((!is_int($idValue) && !is_string($idValue)) || !is_string($name) || !is_string($status)) {
            throw new RuntimeException('The drink query returned invalid data.');
        }

        return [
            'id' => (int) $idValue,
            'name' => $name,
            'lifecycle_status' => $status,
            'manufacturer' => $this->nullableString($row, 'manufacturer'),
            'origin_location' => $this->nullableString($row, 'origin_location'),
            'origin_region' => $this->nullableString($row, 'origin_region'),
            'notes' => $this->nullableString($row, 'notes'),
        ];
    }

    public function create(DrinkInput $input): int
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO drinks (
                    name, lifecycle_status, manufacturer, origin_location, origin_region, notes
                ) VALUES (
                    :name, :lifecycle_status, :manufacturer, :origin_location, :origin_region, :notes
                )
                SQL,
        );
        $statement->execute($this->parameters($input));

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, DrinkInput $input): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                UPDATE drinks
                SET name = :name,
                    lifecycle_status = :lifecycle_status,
                    manufacturer = :manufacturer,
                    origin_location = :origin_location,
                    origin_region = :origin_region,
                    notes = :notes
                WHERE id = :id
                SQL,
        );
        $parameters = $this->parameters($input);
        $parameters['id'] = $id;
        $statement->execute($parameters);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE drinks SET lifecycle_status = :status WHERE id = :id',
        );
        $statement->execute(['status' => $status, 'id' => $id]);

        return $statement->rowCount() === 1 || $this->find($id) !== null;
    }

    public function hasCompletedTest(int $drinkId): bool
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT 1
                FROM drink_tests
                WHERE drink_id = :drink_id
                  AND status = 'completed'
                LIMIT 1
                SQL,
        );
        $statement->execute(['drink_id' => $drinkId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{id: int, storage_path: string, mime_type: string, width: int, height: int}|null */
    public function primaryImage(int $drinkId, bool $forUpdate = false): ?array
    {
        $sql = <<<'SQL'
            SELECT id, storage_path, mime_type, width, height
            FROM drink_images
            WHERE drink_id = :drink_id
            ORDER BY display_order, id
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
            throw new RuntimeException('The image query returned invalid data.');
        }

        $id = $row['id'] ?? null;
        $storagePath = $row['storage_path'] ?? null;
        $mimeType = $row['mime_type'] ?? null;
        $width = $row['width'] ?? null;
        $height = $row['height'] ?? null;

        if (
            (!is_int($id) && !is_string($id))
            || !is_string($storagePath)
            || !is_string($mimeType)
            || (!is_int($width) && !is_string($width))
            || (!is_int($height) && !is_string($height))
        ) {
            throw new RuntimeException('The image query returned invalid data.');
        }

        return [
            'id' => (int) $id,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'width' => (int) $width,
            'height' => (int) $height,
        ];
    }

    /** @return list<string> */
    public function imagePaths(int $drinkId): array
    {
        $statement = $this->connection->prepare(
            'SELECT storage_path FROM drink_images WHERE drink_id = :drink_id ORDER BY id',
        );
        $statement->execute(['drink_id' => $drinkId]);
        $paths = [];

        while (($path = $statement->fetchColumn()) !== false) {
            $paths[] = (string) $path;
        }

        return $paths;
    }

    public function replacePrimaryImage(int $drinkId, StoredImage $image): void
    {
        $this->deleteImages($drinkId);
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO drink_images (
                    drink_id, storage_path, mime_type, width, height, display_order
                ) VALUES (
                    :drink_id, :storage_path, :mime_type, :width, :height, 0
                )
                SQL,
        );
        $statement->execute([
            'drink_id' => $drinkId,
            'storage_path' => $image->relativePath,
            'mime_type' => $image->mimeType,
            'width' => $image->width,
            'height' => $image->height,
        ]);
    }

    public function deleteImages(int $drinkId): void
    {
        $statement = $this->connection->prepare('DELETE FROM drink_images WHERE drink_id = :drink_id');
        $statement->execute(['drink_id' => $drinkId]);
    }

    public function delete(int $drinkId): bool
    {
        $statement = $this->connection->prepare('DELETE FROM drinks WHERE id = :id');
        $statement->execute(['id' => $drinkId]);

        return $statement->rowCount() === 1;
    }

    /** @return array{name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} */
    private function parameters(DrinkInput $input): array
    {
        return [
            'name' => $input->name,
            'lifecycle_status' => $input->lifecycleStatus,
            'manufacturer' => $input->manufacturer,
            'origin_location' => $input->originLocation,
            'origin_region' => $input->originRegion,
            'notes' => $input->notes,
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('A database query returned invalid text data.');
        }

        return $value;
    }
}
