<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Seo\SitemapBuilder;

final class SitemapBuilderTest extends TestCase
{
    public function testListsStaticPagesAndEveryDrinkWithAnAbsoluteLocation(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Spezi Nord', ['manu' => [7, 7, 7], 'fabi' => [7, 7, 7], 'schorsch' => [7, 7, 7]]),
            CatalogFixture::untested('Spezi Süd'),
        ]);

        $xml = (new SitemapBuilder('https://www.spezitest.de'))->build($collection, []);

        self::assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertStringContainsString('<loc>https://www.spezitest.de/</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.spezitest.de/ranking</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.spezitest.de/spiele</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.spezitest.de/spiele/herkunft</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.spezitest.de/spiele/memory</loc>', $xml);
        self::assertStringContainsString('<loc>https://www.spezitest.de/spiele/echt-oder-fake</loc>', $xml);
        self::assertMatchesRegularExpression('~<loc>https://www\.spezitest\.de/spezi/\d+-spezi-nord</loc>~', $xml);
        self::assertMatchesRegularExpression('~<loc>https://www\.spezitest\.de/spezi/\d+-spezi-sued</loc>~', $xml);
    }

    public function testDrinkEntriesCarryALastModifiedDate(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::untested('Spezi West', updatedAt: '2026-05-04 12:30:00'),
        ]);

        $xml = (new SitemapBuilder('https://www.spezitest.de'))->build($collection, []);

        self::assertMatchesRegularExpression(
            '~<loc>https://www\.spezitest\.de/spezi/\d+-spezi-west</loc><lastmod>2026-05-04</lastmod>~',
            $xml,
        );
    }

    public function testTrailingSlashOnTheConfiguredOriginIsNormalised(): void
    {
        $xml = (new SitemapBuilder('https://www.spezitest.de/'))->build(new RatedDrinkCollection([]), []);

        self::assertStringContainsString('<loc>https://www.spezitest.de/</loc>', $xml);
        self::assertStringNotContainsString('spezitest.de//', $xml);
    }

    public function testIsWellFormedXml(): void
    {
        $collection = new RatedDrinkCollection([CatalogFixture::untested('A & B <weird>')]);

        $xml = (new SitemapBuilder('https://www.spezitest.de'))->build($collection, []);

        self::assertInstanceOf(\SimpleXMLElement::class, new \SimpleXMLElement($xml));
    }
}
