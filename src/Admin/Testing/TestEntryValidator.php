<?php

declare(strict_types=1);

namespace Spezitest\Admin\Testing;

use Spezitest\Admin\Validation\ValidationException;
use Spezitest\Domain\Rating\TesterCode;

/**
 * Server-side validation for the admin test-entry form.
 *
 * Grades are accepted as integers 0–10 (the full range observed in the
 * verified historical workbook; the rating methodology's remaining decimal
 * semantics are unresolved, so entry stays integer-only for now). Higher is
 * better. A price is optional and parsed from German or plain decimal
 * notation. The rating aggregation itself is delegated to the verified
 * engine — this class never computes a category average or Gesamt.
 */
final class TestEntryValidator
{
    private const CATEGORIES = ['optik' => 'Optik', 'sueffigkeit' => 'Süffigkeit', 'geschmack' => 'Geschmack'];

    private const TESTER_LABELS = ['manu' => 'Manu', 'fabi' => 'Fabi', 'schorsch' => 'Schorsch'];

    private const GRADE_MIN = 0;

    private const GRADE_MAX = 10;

    /**
     * @param array<array-key, mixed> $body
     */
    public function validate(array $body, bool $requireComplete): TestEntryInput
    {
        $ratings = [];
        $errors = [];

        foreach (TesterCode::cases() as $tester) {
            $code = $tester->value;
            $values = [];
            $providedCount = 0;

            foreach (self::CATEGORIES as $category => $categoryLabel) {
                $raw = $body[$code . '_' . $category] ?? null;
                $grade = $this->grade($raw);

                if ($grade === null && is_string($raw) && trim($raw) !== '') {
                    $errors[] = sprintf(
                        '%s / %s: Note muss eine ganze Zahl von %d bis %d sein.',
                        self::TESTER_LABELS[$code],
                        $categoryLabel,
                        self::GRADE_MIN,
                        self::GRADE_MAX,
                    );

                    continue;
                }

                if ($grade !== null) {
                    ++$providedCount;
                    $values[$category] = $grade;
                }
            }

            if ($providedCount === 3) {
                /** @var array{optik: int, sueffigkeit: int, geschmack: int} $complete */
                $complete = $values;
                $ratings[$code] = $complete;
            } elseif ($providedCount > 0) {
                $errors[] = sprintf(
                    'Für %s bitte alle drei Noten (Optik, Süffigkeit, Geschmack) angeben oder keine.',
                    self::TESTER_LABELS[$code],
                );
            } elseif ($requireComplete) {
                $errors[] = sprintf('Für %s fehlen alle Noten.', self::TESTER_LABELS[$code]);
            }
        }

        $price = $this->price($body['price'] ?? null, $errors);
        $notes = $this->notes($body['notes'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException(implode(' ', $errors));
        }

        $input = new TestEntryInput($ratings, $price, $notes);

        if ($requireComplete && !$input->isComplete()) {
            throw new ValidationException('Der Test kann erst abgeschlossen werden, wenn alle neun Noten gesetzt sind.');
        }

        return $input;
    }

    private function grade(mixed $raw): ?int
    {
        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $value = (int) $raw;

        if ($value < self::GRADE_MIN || $value > self::GRADE_MAX) {
            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     */
    private function price(mixed $raw, array &$errors): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $text = trim($raw);
        $text = str_replace(['€', 'EUR', ' ', "\u{00A0}", "\u{202F}"], '', $text);

        if ($text === '') {
            return null;
        }

        if (str_contains($text, '.') && str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
        }

        $text = str_replace(',', '.', $text);

        if (preg_match('/\A\d{1,8}(?:\.\d{1,4})?\z/D', $text) !== 1) {
            $errors[] = 'Preis: Bitte einen Betrag wie „0,89“ eingeben.';

            return null;
        }

        $amount = (float) $text;

        if ($amount < 0) {
            $errors[] = 'Preis darf nicht negativ sein.';

            return null;
        }

        return number_format($amount, 4, '.', '');
    }

    /**
     * @param list<string> $errors
     */
    private function notes(mixed $raw, array &$errors): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $text = trim($raw);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > 4000) {
            $errors[] = 'Die Testnotiz ist zu lang (maximal 4000 Zeichen).';

            return null;
        }

        return $text;
    }
}
