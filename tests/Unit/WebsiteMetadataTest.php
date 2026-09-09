<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\Geo\PostalGeocoder;
use Spezitest\Website\Catalog\HuntMap;
use Spezitest\Website\Catalog\MapScope;
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

    public function testHomePageConclusionListPutsTheWeakestDrinkAtTheBottom(): void
    {
        $ratings = static fn (int $grade): array => [
            'manu' => [$grade, $grade, $grade],
            'fabi' => [$grade, $grade, $grade],
            'schorsch' => [$grade, $grade, $grade],
        ];
        $html = $this->renderer->home(new RatedDrinkCollection([
            CatalogFixture::tested('Testsieger', $ratings(10), rank: 1),
            CatalogFixture::tested('Fünftletzte', $ratings(5), rank: 2),
            CatalogFixture::tested('Viertletzte', $ratings(4), rank: 3),
            CatalogFixture::tested('Drittletzte', $ratings(3), rank: 4),
            CatalogFixture::tested('Vorletzte', $ratings(2), rank: 5),
            CatalogFixture::tested('Schlusslicht', $ratings(1), rank: 6),
        ]));

        $sectionStart = strpos($html, '<h2 class="display-3">Schlusslichter</h2>');
        $sectionEnd = strpos($html, '<span class="eyebrow eyebrow--accent">Noch gesucht</span>');
        self::assertIsInt($sectionStart);
        self::assertIsInt($sectionEnd);
        $section = substr($html, $sectionStart, $sectionEnd - $sectionStart);

        self::assertStringNotContainsString('Testsieger', $section);
        $positions = array_map(
            static fn (string $name): int|false => strpos($section, '<span class="rank__name">' . $name . '</span>'),
            ['Fünftletzte', 'Viertletzte', 'Drittletzte', 'Vorletzte', 'Schlusslicht'],
        );
        self::assertNotContains(false, $positions);
        self::assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
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

    public function testKarteScopesEachHaveTheirOwnTitleCanonicalAndCurrentTab(): void
    {
        $drink = CatalogFixture::untested('Karten Spezi', 'identified', null, false, '2026-01-01 00:00:00', '01067 Dresden');
        $map = HuntMap::fromDrinks([$drink], PostalGeocoder::default());

        $all = $this->renderer->karte($map, MapScope::All);
        self::assertStringContainsString('<title>Karte · Spezitest</title>', $all);
        self::assertStringContainsString('<link rel="canonical" href="https://www.spezitest.de/karte">', $all);
        self::assertStringContainsString('<a href="/karte" aria-current="page">Alle</a>', $all);

        $tested = $this->renderer->karte($map, MapScope::Tested);
        self::assertStringContainsString('<title>Getestete Spezis auf der Karte · Spezitest</title>', $tested);
        self::assertStringContainsString('href="https://www.spezitest.de/karte/getestet">', $tested);
        self::assertStringContainsString('<a href="/karte/getestet" aria-current="page">Getestet</a>', $tested);

        $sought = $this->renderer->karte($map, MapScope::Sought);
        self::assertStringContainsString('<title>Gesuchte Spezis auf der Karte · Spezitest</title>', $sought);
        self::assertStringContainsString('href="https://www.spezitest.de/karte/gesucht">', $sought);
    }

    public function testKarteSpeziMiniMapTitlesAfterTheDrinkAndHasNoTabs(): void
    {
        $drink = CatalogFixture::untested('Herkunft Spezi', 'identified', 'Quelle AG', false, '2026-01-01 00:00:00', '01067 Dresden');
        $map = HuntMap::fromDrinks([$drink], PostalGeocoder::default());

        $html = $this->renderer->karteSpezi($map, $drink);

        self::assertStringContainsString('<title>Wo Herkunft Spezi herkommt · Spezitest</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://www.spezitest.de/karte/spezi/' . $drink->id . '">', $html);
        self::assertStringContainsString('Wo Herkunft Spezi herkommt', $html);
        self::assertStringNotContainsString('class="karte__tabs"', $html);
        self::assertStringContainsString('data-scope="spezi"', $html);
    }

    public function testGamesHubHasInteractiveBottleThumbnailsAndCurrentNavigation(): void
    {
        $html = $this->renderer->games([
            ['id' => 41, 'name' => 'Erste Flasche', 'image' => '/spezi/41/bild'],
            ['id' => 42, 'name' => 'Zweite Flasche', 'image' => '/spezi/42/bild'],
            ['id' => 43, 'name' => 'Dritte Flasche', 'image' => '/spezi/43/bild'],
        ]);

        self::assertStringContainsString('<a href="/spiele" aria-current="page">Spiele</a>', $html);
        self::assertStringContainsString('class="game-card__visual game-card__visual--geo"', $html);
        self::assertStringContainsString('class="game-card__visual game-card__visual--memory"', $html);
        self::assertStringContainsString('class="game-card__visual game-card__visual--quiz"', $html);
        self::assertStringContainsString('src="/assets/spezitest-icon-memory.svg"', $html);
        self::assertStringContainsString('src="/spezi/41/bild"', $html);
        self::assertStringContainsString('href="/spiele/echt-oder-fake"', $html);
    }

    public function testGamePagesEmbedOnlyTheDataNeededByTheirBrowserGame(): void
    {
        $geo = [];
        $memory = [];

        for ($id = 1; $id <= 15; $id++) {
            $memory[] = ['id' => $id, 'name' => "Flasche $id", 'image' => "/spezi/$id/bild"];
            if ($id <= 5) {
                $geo[] = $memory[$id - 1] + [
                    'location' => '12345 Musterstadt',
                    'latitude' => 50.1,
                    'longitude' => 8.6,
                ];
            }
        }

        $geoHtml = $this->renderer->geographyGame($geo);
        $memoryHtml = $this->renderer->memoryGame($memory);
        $quizHtml = $this->renderer->realOrFakeGame([
            ['id' => 1, 'name' => 'Eins Cola-Mix', 'slug' => '1-eins-cola-mix', 'image' => '/spezi/1/bild'],
            ['id' => 2, 'name' => 'Zwei Cola-Mix', 'slug' => '2-zwei-cola-mix', 'image' => null],
            ['id' => 3, 'name' => 'Drei Cola-Mix', 'slug' => '3-drei-cola-mix', 'image' => null],
            ['id' => 4, 'name' => 'Vier Cola-Mix', 'slug' => '4-vier-cola-mix', 'image' => null],
            ['id' => 5, 'name' => 'Fünf Cola-Mix', 'slug' => '5-fuenf-cola-mix', 'image' => null],
        ], ['Auenperle Mix', 'Berggold Spezi', 'Flussgold Colamix', 'Hofperle ColaMix', 'Quellbub Cola-Mix']);

        self::assertStringContainsString('data-game="geo"', $geoHtml);
        self::assertStringContainsString('Fünf Runden', $geoHtml);
        self::assertStringContainsString('Endlos spielen', $geoHtml);
        self::assertStringContainsString('<p data-geo-name></p>', $geoHtml);
        self::assertStringNotContainsString('geo-game__crosshair', $geoHtml);
        self::assertStringContainsString('data-game="memory"', $memoryHtml);
        self::assertStringContainsString('data-memory-level="hard"', $memoryHtml);
        self::assertStringContainsString('data-memory-shuffle', $memoryHtml);
        self::assertStringContainsString('/assets/spezitest-icon-memory.svg', $memoryHtml);
        self::assertStringContainsString('data-game="real-fake"', $quizHtml);
        self::assertStringContainsString('data-quiz-card', $quizHtml);
        self::assertStringNotContainsString('Zehn Namen warten auf dein Urteil', $quizHtml);
        self::assertStringContainsString('class="quiz-choice quiz-choice--real"', $quizHtml);
        self::assertStringNotContainsString('data-quiz-next', $quizHtml);
        self::assertStringContainsString('Auenperle Mix', $quizHtml);
        self::assertStringNotContainsString('localStorage', $geoHtml . $memoryHtml . $quizHtml);
    }

    public function testPrivacyPageExplainsThatGamesDoNotPersistOrSubmitPlayData(): void
    {
        $html = $this->renderer->datenschutz();

        self::assertStringContainsString('<h2>Spiele</h2>', $html);
        self::assertStringContainsString('weder Cookies noch den lokalen Browserspeicher', $html);
        self::assertStringContainsString('nicht an uns übertragen', $html);
        self::assertStringContainsString('nicht direkt mit einem Kartenanbieter', $html);
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
