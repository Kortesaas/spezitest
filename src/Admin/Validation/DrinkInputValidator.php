<?php

declare(strict_types=1);

namespace Spezitest\Admin\Validation;

final class DrinkInputValidator
{
    public const STATUSES = ['identified', 'acquired', 'tested'];

    /** @param array<array-key, mixed> $input */
    public function validate(array $input, bool $quickCreate = false): DrinkInput
    {
        $name = $this->requiredString($input, 'name', 255, 'Name');
        $status = $this->requiredString($input, 'lifecycle_status', 16, 'Status');

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Der gewählte Status ist ungültig.');
        }

        if ($quickCreate && $status === 'tested') {
            throw new ValidationException('Getestet kann erst nach Erfassung eines abgeschlossenen Tests gewählt werden.');
        }

        return new DrinkInput(
            $name,
            $status,
            $quickCreate ? null : $this->optionalString($input, 'manufacturer', 255, 'Hersteller'),
            $quickCreate ? null : $this->optionalString($input, 'origin_location', 255, 'Ort'),
            $quickCreate ? null : $this->optionalString($input, 'origin_region', 128, 'Region'),
            $quickCreate ? null : $this->optionalString($input, 'notes', 65535, 'Notizen'),
        );
    }

    public function validateStatus(mixed $status): string
    {
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Der gewählte Status ist ungültig.');
        }

        return $status;
    }

    public function validateSearch(mixed $search): string
    {
        if ($search === null) {
            return '';
        }

        if (!is_string($search)) {
            throw new ValidationException('Die Suche ist ungültig.');
        }

        $search = trim($search);

        if (strlen($search) > 255) {
            throw new ValidationException('Die Suche ist zu lang.');
        }

        return $search;
    }

    /** @param array<array-key, mixed> $input */
    private function requiredString(array $input, string $key, int $maximum, string $label): string
    {
        $value = $input[$key] ?? null;

        if (!is_string($value)) {
            throw new ValidationException($label . ' fehlt oder ist ungültig.');
        }

        $value = trim($value);

        if ($value === '') {
            throw new ValidationException($label . ' darf nicht leer sein.');
        }

        if (strlen($value) > $maximum) {
            throw new ValidationException($label . ' ist zu lang.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $input */
    private function optionalString(array $input, string $key, int $maximum, string $label): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException($label . ' ist ungültig.');
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maximum) {
            throw new ValidationException($label . ' ist zu lang.');
        }

        return $value;
    }
}
