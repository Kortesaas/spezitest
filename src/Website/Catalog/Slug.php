<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * Public drink references of the form `{id}` or `{id}-{name-slug}`.
 *
 * Only the leading numeric id is authoritative; the slug tail is cosmetic and
 * ignored when resolving. The canonical URL for a drink always includes the
 * slug, so callers can issue a redirect when {@see self::canonical()} differs
 * from the requested reference.
 */
final readonly class Slug
{
    private function __construct(public int $id, public string $canonical)
    {
    }

    public static function fromReference(string $reference): ?self
    {
        if (preg_match('/\A(\d{1,18})(?:-[^\s\/]*)?\z/D', $reference, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        if ($id < 1) {
            return null;
        }

        return new self($id, $reference);
    }

    public static function forDrink(int $id, string $name): string
    {
        $ascii = self::asciiFold($name);
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';
        $ascii = trim($ascii, '-');

        if ($ascii === '') {
            return (string) $id;
        }

        return $id . '-' . $ascii;
    }

    private static function asciiFold(string $value): string
    {
        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];

        return strtr($value, $map);
    }
}
