<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Website\Games\FakeNameGenerator;

final class FakeNameGeneratorTest extends TestCase
{
    public function testPoolIsLargeUniqueAndUsesShortCatalogueLikeNames(): void
    {
        $pool = FakeNameGenerator::pool([]);
        $normalised = array_map(FakeNameGenerator::normalise(...), $pool);

        self::assertGreaterThanOrEqual(2_000, count($pool));
        self::assertSame(count($pool), count(array_unique($normalised)));

        foreach ($pool as $name) {
            $words = preg_split('/\s+/u', trim($name));
            self::assertIsArray($words);
            self::assertContains(count($words), [2, 3], $name);
        }
    }

    public function testRealCatalogueNamesAreNeverOfferedAsFake(): void
    {
        $pool = FakeNameGenerator::pool(['Alpenfunke Cola Mix', 'BÄRENQUELL COLA-MIX']);
        $normalised = array_map(FakeNameGenerator::normalise(...), $pool);

        self::assertNotContains(FakeNameGenerator::normalise('Alpenfunke Cola Mix'), $normalised);
        self::assertNotContains(FakeNameGenerator::normalise('Bärenquell Cola-Mix'), $normalised);
    }
}
