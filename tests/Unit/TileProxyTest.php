<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Website\Map\TileProxy;

final class TileProxyTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n" . 'body';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/spezitest-tiles-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->cacheDir);
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }

    public function testFetchesAndCachesATileWithinGermany(): void
    {
        $calls = 0;
        $proxy = new TileProxy($this->cacheDir, function () use (&$calls): string {
            ++$calls;

            return self::PNG;
        });

        // z6 / x33 / y21 covers roughly Frankfurt am Main.
        self::assertSame(self::PNG, $proxy->tile(6, 33, 21));
        self::assertFileExists($this->cacheDir . '/6/33/21.png');

        // Second call is served from disk without hitting the fetcher again.
        self::assertSame(self::PNG, $proxy->tile(6, 33, 21));
        self::assertSame(1, $calls);
    }

    public function testRejectsZoomOutsideTheUsedRangeWithoutFetching(): void
    {
        $proxy = new TileProxy($this->cacheDir, function (): string {
            self::fail('Out-of-range tiles must not reach the fetcher.');
        });

        self::assertNull($proxy->tile(3, 4, 2));
        self::assertNull($proxy->tile(13, 4300, 2800));
    }

    public function testRejectsTilesOutsideTheGermanBoundingBox(): void
    {
        $proxy = new TileProxy($this->cacheDir, function (): string {
            self::fail('Tiles outside Germany must not reach the fetcher.');
        });

        // z6 / x18 / y24 is over the mid-Atlantic.
        self::assertNull($proxy->tile(6, 18, 24));
    }

    public function testRejectsAnUpstreamResponseThatIsNotAPng(): void
    {
        $proxy = new TileProxy($this->cacheDir, static fn (): string => '<html>rate limited</html>');

        self::assertNull($proxy->tile(6, 33, 21));
        self::assertFileDoesNotExist($this->cacheDir . '/6/33/21.png');
    }

    public function testReturnsNullWhenTheUpstreamIsUnreachable(): void
    {
        $proxy = new TileProxy($this->cacheDir, static fn (): ?string => null);

        self::assertNull($proxy->tile(6, 33, 21));
    }
}
