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
     * Counts for the data-quality shortcuts on the dashboard: how many drinks
     * still miss a picture, miss a price, or are flagged for a new photo.
     *
     * @return array{no_image: int, no_price: int, needs_photo: int}
     */
    public function qualityCounts(): array
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                SELECT
                    SUM(CASE WHEN di.id IS NULL THEN 1 ELSE 0 END) AS no_image,
                    SUM(CASE WHEN d.price_amount IS NULL OR d.price_volume_ml IS NULL THEN 1 ELSE 0 END) AS no_price,
                    SUM(CASE WHEN d.needs_new_photo = 1 THEN 1 ELSE 0 END) AS needs_photo
                FROM drinks d
                LEFT JOIN drink_images di
                    ON di.drink_id = d.id
                   AND di.display_order = 0
                SQL,
        );
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return ['no_image' => 0, 'no_price' => 0, 'needs_photo' => 0];
        }

        return [
            'no_image' => $this->countValue($row, 'no_image'),
            'no_price' => $this->countValue($row, 'no_price'),
            'needs_photo' => $this->countValue($row, 'needs_photo'),
        ];
    }

    /**
     * A SUM() from an aggregate row. MariaDB returns it as a string (or as NULL
     * for an empty table), so it is validated before it becomes an int.
     *
     * @param array<array-key, mixed> $row
     */
    private function countValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return 0;
        }

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('The drink quality count query returned invalid data.');
        }

        return (int) $value;
    }

    /**
     * The WHERE fragments shared by both listings. `$flag` is one of the
     * validated data-quality filters and never reaches SQL as raw input.
     *
     * @param array<string, string> $parameters
     * @return list<string>
     */
    private function conditions(string $search, ?string $status, string $flag, array &$parameters): array
    {
        $where = [];

        if ($search !== '') {
            $where[] = '(d.name LIKE :search_name OR d.manufacturer LIKE :search_manufacturer)';
            $parameters['search_name'] = '%' . $search . '%';
            $parameters['search_manufacturer'] = '%' . $search . '%';
        }

        if ($status !== null) {
            $where[] = 'd.lifecycle_status = :status';
            $parameters['status'] = $status;
        }

        $condition = match ($flag) {
            'no_image' => 'di.id IS NULL',
            'has_image' => 'di.id IS NOT NULL',
            'no_price' => '(d.price_amount IS NULL OR d.price_volume_ml IS NULL)',
            'needs_photo' => 'd.needs_new_photo = 1',
            default => null,
        };

        if ($condition !== null) {
            $where[] = $condition;
        }

        return $where;
    }

    /** Whitelisted ORDER BY clauses; the key is validated before it gets here. */
    private function orderBy(string $sort): string
    {
        // Rows without a value sort last in both directions, so neither end of
        // a column sort is a wall of dashes.
        $perHalfLitre = '(d.price_amount * 500 / d.price_volume_ml)';
        $noPrice = '(d.price_amount IS NULL OR d.price_volume_ml IS NULL)';
        // The Herkunft column leads with the Ort, so the sort has to as well;
        // with the PLZ in front that also orders the list roughly geographically.
        $origin = "COALESCE(NULLIF(d.origin_location, ''), NULLIF(d.origin_region, ''))";
        $noOrigin = '(' . $origin . ' IS NULL)';
        $lifecycle = "FIELD(d.lifecycle_status, 'identified', 'acquired', 'tested')";

        return match ($sort) {
            'name_desc' => ' ORDER BY d.name DESC, d.id DESC',
            'region' => ' ORDER BY ' . $noOrigin . ', ' . $origin . ' ASC, d.name',
            'region_desc' => ' ORDER BY ' . $noOrigin . ', ' . $origin . ' DESC, d.name',
            'price_asc' => ' ORDER BY ' . $noPrice . ', ' . $perHalfLitre . ' ASC, d.name',
            'price_desc' => ' ORDER BY ' . $noPrice . ', ' . $perHalfLitre . ' DESC, d.name',
            'status' => ' ORDER BY ' . $lifecycle . ' ASC, d.name',
            'status_desc' => ' ORDER BY ' . $lifecycle . ' DESC, d.name',
            'recent' => ' ORDER BY d.updated_at DESC, d.id DESC',
            default => ' ORDER BY d.name, d.id',
        };
    }

    /**
     * @return list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, has_primary_image: bool, needs_new_photo: bool}>
     */
    public function search(string $search, ?string $status, string $flag = '', string $sort = 'name'): array
    {
        $parameters = [];
        $where = $this->conditions($search, $status, $flag, $parameters);

        $sql = <<<'SQL'
            SELECT
                d.id,
                d.name,
                d.lifecycle_status,
                d.manufacturer,
                d.needs_new_photo,
                CASE WHEN di.id IS NULL THEN 0 ELSE 1 END AS has_primary_image
            FROM drinks d
            LEFT JOIN drink_images di
                ON di.drink_id = d.id
               AND di.display_order = 0
            SQL;

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= $this->orderBy($sort) . ' LIMIT 500';
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
            $needsNewPhoto = $row['needs_new_photo'] ?? null;

            if (
                (!is_int($id) && !is_string($id))
                || !is_string($name)
                || !is_string($lifecycleStatus)
                || ($manufacturer !== null && !is_string($manufacturer))
                || (!is_int($hasPrimaryImage) && !is_string($hasPrimaryImage))
                || (!is_int($needsNewPhoto) && !is_string($needsNewPhoto))
            ) {
                throw new RuntimeException('The drink search returned invalid data.');
            }

            $rows[] = [
                'id' => (int) $id,
                'name' => $name,
                'lifecycle_status' => $lifecycleStatus,
                'manufacturer' => $manufacturer,
                'has_primary_image' => (int) $hasPrimaryImage === 1,
                'needs_new_photo' => (int) $needsNewPhoto === 1,
            ];
        }

        return $rows;
    }

    /**
     * Full-detail listing for the admin Spezi overview: every stored drink
     * parameter, including the raw price pair, in one row.
     *
     * @return list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, has_primary_image: bool, needs_new_photo: bool}>
     */
    public function searchDetailed(string $search, ?string $status, string $flag = '', string $sort = 'name'): array
    {
        $parameters = [];
        $where = $this->conditions($search, $status, $flag, $parameters);

        $sql = <<<'SQL'
            SELECT
                d.id,
                d.name,
                d.lifecycle_status,
                d.manufacturer,
                d.origin_location,
                d.origin_region,
                d.notes,
                d.price_amount,
                d.price_volume_ml,
                d.needs_new_photo,
                CASE WHEN di.id IS NULL THEN 0 ELSE 1 END AS has_primary_image
            FROM drinks d
            LEFT JOIN drink_images di
                ON di.drink_id = d.id
               AND di.display_order = 0
            SQL;

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= $this->orderBy($sort) . ' LIMIT 500';
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
            $hasPrimaryImage = $row['has_primary_image'] ?? null;
            $needsNewPhoto = $row['needs_new_photo'] ?? null;

            if (
                (!is_int($id) && !is_string($id))
                || !is_string($name)
                || !is_string($lifecycleStatus)
                || (!is_int($hasPrimaryImage) && !is_string($hasPrimaryImage))
                || (!is_int($needsNewPhoto) && !is_string($needsNewPhoto))
            ) {
                throw new RuntimeException('The drink search returned invalid data.');
            }

            $rows[] = [
                'id' => (int) $id,
                'name' => $name,
                'lifecycle_status' => $lifecycleStatus,
                'manufacturer' => $this->nullableString($row, 'manufacturer'),
                'origin_location' => $this->nullableString($row, 'origin_location'),
                'origin_region' => $this->nullableString($row, 'origin_region'),
                'notes' => $this->nullableString($row, 'notes'),
                'price_amount' => $this->nullableString($row, 'price_amount'),
                'price_volume_ml' => $this->nullableInt($row, 'price_volume_ml'),
                'has_primary_image' => (int) $hasPrimaryImage === 1,
                'needs_new_photo' => (int) $needsNewPhoto === 1,
            ];
        }

        return $rows;
    }

    /**
     * @return array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, needs_new_photo: bool}|null
     */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $sql = <<<'SQL'
            SELECT id, name, lifecycle_status, manufacturer, origin_location, origin_region, notes,
                   price_amount, price_volume_ml, needs_new_photo
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
        $needsNewPhoto = $row['needs_new_photo'] ?? null;

        if (
            (!is_int($idValue) && !is_string($idValue))
            || !is_string($name)
            || !is_string($status)
            || (!is_int($needsNewPhoto) && !is_string($needsNewPhoto))
        ) {
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
            'price_amount' => $this->nullableString($row, 'price_amount'),
            'price_volume_ml' => $this->nullableInt($row, 'price_volume_ml'),
            'needs_new_photo' => (int) $needsNewPhoto === 1,
        ];
    }

    public function create(DrinkInput $input): int
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO drinks (
                    name, lifecycle_status, manufacturer, origin_location, origin_region, notes,
                    price_amount, price_volume_ml
                ) VALUES (
                    :name, :lifecycle_status, :manufacturer, :origin_location, :origin_region, :notes,
                    :price_amount, :price_volume_ml
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
                    notes = :notes,
                    price_amount = :price_amount,
                    price_volume_ml = :price_volume_ml
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

    public function setNeedsNewPhoto(int $id, bool $needsNewPhoto): void
    {
        $statement = $this->connection->prepare(
            'UPDATE drinks SET needs_new_photo = :needs_new_photo WHERE id = :id',
        );
        $statement->execute([
            'needs_new_photo' => $needsNewPhoto ? 1 : 0,
            'id' => $id,
        ]);
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

    /** @return array{name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int} */
    private function parameters(DrinkInput $input): array
    {
        return [
            'name' => $input->name,
            'lifecycle_status' => $input->lifecycleStatus,
            'manufacturer' => $input->manufacturer,
            'origin_location' => $input->originLocation,
            'origin_region' => $input->originRegion,
            'notes' => $input->notes,
            'price_amount' => $input->priceAmount,
            'price_volume_ml' => $input->priceVolumeMl,
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

    /** @param array<array-key, mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('A database query returned invalid numeric data.');
        }

        return (int) $value;
    }
}
