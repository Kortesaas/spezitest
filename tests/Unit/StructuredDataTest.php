<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\StreamEpisode;
use Spezitest\Website\Catalog\StreamSegment;
use Spezitest\Website\View\StructuredData;

final class StructuredDataTest extends TestCase
{
    private StructuredData $schema;

    protected function setUp(): void
    {
        $this->schema = new StructuredData('https://www.spezitest.de/');
    }

    public function testScriptWrapsAValidGraphWithTheSharedOrganisationAndWebsiteNodes(): void
    {
        $html = $this->schema->script([]);

        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertStringEndsWith('</script>', $html);
        self::assertStringContainsString('"@type":"Organization"', $html);
        self::assertStringContainsString('"@type":"WebSite"', $html);
        self::assertStringContainsString('"@context":"https:\/\/schema.org"', $html);

        // The payload is valid JSON.
        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        self::assertIsArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testScriptEscapesClosingTagsSoItCannotBreakOut(): void
    {
        $drink = CatalogFixture::untested('</script><script>alert(1)</script>');

        $html = $this->schema->script([$this->schema->drink($drink, 0)]);

        self::assertStringNotContainsString('</script><script>alert', $html);
        self::assertStringContainsString('<\/script>', $html);
    }

    public function testTestedDrinkCarriesAProductReviewOnTheRealGesamtScale(): void
    {
        $drink = CatalogFixture::tested(
            'Spezi Nord',
            ['manu' => [8, 8, 8], 'fabi' => [8, 8, 8], 'schorsch' => [8, 8, 8]],
            manufacturer: 'Nordbrau',
        );

        $html = $this->schema->script([$this->schema->drink($drink, 5)]);

        self::assertStringContainsString('"@type":"Product"', $html);
        self::assertStringContainsString('"brand":{"@type":"Brand","name":"Nordbrau"}', $html);
        self::assertStringContainsString('"@type":"Review"', $html);
        self::assertStringContainsString('"reviewRating":{"@type":"Rating","ratingValue":48,"worstRating":0,"bestRating":60}', $html);
        self::assertStringContainsString('"datePublished":"2026-01-01"', $html);
        self::assertStringContainsString('"@type":"AggregateRating"', $html);
    }

    public function testUntestedDrinkHasNoRatingNodes(): void
    {
        $html = $this->schema->script([$this->schema->drink(CatalogFixture::untested('Noch nicht getestet'), 0)]);

        self::assertStringContainsString('"@type":"Product"', $html);
        self::assertStringNotContainsString('"@type":"Review"', $html);
        self::assertStringNotContainsString('"@type":"AggregateRating"', $html);
    }

    public function testBreadcrumbNumbersItemsAndOnlyLinksThoseWithAPath(): void
    {
        $html = $this->schema->script([
            $this->schema->breadcrumb([
                ['name' => 'Start', 'path' => '/'],
                ['name' => 'Spezis', 'path' => '/spezis'],
                ['name' => 'Spezi Nord', 'path' => null],
            ]),
        ]);

        self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
        self::assertStringContainsString('"position":1,"name":"Start","item":"https:\/\/www.spezitest.de\/"', $html);
        self::assertStringContainsString('"position":3,"name":"Spezi Nord"}', $html);
    }

    public function testStreamEpisodeBecomesAVideoObjectWithAYouTubeThumbnail(): void
    {
        $drink = CatalogFixture::tested(
            'Segment Spezi',
            ['manu' => [6, 6, 6], 'fabi' => [6, 6, 6], 'schorsch' => [6, 6, 6]],
        );
        $drink = new RatedDrink(
            $drink->id,
            $drink->name,
            $drink->manufacturer,
            $drink->originLocation,
            $drink->originRegion,
            $drink->notes,
            $drink->lifecycleStatus,
            $drink->hasImage,
            $drink->updatedAt,
            $drink->result,
            $drink->rank,
            $drink->priceAmount,
            $drink->priceVolumeMl,
            $drink->testNotes,
            $drink->testedAt,
            $drink->pricePerformance,
            $drink->testerGrades,
            new StreamSegment(3, 'Spezistream 3', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', null),
        );

        $episode = StreamEpisode::fromCollection(new RatedDrinkCollection([$drink]))[0];

        $html = $this->schema->script([$this->schema->streamEpisode($episode)]);

        self::assertStringContainsString('"@type":"VideoObject"', $html);
        self::assertStringContainsString('i.ytimg.com\/vi\/dQw4w9WgXcQ\/hqdefault.jpg', $html);
        self::assertStringContainsString('"embedUrl":"https:\/\/www.youtube.com\/embed\/dQw4w9WgXcQ"', $html);
    }
}
