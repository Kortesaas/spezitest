<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Website\Catalog\Slug;

final class SlugTest extends TestCase
{
    public function testResolvesBareNumericReference(): void
    {
        $slug = Slug::fromReference('42');

        self::assertNotNull($slug);
        self::assertSame(42, $slug->id);
    }

    public function testResolvesReferenceWithSlugTail(): void
    {
        $slug = Slug::fromReference('42-floetzinger-cola-mix');

        self::assertNotNull($slug);
        self::assertSame(42, $slug->id);
        self::assertSame('42-floetzinger-cola-mix', $slug->canonical);
    }

    public function testRejectsNonNumericLeadingReference(): void
    {
        self::assertNull(Slug::fromReference('floetzinger'));
        self::assertNull(Slug::fromReference('-1'));
        self::assertNull(Slug::fromReference('0'));
        self::assertNull(Slug::fromReference('1/2'));
    }

    public function testBuildsAsciiSlugFromGermanName(): void
    {
        self::assertSame('7-floetzinger-cola-mix', Slug::forDrink(7, 'Flötzinger Cola-Mix'));
        self::assertSame('9-spezi-gross-suess', Slug::forDrink(9, 'Spezi groß & süß'));
        self::assertSame('3', Slug::forDrink(3, '„“ !!!'));
    }
}
