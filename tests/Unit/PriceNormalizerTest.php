<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Spezitest\Domain\Rating\PriceNormalizer;

final class PriceNormalizerTest extends TestCase
{
    public function testHalfLitreVolumeIsUnchanged(): void
    {
        self::assertSame(0.89, (new PriceNormalizer())->perReferenceVolume('0.89', 500));
    }

    public function testLargerVolumeIsScaledDown(): void
    {
        self::assertSame(0.745, (new PriceNormalizer())->perReferenceVolume('1.49', 1000));
    }

    public function testSmallerVolumeIsScaledUp(): void
    {
        self::assertSame(1.0, (new PriceNormalizer())->perReferenceVolume('0.5', 250));
    }

    public function testZeroVolumeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PriceNormalizer())->perReferenceVolume('0.89', 0);
    }

    public function testNegativeVolumeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PriceNormalizer())->perReferenceVolume('0.89', -500);
    }
}
