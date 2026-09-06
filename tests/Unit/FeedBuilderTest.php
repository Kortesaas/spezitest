<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Seo\FeedBuilder;

final class FeedBuilderTest extends TestCase
{
    public function testEmitsOneAtomEntryPerTestedDrinkNewestFirst(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested(
                'Ältere Spezi',
                ['manu' => [5, 5, 5], 'fabi' => [5, 5, 5], 'schorsch' => [5, 5, 5]],
                updatedAt: '2026-02-01 09:00:00',
            ),
            CatalogFixture::tested(
                'Neuere Spezi',
                ['manu' => [9, 9, 9], 'fabi' => [9, 9, 9], 'schorsch' => [9, 9, 9]],
                updatedAt: '2026-08-01 09:00:00',
            ),
            CatalogFixture::untested('Ungetestet'),
        ]);

        $xml = (new FeedBuilder('https://www.spezitest.de'))->build($collection);

        $feed = new \SimpleXMLElement($xml);
        $feed->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $titles = array_map(strval(...), $feed->xpath('//a:entry/a:title') ?: []);

        self::assertCount(2, $titles);
        self::assertStringContainsString('Neuere Spezi', $titles[0]);
        self::assertStringContainsString('Ältere Spezi', $titles[1]);
        self::assertStringContainsString('54,00 / 60', $titles[0]);
        self::assertStringNotContainsString('Ungetestet', $xml);
    }

    public function testLinksPointAtTheAbsoluteDrinkUrl(): void
    {
        $collection = new RatedDrinkCollection([
            CatalogFixture::tested('Test Spezi', ['manu' => [6, 6, 6], 'fabi' => [6, 6, 6], 'schorsch' => [6, 6, 6]]),
        ]);

        $xml = (new FeedBuilder('https://www.spezitest.de'))->build($collection);

        self::assertMatchesRegularExpression(
            '~<link href="https://www\.spezitest\.de/spezi/\d+-test-spezi"/>~',
            $xml,
        );
        self::assertInstanceOf(\SimpleXMLElement::class, new \SimpleXMLElement($xml));
    }

    public function testHandlesAnEmptyCatalogWithoutError(): void
    {
        $xml = (new FeedBuilder('https://www.spezitest.de'))->build(new RatedDrinkCollection([]));

        $feed = new \SimpleXMLElement($xml);
        $feed->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');

        self::assertSame([], $feed->xpath('//a:entry') ?: []);
    }
}
