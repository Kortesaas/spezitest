<?php

declare(strict_types=1);

namespace Spezitest\Admin\Validation;

final class DrinkInputValidator
{
    public const STATUSES = ['identified', 'acquired', 'tested'];

    /** @param array<array-key, mixed> $input */
    public function validate(array $input, bool $creating = false): DrinkInput
    {
        $name = $this->requiredString($input, 'name', 255, 'Name');
        $status = $this->requiredString($input, 'lifecycle_status', 16, 'Status');

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Der gewählte Status ist ungültig.');
        }

        if ($creating && $status === 'tested') {
            throw new ValidationException('Getestet kann erst nach Erfassung eines abgeschlossenen Tests gewählt werden.');
        }

        [$priceAmount, $priceVolumeMl] = $this->price($input);

        return new DrinkInput(
            $name,
            $status,
            $this->optionalString($input, 'manufacturer', 255, 'Hersteller'),
            $this->optionalString($input, 'origin_location', 255, 'Ort'),
            $this->optionalString($input, 'origin_region', 128, 'Region'),
            $this->optionalString($input, 'notes', 65535, 'Notizen'),
            $priceAmount,
            $priceVolumeMl,
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

    /**
     * @param array<array-key, mixed> $input
     * @return array{0: ?string, 1: ?int}
     */
    private function price(array $input): array
    {
        $rawPrice = $input['price'] ?? null;
        $rawVolume = $input['price_volume_ml'] ?? null;

        $priceText = is_string($rawPrice) ? trim($rawPrice) : '';
        $volumeText = is_string($rawVolume) ? trim($rawVolume) : '';

        if ($priceText === '' && $volumeText === '') {
            return [null, null];
        }

        if ($priceText === '' || $volumeText === '') {
            throw new ValidationException(
                'Bitte Preis und Menge (ml) gemeinsam angeben oder beide leer lassen.',
            );
        }

        $priceText = str_replace(['€', 'EUR', ' ', "\u{00A0}", "\u{202F}"], '', $priceText);

        if (str_contains($priceText, '.') && str_contains($priceText, ',')) {
            $priceText = str_replace('.', '', $priceText);
        }

        $priceText = str_replace(',', '.', $priceText);

        if (preg_match('/\A\d{1,8}(?:\.\d{1,4})?\z/D', $priceText) !== 1) {
            throw new ValidationException('Preis: Bitte einen Betrag wie „0,89“ eingeben.');
        }

        $amount = (float) $priceText;

        if ($amount <= 0) {
            throw new ValidationException('Preis muss größer als 0 sein.');
        }

        if (!ctype_digit($volumeText)) {
            throw new ValidationException('Menge (ml): bitte eine ganze Zahl angeben.');
        }

        $volume = (int) $volumeText;

        if ($volume < 1 || $volume > 65535) {
            throw new ValidationException('Menge (ml): bitte einen Wert zwischen 1 und 65535 angeben.');
        }

        return [number_format($amount, 4, '.', ''), $volume];
    }
}
