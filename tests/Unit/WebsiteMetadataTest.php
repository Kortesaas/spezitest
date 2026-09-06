<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\View\WebsiteRenderer;

final class WebsiteMetadataTest extends TestCase
{
    private WebsiteRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new WebsiteRenderer('https://www.spezitest.de');
    }

    public function testEveryPageHeadCarriesTheIconManifestThemeAndFeedLinks(): void
    {
        $html = $this->renderer->home(new RatedDrinkCollection([]));

        self::assertStringContainsString('<meta name="theme-color" content="#002D55">', $html);
        self::assertStringContainsString('<link rel="manifest" href="/site.webmanifest">', $html);
        self::assertStringContainsString('<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">', $html);
        self::assertStringContainsString('href="/favicon.ico"', $html);
        self::assertStringContainsString(
            '<link rel="alternate" type="application/atom+xml" title="Spezitest – Neu getestet" href="/feed.xml">',
            $html,
        );
        self::assertStringContainsString(
            '<meta property="og:image" content="https://www.spezitest.de/assets/spezitest-share.png">',
            $html,
        );
        self::assertStringContainsString('<link rel="canonical" href="https://www.spezitest.de/">', $html);
    }

    public function testHomePageEmbedsTheOrganisationAndWebsiteStructuredData(): void
    {
        $html = $this->renderer->home(new RatedDrinkCollection([]));

        self::assertStringContainsString('<script type="application/ld+json">', $html);
        self::assertStringContainsString('"@type":"Organization"', $html);
        self::assertStringContainsString('"@type":"WebSite"', $html);
    }

    public function testDrinkDetailEmbedsProductReviewAndBreadcrumbData(): void
    {
        $drink = CatalogFixture::tested(
            'Spezi Nord',
            ['manu' => [8, 8, 8], 'fabi' => [8, 8, 8], 'schorsch' => [8, 8, 8]],
            manufacturer: 'Nordbräu',
        );
        $collection = new RatedDrinkCollection([$drink]);

        $html = $this->renderer->detail($drink, $collection);

        self::assertStringContainsString('"@type":"Product"', $html);
        self::assertStringContainsString('"@type":"Review"', $html);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
        self::assertStringContainsString('"bestRating":60', $html);
        self::assertStringContainsString('"ratingValue":48,', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
    }

    public function testHomePageOpenGraphTypeIsWebsite(): void
    {
        $html = $this->renderer->home(new RatedDrinkCollection([]));

        self::assertStringContainsString('<meta property="og:type" content="website">', $html);
    }

    public function testSpeziWithAPhotoSharesThatPhotoNotTheGenericCard(): void
    {
        $drink = CatalogFixture::untested('Foto Spezi', hasImage: true);
        $html = $this->renderer->detail($drink, new RatedDrinkCollection([$drink]));

        self::assertMatchesRegularExpression(
            '~<meta property="og:image" content="https://www\.spezitest\.de/spezi/\d+/bild">~',
            $html,
        );
        self::assertStringNotContainsString('og:image:width', $html);
    }

    public function testNotFoundPageIsUnindexedAndCarriesNoCanonicalOrSchema(): void
    {
        $html = $this->renderer->notFound();

        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringNotContainsString('<link rel="canonical"', $html);
        self::assertStringNotContainsString('application/ld+json', $html);
        self::assertStringNotContainsString('<meta property="og:url"', $html);
    }

    public function testServerErrorPageIsBrandedAndUnindexed(): void
    {
        $html = $this->renderer->serverError();

        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('Serverfehler', $html);
        self::assertStringContainsString('site-header', $html);
    }

    public function testConfiguredOriginReplacesTheDefaultInAbsoluteUrls(): void
    {
        $renderer = new WebsiteRenderer('https://staging.spezitest.example');

        $html = $renderer->home(new RatedDrinkCollection([]));

        self::assertStringContainsString('https://staging.spezitest.example/assets/spezitest-share.png', $html);
        self::assertStringNotContainsString('www.spezitest.de', $html);
    }
}
