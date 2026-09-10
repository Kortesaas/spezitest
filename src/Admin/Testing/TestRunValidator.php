<?php

declare(strict_types=1);

namespace Spezitest\Admin\Testing;

use DateTimeImmutable;
use Spezitest\Admin\Validation\ValidationException;

/**
 * Validation for Spezistream metadata and the per-test stream position.
 *
 * Every value comes from an admin form and is therefore untrusted. The stream
 * URL in particular is rendered as a link, so only absolute http(s) URLs are
 * accepted — never a `javascript:` or `data:` scheme.
 */
final class TestRunValidator
{
    private const MAX_NUMBER = 9999;

    private const MAX_TITLE = 190;

    private const MAX_URL = 500;

    private const MAX_NOTES = 65535;

    /** A single test segment cannot sensibly be longer than a day. */
    private const MAX_OFFSET_SECONDS = 86399;

    private const MAX_DURATION_SECONDS = 86399;

    /** @return list<int> */
    public function validateDrinkIds(mixed $values): array
    {
        if ($values === null) {
            return [];
        }

        if (!is_array($values) || count($values) > 500) {
            throw new ValidationException('Die Spezi-Auswahl ist ungültig.');
        }

        $ids = [];

        foreach ($values as $value) {
            if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
                throw new ValidationException('Die Spezi-Auswahl ist ungültig.');
            }

            $ids[(int) $value] = (int) $value;
        }

        return array_values($ids);
    }

    public function validateNumber(mixed $number): int
    {
        if (is_int($number)) {
            $number = (string) $number;
        }

        if (!is_string($number) || !ctype_digit(trim($number))) {
            throw new ValidationException('Die Spezistream-Nummer muss eine Zahl sein.');
        }

        $value = (int) trim($number);

        if ($value < 1 || $value > self::MAX_NUMBER) {
            throw new ValidationException('Die Spezistream-Nummer liegt außerhalb des gültigen Bereichs.');
        }

        return $value;
    }

    public function validateTitle(mixed $title): ?string
    {
        return $this->optionalText($title, self::MAX_TITLE, 'Der Titel ist zu lang.');
    }

    public function validateNotes(mixed $notes): ?string
    {
        return $this->optionalText($notes, self::MAX_NOTES, 'Die Notiz ist zu lang.');
    }

    /** An ISO date (`YYYY-MM-DD`), as produced by an `<input type="date">`. */
    public function validateDate(mixed $date): ?string
    {
        $value = $this->optionalText($date, 10, 'Das Datum ist ungültig.');

        if ($value === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new ValidationException('Das Datum muss im Format JJJJ-MM-TT vorliegen.');
        }

        return $value;
    }

    /**
     * The episode's video address. Only absolute http(s) URLs are stored, so
     * the "im Stream ansehen" link can never carry an executable scheme.
     */
    public function validateStreamUrl(mixed $url): ?string
    {
        $value = $this->optionalText($url, self::MAX_URL, 'Die Stream-Adresse ist zu lang.');

        if ($value === null) {
            return null;
        }

        $scheme = strtolower((string) (parse_url($value, PHP_URL_SCHEME) ?? ''));
        $host = (string) (parse_url($value, PHP_URL_HOST) ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ValidationException('Die Stream-Adresse muss mit http:// oder https:// beginnen.');
        }

        return $value;
    }

    /**
     * The position of a segment inside the stream, entered as `h:mm:ss`,
     * `mm:ss` or a plain number of seconds. Stored as a `TIME` string.
     */
    public function validateOffset(mixed $offset): ?string
    {
        $value = $this->optionalText($offset, 16, 'Der Zeitstempel ist ungültig.');

        if ($value === null) {
            return null;
        }

        $value = str_replace([',', '.'], ':', $value);

        if (ctype_digit($value)) {
            return $this->formatSeconds($this->boundedSeconds((int) $value, self::MAX_OFFSET_SECONDS));
        }

        if (preg_match('/\A(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})\z/D', $value, $matches) !== 1) {
            throw new ValidationException('Der Zeitstempel muss als h:mm:ss, mm:ss oder in Sekunden angegeben werden.');
        }

        $hours = $matches[1] === '' ? 0 : (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];

        if ($minutes > 59 || $seconds > 59) {
            throw new ValidationException('Minuten und Sekunden müssen zwischen 0 und 59 liegen.');
        }

        return $this->formatSeconds(
            $this->boundedSeconds($hours * 3600 + $minutes * 60 + $seconds, self::MAX_OFFSET_SECONDS),
        );
    }

    /** How long the segment ran, in whole seconds. */
    public function validateDuration(mixed $duration): ?int
    {
        $value = $this->optionalText($duration, 8, 'Die Dauer ist ungültig.');

        if ($value === null) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new ValidationException('Die Dauer muss eine Anzahl Sekunden sein.');
        }

        return $this->boundedSeconds((int) $value, self::MAX_DURATION_SECONDS);
    }

    private function boundedSeconds(int $seconds, int $maximum): int
    {
        if ($seconds < 0 || $seconds > $maximum) {
            throw new ValidationException('Der Wert liegt außerhalb des gültigen Bereichs.');
        }

        return $seconds;
    }

    private function formatSeconds(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function optionalText(mixed $value, int $maximum, string $message): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException($message);
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximum) {
            throw new ValidationException($message);
        }

        return $value;
    }
}
