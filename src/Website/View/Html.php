<?php

declare(strict_types=1);

namespace Spezitest\Website\View;

/**
 * Small output helpers shared by the website templates.
 *
 * Grades use the Spezitest scale: each category is 0–10 and the Gesamtwertung
 * is roughly 0–60, higher is better. Values are formatted with the German
 * decimal comma. Bar widths map a value onto its axis maximum so a longer bar
 * means a better result.
 */
final class Html
{
    public const CATEGORY_MAX = 10.0;

    public const GESAMT_MAX = 60.0;

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function grade(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, ',', '');
    }

    public static function gradeOrDash(?float $value, int $decimals = 2): string
    {
        return $value === null ? 'k. A.' : self::grade($value, $decimals);
    }

    public static function integer(int|float $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    public static function barWidth(float $value, float $max): string
    {
        if ($max <= 0.0) {
            return '0';
        }

        $percent = max(0.0, min(100.0, $value / $max * 100.0));

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }

    public static function price(string $decimal): string
    {
        return number_format((float) $decimal, 2, ',', '.') . ' €';
    }

    public static function stateLabel(string $status): string
    {
        return match ($status) {
            'identified' => 'Identifiziert',
            'acquired' => 'Erworben',
            'tested' => 'Getestet',
            default => 'Unbekannt',
        };
    }

    public static function stateBadge(string $status, bool $large = false): string
    {
        $modifier = in_array($status, ['identified', 'acquired', 'tested'], true) ? $status : 'identified';
        $size = $large ? ' state--lg' : '';

        return '<span class="state state--' . $modifier . $size . '">' . self::e(self::stateLabel($status)) . '</span>';
    }

    public static function isoToGermanDate(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        $time = strtotime($timestamp);

        if ($time === false) {
            return null;
        }

        return date('d.m.Y', $time);
    }
}
