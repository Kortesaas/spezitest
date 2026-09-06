<?php

declare(strict_types=1);

namespace Spezitest\Website\View;

use Spezitest\Domain\Rating\PriceNormalizer;
use Spezitest\Website\Catalog\CatalogPage;
use Spezitest\Website\Catalog\CatalogQuery;
use Spezitest\Website\Catalog\OriginMap;
use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\Statistics;
use Spezitest\Website\Catalog\StreamEpisode;

/**
 * Renders the six public pages from real catalog data using the Spezitest
 * Design System classes. Pages never show placeholder or invented values: when
 * there is no data yet, an honest empty state is shown instead.
 */
final class WebsiteRenderer
{
    private const TESTERS = ['manu' => 'Manu', 'fabi' => 'Fabi', 'schorsch' => 'Schorsch'];

    private readonly string $siteUrl;

    private readonly StructuredData $schema;

    public function __construct(string $siteUrl = 'https://www.spezitest.de')
    {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->schema = new StructuredData($this->siteUrl);
    }

    /**
     * Wraps {@see Layout::page()} so every page carries the configured origin
     * and, unless it is unindexed (the 404), the shared Organization/WebSite
     * structured data plus whatever page-specific nodes the caller adds.
     *
     * @param list<array<string, mixed>> $schemaNodes
     */
    private function shell(
        string $title,
        string $main,
        string $active,
        ?string $description = null,
        ?string $path = null,
        array $schemaNodes = [],
        ?string $imagePath = null,
        string $ogType = 'website',
    ): string {
        $structuredData = $path === null ? '' : $this->schema->script($schemaNodes);

        return Layout::page(
            $title,
            $main,
            $active,
            $description,
            $path,
            $this->siteUrl,
            $structuredData,
            $imagePath,
            $ogType,
        );
    }

    public function home(RatedDrinkCollection $collection): string
    {
        $counts = $collection->lifecycleCounts();
        $ranked = $collection->ranked();
        $top = array_slice($ranked, 0, 5);
        $best = $top[0]->result ?? null;

        $hero = '<section class="wrap section"><div class="hero">'
            . '<div class="stack-lg"><div class="stack">'
            . '<span class="eyebrow eyebrow--accent">Spezitest</span>'
            . '<h1 class="display-1">' . Html::e($this->headline($counts['tested'])) . '</h1>'
            . '<p class="lede">Wir suchen Cola-Mixe aus ganz Deutschland und den Nachbarländern zusammen, kaufen sie '
            . 'und bewerten sie zu dritt nach Optik, Süffigkeit und Geschmack. '
            . 'Den Bewertungsprozess könnt ihr in unseren Livestreams verfolgen.</p></div>'
            . '<div class="cluster"><a class="btn btn--primary btn--lg" href="/spezis">Zum Katalog</a>'
            . '<a class="btn btn--secondary btn--lg" href="/ranking">Zum Ranking</a></div></div>';

        if ($top !== [] && $best !== null) {
            $leader = $top[0];
            $hero .= '<a class="winner" href="/spezi/' . Html::e($leader->slug()) . '">'
                . $this->productImage($leader, 'pimg--hero')
                . '<span class="winner__tag"><span class="winner__score">' . Html::gradeOfMax($best->gesamt(), Html::GESAMT_MAX) . '</span>'
                . '<span class="winner__label">Testsieger</span><span class="winner__name">' . Html::e($leader->name) . '</span></span></a>';
        } else {
            $hero .= '<div class="empty"><p class="empty__title">Noch kein Testsieger</p>'
                . '<p>Erscheint mit dem ersten abgeschlossenen Test.</p></div>';
        }

        $hero .= '</div></section>';

        $topSection = '<section class="section section--tint"><div class="wrap stack-lg">'
            . '<div class="cluster cluster--between"><h2 class="display-3">'
            . ($top === [] ? 'Bestenliste' : 'Top ' . count($top)) . '</h2>'
            . '<a class="link-arrow" href="/ranking">Ganzes Ranking</a></div>'
            . ($top === []
                ? '<div class="empty"><p class="empty__title">Noch keine getesteten Spezis</p><p>Füllt sich mit dem ersten abgeschlossenen Test.</p></div>'
                : '<div class="rank">' . $this->rankRows($top, 3) . '</div>')
            . '</div></section>';

        $worst = array_slice(array_reverse($ranked), 0, 5);
        $tailSection = '<section class="section"><div class="wrap split split--sidebar">'
            . '<div class="stack-lg"><div class="cluster cluster--between">'
            . '<h2 class="display-3">Schlusslichter</h2>'
            . '<a class="link-arrow" href="/ranking">Ganzes Ranking</a></div>'
            . ($worst === []
                ? '<div class="empty"><p class="empty__title">Noch nichts getestet</p></div>'
                : '<div class="rank">' . $this->rankRows($worst, 0) . '</div>')
            . '</div>'
            . '<aside><div class="panel card--strong sticky-side">'
            . '<span class="eyebrow eyebrow--accent">Noch gesucht</span>'
            . '<p class="figure__num" style="display:block;margin-top:var(--sp-3);font-size:var(--fs-d3)">' . $counts['identified'] . '</p>'
            . '<p class="meta" style="margin-top:var(--sp-2)">Spezis sind identifiziert, aber noch nicht im Kasten.</p>'
            . '<p style="margin-top:var(--sp-5)"><a class="btn btn--secondary btn--block" href="/spezis?status%5B%5D=identified">Liste ansehen</a></p></div>'
            . '</aside></div></section>';

        return $this->shell(
            'Start',
            $hero . $topSection . $tailSection . $this->figuresSection($collection),
            'start',
            'Cola-Mix und Spezi im Test: Katalog, Ranking und Statistik der Abteilung Spezitest.',
            '/',
        );
    }

    public function catalog(CatalogPage $page): string
    {
        $query = $page->query;

        $body = '<div class="wrap section" style="padding-bottom:var(--sp-5)">'
            . '<nav aria-label="Brotkrumen"><ol class="breadcrumb"><li><a href="/">Start</a></li><li>Spezis</li></ol></nav>'
            . '<div class="stack" style="margin-top:var(--sp-3)"><h1 class="display-3">Spezis</h1>'
            . '<form class="search-wrap" role="search" method="get" action="/spezis" data-suggest>'
            . '<div class="search">'
            . '<label class="visually-hidden" for="q">Spezi suchen</label>'
            . '<input id="q" name="q" type="search" placeholder="Marke, Hersteller, Region …" '
            . 'autocomplete="off" role="combobox" aria-expanded="false" aria-controls="q-suggest" '
            . 'aria-autocomplete="list" value="' . Html::e($query->search) . '">'
            . $this->hiddenSortField($query)
            . '<button type="submit">Suchen</button></div>'
            . '<ul class="suggest" id="q-suggest" role="listbox" aria-label="Vorschläge" hidden></ul>'
            . '</form></div></div>';

        $body .= '<div class="wrap" style="padding-bottom:var(--sp-9)"><div class="stack-lg">'
            . $this->catalogToolbar($page)
            // Everything below the toolbar is swapped in one piece when "Mehr
            // laden" is used with JavaScript, so it carries its own container.
            . '<div id="ergebnisse" class="stack-lg" data-catalog-results>'
            . $this->catalogResults($page)
            . '</div></div></div>';

        return $this->shell(
            'Spezis',
            $body,
            'spezis',
            'Alle katalogisierten Cola-Mix- und Spezi-Getränke mit Status und Gesamtwertung.',
            // Every filter, sort and page of the catalog is the same content in a
            // different order: they all canonicalise to the catalog root.
            '/spezis',
        );
    }

    public function detail(RatedDrink $drink, RatedDrinkCollection $collection): string
    {
        $result = $drink->result;
        // The subtitle carries everything the old "Details" section used to
        // repeat: maker, both origin fields when they differ, and the test date.
        $testedDate = Html::isoToGermanDate($drink->testedAt);
        $subtitleParts = array_values(array_unique(array_filter([
            $drink->manufacturer,
            $drink->originLocation,
            $drink->originRegion,
            $testedDate === null ? null : 'Getestet am ' . $testedDate,
        ])));

        $hero = '<div class="wrap" style="padding-top:var(--sp-4)"><nav aria-label="Brotkrumen"><ol class="breadcrumb">'
            . '<li><a href="/">Start</a></li><li><a href="/spezis">Spezis</a></li><li>' . Html::e($drink->name) . '</li></ol></nav></div>'
            . '<article><section class="wrap section" style="padding-top:var(--sp-5)"><div class="hero hero--detail">'
            . '<div>' . $this->productImage($drink, 'pimg--hero') . '</div>'
            . '<div class="stack-lg"><div class="stack">'
            // A compact badge: the state is context for the name, not a headline.
            . '<div class="cluster cluster--tight">' . Html::stateBadge($drink->lifecycleStatus)
            . '</div><h1 class="display-2">' . Html::e($drink->name) . '</h1>'
            . ($subtitleParts !== [] ? '<p class="lede">' . Html::e(implode(' · ', $subtitleParts)) . '</p>' : '')
            . '</div>';

        if ($result !== null) {
            $hero .= '<div class="verdict-row">'
                . ($drink->rank !== null
                    ? '<div class="verdict-rank"><span class="verdict-rank__num">#' . $drink->rank . '</span>'
                        . '<span class="verdict-rank__label">von ' . count($collection->tested()) . ' getesteten</span></div>'
                    : '')
                . '<div class="verdict-scores">'
                . '<div class="score"><span class="score__num">' . Html::grade($result->gesamt()) . '</span>'
                . '<span class="score__label">Gesamtwertung · 0–60</span></div>'
                . $this->priceScores($drink)
                . '</div></div>'
                . $this->streamLink($drink)
                . $this->ratingBreakdown($drink);
        } else {
            $hero .= '<div class="notice"><span>Noch nicht getestet. Wertung und Einzelnoten folgen nach dem Testabend.</span></div>';
        }

        $hero .= '</div></div></section>';

        $body = $hero . $this->previousNextNav($drink, $collection) . '</article>';

        return $this->shell(
            $drink->name,
            $body,
            'spezis',
            $result !== null
                ? $drink->name . ' im Spezitest: Gesamtwertung ' . Html::grade($result->gesamt()) . '.'
                : $drink->name . ' im Spezitest-Katalog.',
            '/spezi/' . $drink->slug(),
            [
                $this->schema->breadcrumb([
                    ['name' => 'Start', 'path' => '/'],
                    ['name' => 'Spezis', 'path' => '/spezis'],
                    ['name' => $drink->name, 'path' => null],
                ]),
                $this->schema->drink($drink, count($collection->tested())),
            ],
            // Share the Spezi's own photo when there is one, so a link to this
            // page previews the bottle rather than the generic card.
            $drink->hasImage ? '/spezi/' . $drink->id . '/bild' : null,
            'article',
        );
    }

    public function ranking(RatedDrinkCollection $collection): string
    {
        $ranked = $collection->ranked();
        $podium = array_slice($ranked, 0, 3);
        $rest = array_slice($ranked, 3);

        $band = '<div class="band"><div class="wrap band__inner"><div class="cluster cluster--between" style="align-items:flex-end">'
            . '<h1 class="display-2" style="color:#fff">Ranking</h1>'
            . '<p style="font-weight:700;font-size:var(--fs-body-lg)">'
            . ($ranked === []
                ? 'Noch kein Test abgeschlossen.'
                : count($ranked) . ' getestete Spezis · nach Gesamtwertung (0–60)')
            . '</p></div></div></div>';

        if ($ranked === []) {
            return $this->shell('Ranking', $band . '<div class="wrap section"><div class="empty">'
                . '<p class="empty__title">Noch kein Ranking</p>'
                . '<p>Es wurde noch kein Test abgeschlossen.</p></div></div>', 'ranking', null, '/ranking');
        }

        $body = $band . '<div class="wrap section">'
            . ($podium !== [] ? '<div class="podium" style="margin-block:var(--sp-6)">' . $this->podium($podium) . '</div>' : '')
            . '<div class="rank">' . $this->rankRows($rest, 0) . '</div>'
            . '<p class="meta" style="margin-top:var(--sp-6)">Gesamtwertung = Optik ×1 + Süffigkeit ×2 + Geschmack ×3, je 0–10. '
            . '<a href="/ueber#methode">Methode</a></p>'
            . '</div>';

        return $this->shell(
            'Ranking',
            $body,
            'ranking',
            'Das vollständige Spezitest-Ranking nach Gesamtwertung.',
            '/ranking',
            [$this->schema->ranking(array_slice($ranked, 0, 25))],
        );
    }

    public function statistik(Statistics $stats, OriginMap $map): string
    {
        $intro = '<section class="wrap section">'
            . '<h1 class="display-2">Statistik</h1>';

        if ($stats->testedCount === 0) {
            $intro .= '<div class="empty" style="margin-top:var(--sp-6)"><p class="empty__title">Noch keine Auswertung</p>'
                . '<p>Erscheint mit dem ersten abgeschlossenen Test. Erfasst: ' . $stats->total . '.</p></div></section>';

            return $this->shell('Statistik', $intro, 'statistik', null, '/statistik');
        }

        $intro .= '<div class="figure-row" style="margin-top:var(--sp-6)">'
            . $this->figure((string) $stats->testedCount, 'getestet')
            . $this->figure((string) $stats->total, 'im Katalog')
            . $this->figure((string) $stats->lifecycleCounts['identified'], 'noch gesucht')
            . $this->figure(Html::gradeOrDash($stats->averageGesamt), 'Ø Gesamtwertung')
            . '</div></section>';

        $distribution = '<section class="section section--tint"><div class="wrap split">'
            . '<div class="stack-lg"><div class="stack"><span class="eyebrow">Verteilung</span>'
            . '<h2 class="display-3">Gesamtwertungen</h2><p class="meta">Anzahl Spezis je 10-Punkte-Bereich.</p></div>'
            . '<div class="barchart">' . $this->distributionRows($stats) . '</div></div>'
            . '<div class="stack-lg"><div class="stack"><span class="eyebrow">Tester</span>'
            . '<h2 class="display-3">Wer wertet strenger?</h2>'
            . '<p class="meta">Durchschnittliche Einzelnote über alle Tests, 0 bis 10.</p></div>'
            . '<div class="barchart">' . $this->testerAverageRows($stats) . '</div>'
            . '<hr class="rule rule--hair"><div class="stack"><span class="eyebrow">Kriterien im Schnitt</span>'
            . '<div class="barchart">' . $this->categoryAverageRows($stats) . '</div></div></div>'
            . '</div></section>';

        return $this->shell(
            'Statistik',
            $intro . $this->originMapSection($map) . $distribution
                . $this->priceLeistungSection($stats),
            'statistik',
            'Auswertung der Spezitest-Testabende: Herkunftskarte, Verteilung, Tester und Preis/Leistung.',
            '/statistik',
        );
    }

    private function priceLeistungSection(Statistics $stats): string
    {
        if ($stats->pricedCount === 0) {
            return '<section class="section section--tint" id="preis-leistung"><div class="wrap stack"><span class="eyebrow">Preis / Leistung</span>'
                . '<h2 class="display-3">Noch keine Preise erfasst</h2>'
                . '<p class="meta">Erscheint, sobald eine getestete Spezi einen Preis hat.</p></div></section>';
        }

        $bestCaption = $stats->bestValue !== null
            ? '<p class="meta">Bestes Verhältnis: <a href="/spezi/' . Html::e($stats->bestValue['slug']) . '">'
                . Html::e($stats->bestValue['name']) . '</a> (' . Html::price((string) $stats->bestValue['price'])
                . ' je 0,5 l · ' . Html::gradeOfMax($stats->bestValue['gesamt'], Html::GESAMT_MAX) . ').</p>'
            : '';

        $figures = '<div class="figure-row">'
            . $this->figure((string) $stats->pricedCount, 'mit Preis erfasst')
            . $this->figure(
                $stats->averagePriceHalfLiter !== null ? Html::price((string) $stats->averagePriceHalfLiter) : '–',
                'Ø Preis je 0,5 l',
            )
            . $this->figure(
                $stats->bestValue !== null ? Html::grade($stats->bestValue['score'] * 100, 0) : '–',
                'beste Preis/Leistung · von 100',
            )
            . '</div>';

        return '<section class="section section--tint" id="preis-leistung"><div class="wrap stack-lg">'
            . '<div class="stack"><span class="eyebrow">Preis / Leistung</span>'
            . '<h2 class="display-3">Wer ist sein Geld wert?</h2>'
            . '<p class="meta">Jeder Punkt eine getestete Spezi: Preis je 0,5 l gegen Gesamtwertung. Zum Nachschauen '
            . 'antippen oder mit der Maus darüberfahren.</p></div>'
            . $figures . $bestCaption . $this->priceScatterChart($stats)
            . '</div></section>';
    }

    /**
     * The scale behind the scatter plot: horizontal rules every ten points of
     * the Gesamtwertung and four price steps across, each with a small label.
     * Purely a reading aid — no data of its own.
     */
    private function scatterGrid(
        float $minPrice,
        float $maxPrice,
        float $padLeft,
        float $padTop,
        float $plotWidth,
        float $plotHeight,
    ): string {
        $lines = '';
        $labels = '';

        for ($score = 0; $score <= Html::GESAMT_MAX; $score += 10) {
            $y = round($padTop + (1 - $score / Html::GESAMT_MAX) * $plotHeight, 1);
            $lines .= '<line x1="' . $padLeft . '" y1="' . $y . '" x2="' . round($padLeft + $plotWidth, 1)
                . '" y2="' . $y . '"' . ($score === 0 ? ' class="is-axis"' : '') . '></line>';

            if ($score % 20 === 0) {
                $labels .= '<text class="scatter__tick" x="' . round($padLeft - 5, 1) . '" y="' . round($y + 2.4, 1)
                    . '" text-anchor="end">' . $score . '</text>';
            }
        }

        $steps = 4;
        $span = $maxPrice - $minPrice;

        for ($step = 0; $step <= $steps; ++$step) {
            $x = round($padLeft + $plotWidth * $step / $steps, 1);
            $lines .= '<line x1="' . $x . '" y1="' . $padTop . '" x2="' . $x . '" y2="'
                . round($padTop + $plotHeight, 1) . '"></line>';

            if ($step % 2 !== 0) {
                continue;
            }

            $anchor = match ($step) {
                0 => 'start',
                $steps => 'end',
                default => 'middle',
            };
            $labels .= '<text class="scatter__tick" x="' . $x . '" y="' . round($padTop + $plotHeight + 11, 1)
                . '" text-anchor="' . $anchor . '">'
                . Html::e(Html::price((string) ($minPrice + $span * $step / $steps))) . '</text>';
        }

        return '<g class="scatter__grid" aria-hidden="true">' . $lines . $labels . '</g>';
    }

    private function priceScatterChart(Statistics $stats): string
    {
        $points = $stats->priceScatter;

        if (count($points) < 2) {
            return '<p class="meta">Braucht mindestens zwei bepreiste Tests für die Übersicht.</p>';
        }

        $prices = array_map(static fn (array $point): float => $point['price'], $points);
        $minPrice = min($prices);
        $priceSpan = max($prices) - $minPrice;

        // A wide, shallow box: 125 points need horizontal room, and the plot
        // is height-capped in CSS so a wider ratio also fills more of the page.
        $width = 480.0;
        $height = 200.0;
        $padLeft = 30.0;
        $padRight = 14.0;
        $padTop = 12.0;
        $padBottom = 26.0;
        $plotWidth = $width - $padLeft - $padRight;
        $plotHeight = $height - $padTop - $padBottom;

        $grid = $this->scatterGrid(
            $minPrice,
            $minPrice + $priceSpan,
            $padLeft,
            $padTop,
            $plotWidth,
            $plotHeight,
        );
        $dots = '';

        foreach ($points as $point) {
            $xShare = $priceSpan > 0.0 ? ($point['price'] - $minPrice) / $priceSpan : 0.5;
            $yShare = max(0.0, min(1.0, $point['gesamt'] / Html::GESAMT_MAX));
            $x = round($padLeft + $xShare * $plotWidth, 1);
            $y = round($padTop + (1 - $yShare) * $plotHeight, 1);
            $scoreLabel = $point['score'] !== null ? Html::grade($point['score'] * 100, 0) . ' / 100' : 'k. A.';

            $dots .= '<a class="scatter__dot map__dot" href="/spezi/' . Html::e($point['slug']) . '"'
                . ' data-scatter-name="' . Html::e($point['name']) . '"'
                . ' data-scatter-price="' . Html::e(Html::price((string) $point['price'])) . ' je 0,5 l"'
                . ' data-scatter-gesamt="' . Html::e(Html::gradeOfMax($point['gesamt'], Html::GESAMT_MAX)) . '"'
                . ' data-scatter-score="' . Html::e($scoreLabel) . '">'
                . '<circle class="map__halo" cx="' . $x . '" cy="' . $y . '" r="9"></circle>'
                . '<circle class="map__pin" cx="' . $x . '" cy="' . $y . '" r="4"></circle>'
                . '<title>' . Html::e($point['name']) . '</title></a>';
        }

        return '<figure class="scatter" data-scatter>'
            . '<svg class="scatter__plot" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" '
            . 'aria-label="Streudiagramm: Preis je 0,5 Liter gegen Gesamtwertung, ein Punkt je getestete Spezi" '
            . 'preserveAspectRatio="xMidYMid meet">' . $grid . $dots . '</svg>'
            . '<figcaption class="scatter__caption">'
            . '<span>← günstiger je 0,5 l</span><span>höhere Wertung ↑</span><span>teurer →</span></figcaption>'
            . '<div class="scatter__readout" data-scatter-readout hidden></div>'
            . '</figure>';
    }

    /**
     * The streams overview: one card per Testabend, newest first. The card
     * opens that evening's own page; a second link opens the recording.
     *
     * @param array<int, ?string> $recordedOn recording dates keyed by episode number
     */
    public function streams(RatedDrinkCollection $collection, array $recordedOn = []): string
    {
        $episodes = $this->episodes($collection, $recordedOn);

        $head = '<div class="wrap section" style="padding-bottom:var(--sp-6)">'
            . '<nav aria-label="Brotkrumen"><ol class="breadcrumb"><li><a href="/">Start</a></li><li>Streams</li></ol></nav>'
            . '<div class="stack" style="margin-top:var(--sp-3)"><h1 class="display-2">Streams</h1>'
            . '<p class="lede">Jeder Testabend ist als Aufzeichnung online. Ein Klick auf einen Abend zeigt, '
            . 'was dort verkostet wurde. Jede Zeile springt direkt an die passende Stelle im Video.</p></div></div>';

        if ($episodes === []) {
            return $this->shell(
                'Streams',
                $head . '<div class="wrap" style="padding-bottom:var(--sp-9)"><div class="empty">'
                    . '<p class="empty__title">Noch keine Aufzeichnung</p>'
                    . '<p>Hier erscheinen die Testabende, sobald sie gestreamt wurden.</p></div></div>',
                'streams',
                null,
                '/streams',
            );
        }

        $tasted = 0;
        $seconds = 0;

        foreach ($episodes as $episode) {
            $tasted += $episode->count();
            $seconds += $episode->tastingSeconds ?? 0;
        }

        $figures = '<div class="figure-row" style="margin-bottom:var(--sp-7)">'
            . $this->figure((string) count($episodes), count($episodes) === 1 ? 'Testabend' : 'Testabende')
            . $this->figure((string) $tasted, 'Spezis verkostet')
            . $this->figure($seconds === 0 ? '–' : (string) (int) round($seconds / 3600) . ' h', 'reine Verkostungszeit')
            . $this->figure(
                $tasted === 0 || $seconds === 0 ? '–' : StreamEpisode::minutes((int) round($seconds / $tasted)) ?? '–',
                'Ø je Spezi',
            )
            . '</div>';

        $cards = '';

        foreach ($episodes as $episode) {
            $cards .= $this->episodeCard($episode);
        }

        return $this->shell(
            'Streams',
            $head . '<div class="wrap" style="padding-bottom:var(--sp-9)">'
                . $figures . '<div class="grid grid--2">' . $cards . '</div></div>',
            'streams',
            'Alle Spezitest-Testabende als Aufzeichnung, mit Sprungmarke zu jeder verkosteten Spezi.',
            '/streams',
        );
    }

    /**
     * One Testabend in full: the evening in numbers, then what was tasted in
     * the order it happened, each row jumping into the recording.
     *
     * @param array<int, ?string> $recordedOn
     */
    public function stream(int $number, RatedDrinkCollection $collection, array $recordedOn = []): ?string
    {
        $episodes = $this->episodes($collection, $recordedOn);
        $episode = null;
        $position = null;

        foreach ($episodes as $index => $candidate) {
            if ($candidate->number === $number) {
                $episode = $candidate;
                $position = $index;
            }
        }

        if ($episode === null || $position === null) {
            return null;
        }

        // The list runs newest first, so the earlier evening is the next entry.
        $newer = $episodes[$position - 1] ?? null;
        $older = $episodes[$position + 1] ?? null;

        $rows = '';
        $index = 0;

        foreach ($episode->drinks as $drink) {
            ++$index;
            $rows .= $this->tastingRow($drink, $index);
        }

        $body = '<div class="wrap section" style="padding-bottom:var(--sp-5)">'
            . '<nav aria-label="Brotkrumen"><ol class="breadcrumb"><li><a href="/">Start</a></li>'
            . '<li><a href="/streams">Streams</a></li><li>Testabend ' . $episode->number . '</li></ol></nav>'
            . '<div class="stack" style="margin-top:var(--sp-3)">'
            . '<span class="eyebrow eyebrow--accent">Testabend ' . $episode->number . '</span>'
            . '<h1 class="display-2">' . Html::e($episode->title) . '</h1>'
            . '<p class="meta">' . $episode->count() . ' Spezis verkostet'
            . ($episode->recordedOn === null
                ? ''
                : ' · aufgezeichnet am ' . Html::e(Html::isoToGermanDate($episode->recordedOn) ?? $episode->recordedOn))
            . '</p></div>'
            . ($episode->url === null
                ? '<p class="notice" style="margin-top:var(--sp-5)"><span>Die Aufzeichnung dieses Abends ist noch nicht verlinkt.</span></p>'
                : '<p style="margin-top:var(--sp-5)"><a class="btn btn--primary btn--lg" href="' . Html::e($episode->url) . '" '
                    . 'target="_blank" rel="noopener noreferrer">Stream auf YouTube ansehen</a></p>')
            . $this->episodeFigures($episode)
            . '</div>'
            . $this->episodeFacts($episode)
            . '<section class="section section--tint"><div class="wrap stack-lg">'
            . '<div class="cluster cluster--between"><h2 class="display-3">Verkostet an diesem Abend</h2>'
            . ($episode->url === null ? '' : '<p class="meta">Jede Zeile springt ins Video.</p>') . '</div>'
            . '<ol class="tasting">' . $rows . '</ol></div></section>'
            . $this->episodePager($newer, $older);

        return $this->shell(
            'Testabend ' . $episode->number,
            $body,
            'streams',
            $episode->title . ': ' . $episode->count() . ' Spezis im Test, mit Sprungmarken ins Video.',
            '/streams/' . $episode->number,
            [
                $this->schema->breadcrumb([
                    ['name' => 'Start', 'path' => '/'],
                    ['name' => 'Streams', 'path' => '/streams'],
                    ['name' => 'Testabend ' . $episode->number, 'path' => null],
                ]),
                $this->schema->streamEpisode($episode),
            ],
            null,
            'article',
        );
    }

    /**
     * @param array<int, ?string> $recordedOn
     * @return list<StreamEpisode>
     */
    private function episodes(RatedDrinkCollection $collection, array $recordedOn): array
    {
        return array_map(
            static fn (StreamEpisode $episode): StreamEpisode
                => $episode->withRecordedOn($recordedOn[$episode->number] ?? null),
            StreamEpisode::fromCollection($collection),
        );
    }

    private function episodeFigures(StreamEpisode $episode): string
    {
        return '<div class="figure-row" style="margin-top:var(--sp-6)">'
            . $this->figure((string) $episode->count(), 'Spezis verkostet')
            . $this->figure(Html::gradeOrDash($episode->averageGesamt), 'Ø Gesamtwertung')
            . $this->figure(StreamEpisode::minutes($episode->averageSeconds) ?? '–', 'Ø je Spezi')
            . $this->figure(
                $episode->tastingSeconds === null ? '–' : StreamEpisode::clock($episode->tastingSeconds) ?? '–',
                'reine Verkostungszeit',
            )
            . '</div>';
    }

    /** The evening's superlatives, each linking to the Spezi it names. */
    private function episodeFacts(StreamEpisode $episode): string
    {
        $facts = [
            ['Beste Spezi des Abends', $episode->best, static fn (RatedDrink $d): string
                => $d->result === null ? '' : Html::gradeOfMax($d->result->gesamt(), Html::GESAMT_MAX)],
            ['Schlusslicht des Abends', $episode->worst, static fn (RatedDrink $d): string
                => $d->result === null ? '' : Html::gradeOfMax($d->result->gesamt(), Html::GESAMT_MAX)],
            ['Längste Verkostung', $episode->longest, static fn (RatedDrink $d): string
                => StreamEpisode::minutes($d->stream->durationSeconds ?? null) ?? ''],
            ['Kürzeste Verkostung', $episode->shortest, static fn (RatedDrink $d): string
                => StreamEpisode::minutes($d->stream->durationSeconds ?? null) ?? ''],
        ];

        $cards = '';

        foreach ($facts as [$label, $drink, $value]) {
            if ($drink === null) {
                continue;
            }

            $cards .= '<div class="episode-fact">'
                . '<span class="episode-fact__label">' . Html::e($label) . '</span>'
                . '<a class="episode-fact__name" href="/spezi/' . Html::e($drink->slug()) . '">'
                . Html::e($drink->name) . '</a>'
                . '<span class="episode-fact__value">' . Html::e($value($drink)) . '</span></div>';
        }

        if ($cards === '') {
            return '';
        }

        return '<div class="wrap" style="padding-bottom:var(--sp-7)">'
            . '<div class="episode-facts">' . $cards . '</div></div>';
    }

    private function episodeCard(StreamEpisode $episode): string
    {
        // Only bottles that actually have a photo go in the strip; a
        // placeholder there would read as a gap, not as a missing image.
        $withImage = array_values(array_filter(
            $episode->drinks,
            static fn (RatedDrink $drink): bool => $drink->hasImage,
        ));
        $strip = '';

        foreach (array_slice($withImage, 0, 6) as $drink) {
            $strip .= '<span class="episode-card__thumb">' . $this->productImage($drink, 'pimg--bare') . '</span>';
        }

        $rest = $episode->count() - min(6, count($withImage));

        if ($rest > 0) {
            $strip .= '<span class="episode-card__more">+' . $rest . '</span>';
        }

        $meta = [$episode->count() . ' ' . ($episode->count() === 1 ? 'Spezi' : 'Spezis')];

        if ($episode->averageGesamt !== null) {
            $meta[] = 'Ø ' . Html::grade($episode->averageGesamt);
        }

        if ($episode->averageSeconds !== null) {
            $meta[] = (StreamEpisode::minutes($episode->averageSeconds) ?? '') . ' je Spezi';
        }

        return '<article class="card episode-card">'
            . '<div class="episode-card__strip">' . $strip . '</div>'
            . '<div class="card__body">'
            . '<span class="eyebrow eyebrow--accent">Testabend ' . $episode->number
            . ($episode->recordedOn === null
                ? ''
                : ' · ' . Html::e(Html::isoToGermanDate($episode->recordedOn) ?? $episode->recordedOn))
            . '</span>'
            . '<h2 class="card__title"><a class="episode-card__link" href="/streams/' . $episode->number . '">'
            . Html::e($episode->title) . '</a></h2>'
            . '<p class="meta">' . Html::e(implode(' · ', $meta)) . '</p>'
            . ($episode->best === null
                ? ''
                : '<p class="meta">Siegerin des Abends: <a href="/spezi/' . Html::e($episode->best->slug()) . '">'
                    . Html::e($episode->best->name) . '</a></p>')
            . '<div class="episode-card__actions">'
            . '<a class="btn btn--secondary btn--sm" href="/streams/' . $episode->number . '">Verkostungen ansehen</a>'
            . ($episode->url === null
                ? '<span class="meta">Aufzeichnung folgt</span>'
                : '<a class="btn btn--ghost btn--sm episode-card__watch" href="' . Html::e($episode->url) . '" '
                    . 'target="_blank" rel="noopener noreferrer">Auf YouTube</a>')
            . '</div></div></article>';
    }

    private function tastingRow(RatedDrink $drink, int $position): string
    {
        $segment = $drink->stream;
        $result = $drink->result;
        $watch = $segment?->watchUrl();
        $offset = $segment?->formattedOffset();
        $duration = StreamEpisode::minutes($segment->durationSeconds ?? null);

        return '<li class="tasting__row">'
            . '<span class="tasting__pos">' . $position . '</span>'
            . $this->productImage($drink, 'pimg--thumb')
            . '<span class="tasting__text">'
            . '<a class="tasting__name" href="/spezi/' . Html::e($drink->slug()) . '">' . Html::e($drink->name) . '</a>'
            . '<span class="tasting__sub">' . Html::e($drink->manufacturer ?? $drink->displayOrigin() ?? '—')
            . ($duration === null ? '' : ' · ' . Html::e($duration)) . '</span>'
            . '</span>'
            . '<span class="tasting__score">'
            . ($result === null ? '–' : Html::grade($result->gesamt()))
            . '<small>' . ($drink->rank === null ? 'Wertung' : 'Platz ' . $drink->rank) . '</small></span>'
            . ($watch === null || $offset === null
                ? '<span class="tasting__jump tasting__jump--none">' . Html::e($offset ?? '–') . '</span>'
                : '<a class="tasting__jump" href="' . Html::e($watch) . '" target="_blank" rel="noopener noreferrer">'
                    . '<span>' . Html::e($offset) . '</span>'
                    . '<span class="visually-hidden">' . Html::e($drink->name) . ' im Stream ansehen</span></a>')
            . '</li>';
    }

    private function episodePager(?StreamEpisode $newer, ?StreamEpisode $older): string
    {
        if ($newer === null && $older === null) {
            return '';
        }

        $link = static function (?StreamEpisode $episode, string $direction, string $label): string {
            if ($episode === null) {
                return '';
            }

            return '<a class="neighbour neighbour--' . $direction . '" href="/streams/' . $episode->number . '">'
                . '<span class="neighbour__arrow" aria-hidden="true"></span>'
                . '<span class="neighbour__text">'
                . '<span class="neighbour__label">' . Html::e($label) . '</span>'
                . '<span class="neighbour__name">' . Html::e($episode->title) . '</span></span></a>';
        };

        return '<section class="wrap section" style="padding-top:0">'
            . '<nav class="pager-nav" aria-label="Weitere Testabende">'
            . $link($older, 'prev', 'Früherer Testabend')
            . $link($newer, 'next', 'Späterer Testabend')
            . '</nav></section>';
    }
    public function ueber(RatedDrinkCollection $collection): string
    {
        $counts = $collection->lifecycleCounts();
        $body = '<section class="wrap section"><div class="split" style="align-items:center">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Über das Projekt</span>'
            . '<h1 class="display-2">Fabi, Manu und Schorsch trinken alle Spezis.</h1>'
            . '<p class="lede">Ein Hobbyprojekt von drei Leuten mit einer selbstgestellten Aufgabe: möglichst '
            . 'jede Spezi auftreiben, selbst kaufen und nach immer denselben Kriterien bewerten. Bisher '
            . $counts['tested'] . ' ' . ($counts['tested'] === 1 ? 'getestete Spezi' : 'getestete Spezis') . '.</p></div>'
            . '<figure class="team-photo"><img src="/assets/spezitest-team.jpg" '
            . 'alt="Manu, Fabi und Schorsch hinter einem Tisch voller Cola-Mix-Flaschen" '
            . 'width="1600" height="921" loading="lazy"></figure></div></section>'

            . '<section class="section section--tint" id="methode"><div class="wrap split split--sidebar">'
            . '<div class="prose stack-lg"><div class="stack"><span class="eyebrow">Methode</span>'
            . '<h2 class="display-3">Wie getestet wird</h2></div>'
            . '<p>Gleiche Temperatur, gleiches Glas. Jeder der drei Tester vergibt für Optik, Süffigkeit und '
            . 'Geschmack eine Note von 0 bis 10. Höher ist besser.</p>'
            . '<h3>Optik</h3><p>Farbe im Glas, Kohlensäure, Schaum, Flasche oder Dose.</p>'
            . '<h3>Süffigkeit</h3><p>Wie leicht sich das Glas leert. Süße, Säure, Abgang.</p>'
            . '<h3>Geschmack</h3><p>Verhältnis von Cola zu Orange, Aromatik, Eigenständigkeit.</p>'
            . '<h3>Gesamtwertung</h3><p>Gewichtet: Optik ×1, Süffigkeit ×2, Geschmack ×3. Ergebnis 0 bis 60.</p>'
            . '<h3>Preis / Leistung</h3><p>Nur, wenn ein Preis erfasst wurde. Verglichen wird auf Basis '
            . 'des Preises je 0,5 l.</p>'
            . '<p class="meta">Alle Flaschen kaufen wir selbst. Es gibt keine bezahlten Tests, keine '
            . 'Kooperationen und keine nachträglichen Änderungen an der Methodik. Auch dann nicht, wenn '
            . 'uns ein Ergebnis nicht passt.</p></div>'
            . '<aside class="stack-lg"><div class="panel">'
            . '<span class="eyebrow">Spezi-Lifecycle</span>'
            . '<p class="meta" style="margin-top:var(--sp-2)">Jeder Eintrag durchläuft dieselben drei Zustände '
            . '– und hat immer genau einen davon.</p>'
            . '<div style="margin-top:var(--sp-5);display:flex;flex-direction:column">'
            . $this->lifecycleStep(
                'identified',
                'Wir wissen, dass es die Spezi gibt – gesehen, aber noch nicht im Kasten.',
                false,
            )
            . $this->lifecycleStep(
                'acquired',
                'Mindestens eine Flasche steht bei uns und wartet auf den Testabend.',
                false,
            )
            . $this->lifecycleStep(
                'tested',
                'Zu dritt verkostet und nach denselben Regeln bewertet.',
                true,
            )
            . '</div></div></aside></div></section>'

            . '<section class="wrap section" id="tester" style="padding-bottom:var(--sp-6)"><div class="stack-lg">'
            . '<div class="stack"><span class="eyebrow">Die Spezitester</span><h2 class="display-3">Was man über uns wissen muss:</h2></div>'
            . '<div class="grid grid--3">'
            . $this->testerCard('Manu', 'Trinkt sehr gerne Spezi.', 'manu')
            . $this->testerCard('Fabi', 'Trinkt auch sehr gerne Spezi.', 'fabi')
            . $this->testerCard('Schorsch', 'Trinkt auch sehr gerne Spezi.', 'schorsch')
            . '</div></div></section>'

            . '<section class="wrap section" id="projekt" style="padding-block:var(--sp-6)"><div class="prose stack-lg">'
            . '<div class="stack"><span class="eyebrow">Zur Einordnung</span>'
            . '<h2 class="display-3">Privatvergnügen, kein Geschäft!</h2></div>'
            . '<p>Spezitest ist ein Hobby. Wir verdienen hier nichts: keine Werbung, keine Affiliate-Links, '
            . 'keine gesponserten Beiträge, nichts zu kaufen. Die Getränke zahlen wir selbst, und kein '
            . 'Hersteller hat Einfluss darauf, was wir schreiben oder wie wir werten.</p>'
            . '<p>„Spezi“ ist eine eingetragene Marke, und wir gehören nicht dazu. Wir benutzen das Wort so, '
            . 'wie es hierzulande am Tresen benutzt wird: als Sammelbegriff für Cola-Mix, egal von wem. '
            . 'Genauso stehen alle anderen Marken- und Produktnamen hier nur deshalb, weil wir über genau '
            . 'dieses Getränk schreiben. Sie gehören ihren Inhabern. '
            . '<a href="/impressum#marken">Mehr dazu im Impressum.</a></p>'
            . '<p>Und was hier steht, sind Geschmacksurteile von drei Privatleuten, keine Laborwerte. '
            . 'Wer anderer Meinung ist, hat vermutlich recht.</p>'
            . '</div></section>'

            . '<section class="section section--navy"><div class="wrap on-navy split" style="align-items:center">'
            . '<h2 class="display-3" style="color:#fff">Fehlt uns eine Spezi?</h2>'
            . '<div class="stack"><p class="lede" style="color:rgba(255,255,255,.86)">Erst im Katalog nachsehen. '
            . 'Was dort fehlt, suchen wir.</p>'
            . '<div class="cluster"><a class="btn btn--on-navy" href="/spezis">Katalog prüfen</a></div></div></div></section>';

        return $this->shell(
            'Über Spezitest',
            $body,
            'ueber',
            'Testmethode, Tester und Selbstverständnis hinter Spezitest. Ein privates, nicht kommerzielles Projekt.',
            '/ueber',
        );
    }

    /**
     * Legal notice, carried over from the previous spezitest.de site. The
     * operator address was corrected to Zeppelinstraße 16 1/2.
     */
    public function impressum(): string
    {
        $body = '<section class="wrap section"><div class="prose stack-lg">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Rechtliches</span>'
            . '<h1 class="display-2">Impressum</h1></div>'

            . '<div><h2>Was das hier ist</h2>'
            . '<p>Spezitest ist ein privates Hobbyprojekt von drei Leuten, die zu viel Cola-Mix trinken. '
            . 'Wir verkaufen nichts, wir schalten keine Werbung, wir nehmen kein Geld für Tests und wir '
            . 'arbeiten mit keinem der hier genannten Hersteller zusammen. Die Seite kostet uns Geld statt '
            . 'welches einzubringen. So soll es auch bleiben.</p></div>'

            . '<div><h2>Warum hier eine Firma steht</h2>'
            . '<p>Ein Impressum braucht eine ladungsfähige Anschrift und jemanden, den man erreichen kann. '
            . 'Fabian, einer von uns dreien, ist Geschäftsführer der ABOUT US Media GmbH. Deshalb laufen '
            . 'Post und Anfragen zu Spezitest über deren Anschrift, statt dass wir hier drei private '
            . 'Wohnadressen ins Netz stellen. Die GmbH steht hier also als Kontakt- und Zustelladresse.</p>'
            . '<p>Kommerziell wird das Projekt dadurch nicht: Spezitest ist kein Angebot der Firma, es gibt '
            . 'keine Einnahmen, keine Werbung, keinen Auftrag und keine Kundenbeziehung zu irgendeinem '
            . 'Getränkehersteller. Die Kästen zahlen wir privat.</p></div>'

            . '<div><h2>Anbieter</h2>'
            . '<p>ABOUT US Media GmbH<br>Zeppelinstraße 16 1/2<br>86343 Königsbrunn</p></div>'
            . '<div><h2>Kontakt</h2>'
            . '<p>E-Mail: <a href="mailto:hallo@aboutusmedia.de">hallo@aboutusmedia.de</a><br>'
            . 'Telefon: <a href="tel:+4915902608764">+49 1590 2608764</a></p></div>'
            . '<div><h2>Registereintrag</h2>'
            . '<p>Handelsregister: HRB 37022<br>Registergericht: Amtsgericht Augsburg</p></div>'
            . '<div><h2>Umsatzsteuer</h2>'
            . '<p>Umsatzsteuer-Identifikationsnummer gem. § 27a UStG: DE350459301</p></div>'
            . '<div><h2>Vertretungsberechtigter Geschäftsführer</h2>'
            . '<p>Fabian Heißerer<br>'
            . 'E-Mail: <a href="mailto:fabian@aboutusmedia.de">fabian@aboutusmedia.de</a><br>'
            . 'Telefon: <a href="tel:+4917681646809">+49 176 81646809</a></p></div>'
            . '<div><h2>Inhaltlich Verantwortlicher gem. § 18 Abs. 2 MStV</h2>'
            . '<p>Fabian Heißerer (Anschrift und Kontakt s.&nbsp;o.)</p></div>'

            . '<div id="marken"><h2>Marken und Produktnamen</h2>'
            . '<p>„Spezi“ ist eine eingetragene Marke. Wir gehören nicht dazu und geben auch nicht vor, dazu '
            . 'zu gehören. Im deutschen Sprachgebrauch ist „Spezi“ längst das Wort, mit dem man ein '
            . 'Cola-Mix-Getränk bestellt, egal von welchem Hersteller. Genau so benutzen wir es hier: '
            . 'beschreibend, als Gattungsbegriff für die Getränkeart, um die es auf dieser Seite geht.</p>'
            . '<p>Dasselbe gilt für alle anderen Marken-, Produkt- und Herstellernamen auf dieser Seite. Sie '
            . 'gehören ihren jeweiligen Inhabern. Wir nennen sie, weil wir über genau dieses Getränk '
            . 'schreiben. Das ist weder eine Empfehlung des Herstellers an uns noch eine Zusammenarbeit in '
            . 'irgendeine Richtung.</p>'
            . '<p>Die Produktfotos zeigen die Flaschen und Dosen, die wir selbst gekauft und getestet '
            . 'haben. Wir fotografieren sie zu Hause und bearbeiten die Bilder nach. Teilweise nutzen wir '
            . 'dafür auch KI-Werkzeuge, etwa um Hintergrund, Ausschnitt oder Ausleuchtung zu '
            . 'vereinheitlichen. Es sind also keine offiziellen Herstellerfotos. Sie sind auch nicht '
            . 'als originalgetreue Abbildung der Verpackung gedacht, sondern sollen im Katalog nur '
            . 'zeigen, um welches Getränk es geht. Im Zweifel gilt immer das, was tatsächlich im '
            . 'Regal steht.</p>'
            . '<p>Unsere Bewertungen sind persönliche Geschmacksurteile von drei Privatpersonen an einem '
            . 'Küchentisch. Sie sind keine Warentests im Sinne eines Prüfinstituts und erheben keinen '
            . 'Anspruch auf Objektivität.</p>'
            . '<p>Wenn ein Rechteinhaber mit etwas auf dieser Seite ein Problem hat: kurze Mail an die oben '
            . 'genannte Adresse genügt. Wir nehmen das ernst und ändern oder entfernen die Stelle.</p></div>'

            . '<div><h2>Haftung für Inhalte</h2>'
            . '<p>Für eigene Inhalte auf diesen Seiten sind wir nach § 7 Abs. 1 TMG und den allgemeinen '
            . 'Gesetzen verantwortlich. Nach §§ 8 bis 10 TMG sind wir allerdings nicht verpflichtet, fremde '
            . 'Informationen zu überwachen oder nach Hinweisen auf rechtswidrige Tätigkeiten zu suchen. '
            . 'Pflichten zur Entfernung oder Sperrung nach den allgemeinen Gesetzen bleiben davon unberührt; '
            . 'sie greifen aber erst, sobald wir von einer konkreten Rechtsverletzung wissen. Sagen Sie uns '
            . 'Bescheid, dann ist der Inhalt schnell weg.</p></div>'

            . '<div><h2>Haftung für Links</h2>'
            . '<p>Wir verlinken an einigen Stellen auf fremde Seiten, vor allem auf unsere Aufzeichnungen bei '
            . 'YouTube. Auf deren Inhalte haben wir keinen Einfluss, verantwortlich ist immer der jeweilige '
            . 'Anbieter. Als wir die Links gesetzt haben, war dort nichts Rechtswidriges zu erkennen. Eine '
            . 'dauerhafte Kontrolle aller verlinkten Seiten leisten wir ohne konkreten Anlass nicht. Wird '
            . 'uns einer bekannt, fliegt der Link raus.</p></div>'

            . '<div><h2>Urheberrecht</h2>'
            . '<p>Texte, Fotos und Auswertungen auf dieser Seite haben wir selbst gemacht; sie unterliegen dem '
            . 'deutschen Urheberrecht. Beiträge Dritter sind gekennzeichnet. Wer etwas davon außerhalb der '
            . 'Schranken des Urheberrechts verwenden möchte, fragt uns bitte vorher. Für den privaten Gebrauch '
            . 'ist das Kopieren selbstverständlich in Ordnung.</p></div>'

            . '</div></section>';

        return $this->shell(
            'Impressum',
            $body,
            'impressum',
            'Impressum von Spezitest. Ein privates, nicht kommerzielles Projekt rund um Cola-Mix.',
            '/impressum',
        );
    }
    public function datenschutz(): string
    {
        $body = '<section class="wrap section"><div class="prose stack-lg">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Rechtliches</span>'
            . '<h1 class="display-2">Datenschutz</h1></div>'

            . '<div><h2>Kurzfassung</h2>'
            . '<p>Diese Seite sammelt nichts über Sie. Kein Tracking, keine Analyse-Tools, keine Werbe- oder '
            . 'Social-Media-Skripte, keine Cookies, keine Schriften oder Bilder von fremden Servern. Sie können '
            . 'hier lesen, suchen und sortieren, ohne dass davon irgendetwas bei uns landet. Ausgenommen '
            . 'sind die Zugriffsprotokolle, die jeder Webserver technisch bedingt schreibt.</p>'

            . '<div><h2>Verantwortliche Stelle</h2>'
            . '<p>ABOUT US Media GmbH<br>Zeppelinstraße 16 1/2<br>86343 Königsbrunn</p>'
            . '<p>E-Mail: <a href="mailto:hallo@aboutusmedia.de">hallo@aboutusmedia.de</a><br>'
            . 'Telefon: <a href="tel:+4915902608764">+49 1590 2608764</a></p>'
            . '<p>Verantwortliche Stelle ist, wer über Zwecke und Mittel der Verarbeitung personenbezogener '
            . 'Daten entscheidet. Bei Fragen zum Datenschutz schreiben Sie einfach an die Adresse oben.</p></div>'

            . '<div><h2>Server-Logfiles</h2>'
            . '<p>Wenn Sie eine Seite aufrufen, hält unser Hoster den Zugriff in einer Protokolldatei fest. '
            . 'Darin stehen die aufgerufene Adresse, Datum und Uhrzeit, die übertragene Datenmenge, die '
            . 'Meldung ob der Abruf geklappt hat, Browser und Betriebssystem sowie Ihre IP-Adresse. Diese Daten '
            . 'brauchen wir, damit die Seite ausgeliefert werden kann und damit wir Störungen und Angriffe '
            . 'erkennen. Wir führen sie nicht mit anderen Daten zusammen und werten sie nicht personenbezogen '
            . 'aus.</p>'
            . '<p>Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO: Wir haben ein berechtigtes Interesse daran, '
            . 'dass die Seite technisch fehlerfrei und sicher läuft. Die Protokolle werden beim Hoster nach '
            . 'kurzer Zeit automatisch gelöscht.</p></div>'

            . '<div><h2>Cookies</h2>'
            . '<p>Auf den öffentlichen Seiten setzen wir keine Cookies. Auch keine „technisch notwendigen“. '
            . 'Deshalb finden Sie hier auch kein Cookie-Banner. Ein Sitzungs-Cookie gibt es nur im internen '
            . 'Verwaltungsbereich, in dem wir die Testergebnisse pflegen; dort kommen Sie ohne Zugangsdaten '
            . 'nicht hin.</p></div>'

            . '<div><h2>Suche</h2>'
            . '<p>Die Vorschläge, die beim Tippen im Katalog erscheinen, beantwortet unser eigener Server. '
            . 'Ihre Suchbegriffe gehen an niemanden sonst und werden nicht dauerhaft gespeichert.</p></div>'

            . '<div><h2>Links zu YouTube</h2>'
            . '<p>Wir verlinken unsere Aufzeichnungen bei YouTube, betten aber keine Videos ein. Solange Sie '
            . 'nicht auf so einen Link klicken, erfährt YouTube nichts von Ihrem Besuch bei uns. Klicken Sie, '
            . 'gilt die Datenschutzerklärung von YouTube bzw. Google.</p></div>'

            . '<div><h2>Verschlüsselung</h2>'
            . '<p>Die Seite wird über HTTPS ausgeliefert. Sie erkennen das am Schloss-Symbol in der Adresszeile '
            . 'Ihres Browsers. Was zwischen Ihrem Gerät und unserem Server läuft, kann unterwegs niemand '
            . 'mitlesen.</p></div>'

            . '<div><h2>Ihre Rechte</h2>'
            . '<p>Sie haben jederzeit das Recht auf Auskunft über die zu Ihrer Person gespeicherten Daten, auf '
            . 'Berichtigung, Löschung und Einschränkung der Verarbeitung, auf Datenübertragbarkeit sowie das '
            . 'Recht, einer Verarbeitung zu widersprechen. Melden Sie sich dafür bei der oben genannten '
            . 'Adresse. Außerdem können Sie sich bei einer Datenschutz-Aufsichtsbehörde beschweren. Für uns '
            . 'zuständig ist das Bayerische Landesamt für Datenschutzaufsicht.</p>'
            . '<p>Viel zu holen gibt es dabei allerdings nicht: Außer den Logfiles beim Hoster liegen hier '
            . 'keine Daten über Besucherinnen und Besucher.</p></div>'

            . '</div></section>';

        return $this->shell(
            'Datenschutz',
            $body,
            'datenschutz',
            'Datenschutz bei Spezitest: keine Cookies, kein Tracking, keine Dienste Dritter.',
            '/datenschutz',
        );
    }
    public function notFound(): string
    {
        $body = '<section class="wrap section section--lg" style="min-height:55vh;display:flex;align-items:center">'
            . '<div class="stack-lg" style="max-width:520px"><div class="stack">'
            . '<span class="mark display-2" style="line-height:1">404</span>'
            . '<h1 class="display-3">Diese Flasche ist leer.</h1>'
            . '<p class="lede">Diese Seite gibt es nicht.</p></div>'
            . '<form class="search" role="search" method="get" action="/spezis">'
            . '<label class="visually-hidden" for="q404">Spezi suchen</label>'
            . '<input id="q404" name="q" type="search" placeholder="Marke, Hersteller, Region …">'
            . '<button type="submit">Suchen</button></form>'
            . '<div class="cluster"><a class="btn btn--primary" href="/">Startseite</a>'
            . '<a class="btn btn--secondary" href="/spezis">Alle Spezis</a></div></div></section>';

        return $this->shell('Seite nicht gefunden', $body, '');
    }

    /**
     * The branded 500 page. Built from static markup only — it must render even
     * when the database or a downstream service is the thing that failed.
     */
    public function serverError(): string
    {
        $body = '<section class="wrap section section--lg" style="min-height:55vh;display:flex;align-items:center">'
            . '<div class="stack-lg" style="max-width:520px"><div class="stack">'
            . '<span class="mark display-2" style="line-height:1">500</span>'
            . '<h1 class="display-3">Da ist uns die Kohlensäure ausgegangen.</h1>'
            . '<p class="lede">Auf dem Server ist etwas schiefgelaufen. Wir kümmern uns darum. '
            . 'Bitte später noch einmal versuchen.</p></div>'
            . '<div class="cluster"><a class="btn btn--primary" href="/">Startseite</a>'
            . '<a class="btn btn--secondary" href="/spezis">Alle Spezis</a></div></div></section>';

        return $this->shell('Serverfehler', $body, '');
    }

    // --- fragments --------------------------------------------------------

    private function headline(int $tested): string
    {
        if ($tested === 0) {
            return 'Cola-Mix. Ein Urteil.';
        }

        return $tested . ($tested === 1 ? ' Spezi. Ein Urteil.' : ' Spezis getestet.');
    }

    private function productImage(RatedDrink $drink, string $modifier = ''): string
    {
        $class = trim('pimg ' . $modifier);

        if (!$drink->hasImage) {
            return '<figure class="' . $class . '"><div class="pimg__ph"><span>Kein Bild</span></div></figure>';
        }

        return '<figure class="' . $class . '"><img src="/spezi/' . $drink->id . '/bild" alt="' . Html::e($drink->name) . '" loading="lazy"></figure>';
    }

    /**
     * @param list<RatedDrink> $drinks
     */
    private function rankRows(array $drinks, int $podiumCount): string
    {
        $rows = '';

        foreach ($drinks as $index => $drink) {
            $result = $drink->result;

            if ($result === null) {
                continue;
            }

            $podium = $index < $podiumCount ? ' rank__row--podium' : '';
            $rows .= '<a class="rank__row' . $podium . '" href="/spezi/' . Html::e($drink->slug()) . '">'
                . '<span class="rank__pos">' . ($drink->rank ?? ($index + 1)) . '</span>'
                . $this->productImage($drink, 'pimg--thumb')
                . '<span class="rank__text"><span class="rank__name">' . Html::e($drink->name) . '</span>'
                . '<span class="rank__sub">' . Html::e($drink->manufacturer ?? '–') . '</span></span>'
                . '<span class="rank__score">' . Html::gradeOfMax($result->gesamt(), Html::GESAMT_MAX) . '<small>Wertung</small></span></a>';
        }

        return $rows;
    }

    /**
     * @param list<RatedDrink> $podium
     */
    private function podium(array $podium): string
    {
        $items = '';

        foreach ($podium as $index => $drink) {
            $result = $drink->result;

            if ($result === null) {
                continue;
            }

            $items .= '<a class="podium__item podium__item--' . ($index + 1) . '" href="/spezi/' . Html::e($drink->slug()) . '">'
                . '<span class="podium__num">' . ($drink->rank ?? ($index + 1)) . '</span>'
                . '<span class="podium__media">'
                . ($drink->hasImage
                    ? '<img src="/spezi/' . $drink->id . '/bild" alt="" loading="lazy">'
                    : '')
                . '</span>'
                . '<span class="podium__body"><span class="rank__name">' . Html::e($drink->name) . '</span>'
                . '<span class="rank__sub">' . Html::e($drink->manufacturer ?? '–') . '</span></span>'
                . '<span class="podium__score">' . Html::gradeOfMax($result->gesamt(), Html::GESAMT_MAX) . '<small>Wertung</small></span>'
                . '</a>';
        }

        return $items;
    }

    private function catalogCard(RatedDrink $drink): string
    {
        $result = $drink->isTested() ? $drink->result : null;
        $foot = $result !== null
            ? '<div class="card__foot">'
                . ($drink->rank !== null
                    ? '<span class="card__rank">#' . $drink->rank . '</span>'
                    : '<span class="card__rank card__rank--none">Getestet</span>')
                . '<span class="card__score">' . Html::gradeOfMax($result->gesamt(), Html::GESAMT_MAX) . '<small>Wertung</small></span></div>'
            : '<div class="card__foot">' . Html::stateBadge($drink->lifecycleStatus) . '</div>';

        return '<a class="card card-link" href="/spezi/' . Html::e($drink->slug()) . '">'
            . $this->productImage($drink)
            . '<div class="card__body"><span class="card__title">' . Html::e($drink->name) . '</span>'
            . '<span class="meta">' . Html::e($drink->manufacturer ?? $drink->displayOrigin() ?? '—') . '</span>'
            . $foot . '</div></a>';
    }

    private function catalogChips(CatalogQuery $query): string
    {
        $chips = '';

        foreach (CatalogQuery::STATUSES as $status) {
            $active = in_array($status, $query->statuses, true);
            $target = $active ? $query->withoutStatus($status) : $query->withStatus($status);
            $href = '/spezis' . ($target->toQueryString() !== '' ? '?' . $target->toQueryString() : '');
            $chips .= '<a class="chip' . ($active ? ' chip--active' : '') . '" href="' . Html::e($href) . '">'
                . Html::e(Html::stateLabel($status)) . '</a>';
        }

        $imageTarget = $query->withImageFilter(!$query->withImageOnly);
        $imageHref = '/spezis' . ($imageTarget->toQueryString() !== '' ? '?' . $imageTarget->toQueryString() : '');
        $chips .= '<a class="chip' . ($query->withImageOnly ? ' chip--active' : '') . '" href="' . Html::e($imageHref) . '">Nur mit Bild</a>';

        if ($query->isFiltered()) {
            $chips .= '<a class="chip chip--reset" href="/spezis">Zurücksetzen</a>';
        }

        return '<div class="filters">' . $chips . '</div>';
    }

    private function catalogToolbar(CatalogPage $page): string
    {
        $query = $page->query;

        $sorts = [
            'best' => 'Beste Wertung',
            'worst' => 'Schwächste zuerst',
            'name' => 'Name A–Z',
            'recent' => 'Neueste zuerst',
        ];
        $options = '';

        foreach ($sorts as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($query->sort === $value ? ' selected' : '') . '>' . Html::e($label) . '</option>';
        }

        return '<div class="toolbar" style="margin:0">'
            . $this->catalogChips($query)
            . '<form method="get" action="/spezis" class="cluster cluster--tight">'
            . ($query->search !== '' ? '<input type="hidden" name="q" value="' . Html::e($query->search) . '">' : '')
            . $this->hiddenStatusFields($query)
            . '<label class="label" for="sort">Sortierung</label>'
            // Submitting happens in spezitest.js: the Content-Security-Policy
            // has no 'unsafe-inline', so an inline onchange never runs.
            . '<select class="select" id="sort" name="sort" style="width:auto" data-autosubmit>' . $options . '</select>'
            . '<noscript><button class="btn btn--secondary btn--sm" type="submit">Sortieren</button></noscript>'
            . '</form></div>';
    }

    private function hiddenSortField(CatalogQuery $query): string
    {
        return $query->sort !== 'best'
            ? '<input type="hidden" name="sort" value="' . Html::e($query->sort) . '">'
            : '';
    }

    private function hiddenStatusFields(CatalogQuery $query): string
    {
        $html = '';

        foreach ($query->statuses as $status) {
            $html .= '<input type="hidden" name="status[]" value="' . Html::e($status) . '">';
        }

        if ($query->withImageOnly) {
            $html .= '<input type="hidden" name="with_image" value="1">';
        }

        return $html;
    }

    private function figuresSection(RatedDrinkCollection $collection): string
    {
        $tested = $collection->tested();
        $best = $collection->ranked()[0]->result ?? null;
        $values = [];

        foreach ($tested as $drink) {
            if ($drink->result !== null) {
                $values[] = $drink->result->gesamt();
            }
        }

        $average = $values === [] ? null : array_sum($values) / count($values);

        return '<section class="section section--tint"><div class="wrap stack-lg">'
            . '<div class="cluster cluster--between"><h2 class="display-3">In Zahlen</h2>'
            . '<a class="link-arrow" href="/statistik">Statistik</a></div>'
            . '<div class="figure-row">'
            . $this->figure((string) count($tested), 'getestet')
            . $this->figure((string) $collection->count(), 'im Katalog')
            . $this->figure($best !== null ? Html::grade($best->gesamt()) : '–', 'beste Wertung')
            . $this->figure(Html::gradeOrDash($average), 'Ø Wertung')
            . '</div></div></section>';
    }

    private function figure(string $value, string $label): string
    {
        return '<div class="figure"><span class="figure__num">' . Html::e($value) . '</span>'
            . '<p class="figure__label">' . Html::e($label) . '</p></div>';
    }

    private function distributionRows(Statistics $stats): string
    {
        $max = 0;

        foreach ($stats->gesamtDistribution as $bin) {
            $max = max($max, $bin['count']);
        }

        $rows = '';

        foreach ($stats->gesamtDistribution as $index => $bin) {
            $width = $max > 0 ? (int) round($bin['count'] / $max * 100) : 0;
            $accent = $index >= 4 ? ' barchart__row--accent' : '';
            $hasDrinks = $bin['drinks'] !== [];
            $trigger = $hasDrinks ? ' data-bin-trigger tabindex="0" role="button" aria-expanded="false"' : '';
            $rows .= '<div class="barchart__row' . $accent . '"' . $trigger . '>'
                . '<span class="barchart__label">' . Html::e($bin['label']) . '</span>'
                . '<span class="barchart__track"><i style="width:' . $width . '%"></i></span>'
                . '<span class="barchart__val">' . $bin['count'] . '</span></div>';

            if ($hasDrinks) {
                $items = '';

                foreach ($bin['drinks'] as $drink) {
                    $items .= '<li><a href="/spezi/' . Html::e($drink['slug']) . '">' . Html::e($drink['name']) . '</a>'
                        . '<span>' . Html::gradeOfMax($drink['gesamt'], Html::GESAMT_MAX) . '</span></li>';
                }

                $rows .= '<ul class="barchart__detail" hidden>' . $items . '</ul>';
            }
        }

        return $rows;
    }

    private function testerAverageRows(Statistics $stats): string
    {
        $rows = '';

        foreach (self::TESTERS as $code => $label) {
            $value = $stats->testerAverages[$code] ?? null;
            $rows .= '<div class="barchart__row"><span class="barchart__label">' . Html::e($label) . '</span>'
                . '<span class="barchart__track"><i style="width:' . ($value !== null ? Html::barWidth($value, Html::CATEGORY_MAX) : '0') . '%"></i></span>'
                . '<span class="barchart__val">' . Html::gradeOrDash($value, 1) . '</span></div>';
        }

        return $rows;
    }

    private function categoryAverageRows(Statistics $stats): string
    {
        $labels = ['optik' => 'Optik', 'sueffigkeit' => 'Süffigkeit', 'geschmack' => 'Geschmack'];
        $rows = '';

        foreach ($labels as $key => $label) {
            $value = $stats->averageByCategory[$key];
            $rows .= '<div class="barchart__row"><span class="barchart__label">' . Html::e($label) . '</span>'
                . '<span class="barchart__track"><i style="width:' . ($value !== null ? Html::barWidth($value, Html::CATEGORY_MAX) : '0') . '%"></i></span>'
                . '<span class="barchart__val">' . Html::gradeOrDash($value, 1) . '</span></div>';
        }

        return $rows;
    }

    /**
     * The origin map: an abstracted Germany in the Spezitest palette with one
     * dot per postal region. Without JavaScript every dot is an anchor to its
     * own entry in the list beside it; with JavaScript the list turns into a
     * readout that follows the pointer.
     */
    private function originMapSection(OriginMap $map): string
    {
        if ($map->points === []) {
            return '';
        }

        $dots = '';
        $entries = '';

        foreach ($map->points as $point) {
            $radius = $map->radius($point['count']);
            $label = $point['area'] . ': ' . $point['count'] . ' ' . ($point['count'] === 1 ? 'Spezi' : 'Spezis');
            $dots .= '<a class="map__dot" href="#ort-' . Html::e($point['key']) . '"'
                . ' data-map-dot="' . Html::e($point['key']) . '"'
                . ' data-map-area="' . Html::e($point['area']) . '"'
                . ' data-map-count="' . $point['count'] . '">'
                . '<circle class="map__halo" cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="' . ($radius + 6) . '"></circle>'
                . '<circle class="map__pin" cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="' . $radius . '"></circle>'
                . '<title>' . Html::e($label) . '</title></a>';

            $items = '';

            foreach ($point['drinks'] as $drink) {
                $items .= '<li><a href="/spezi/' . Html::e($drink['slug']) . '">' . Html::e($drink['name']) . '</a>'
                    . '<span class="map__place">' . Html::e($drink['place']) . '</span>'
                    . ($drink['gesamt'] !== null ? '<span class="map__grade">' . Html::grade($drink['gesamt']) . '</span>' : '')
                    . '</li>';
            }

            $entries .= '<section class="map__entry" id="ort-' . Html::e($point['key']) . '" data-map-entry="' . Html::e($point['key']) . '">'
                . '<h3 class="map__entry-title">' . Html::e($point['area'])
                . '<span class="map__entry-count">' . $point['count'] . '</span></h3>'
                . '<ul class="map__drinks">' . $items . '</ul></section>';
        }

        $elsewhere = '';

        foreach ($map->elsewhere as $entry) {
            $elsewhere .= '<li>' . Html::e($entry['label']) . ' <span>' . $entry['count'] . '</span></li>';
        }

        return '<section class="section section--tint" id="karte"><div class="wrap stack-lg">'
            . '<div class="cluster cluster--between"><h2 class="display-3">Woher die Spezis kommen</h2>'
            . '<p class="meta">' . $map->placed . ' von ' . ($map->placed + $map->unplaced) . ' Einträgen verortet</p></div>'
            . '<div class="map">'
            . '<figure class="map__canvas" data-map>'
            . '<svg viewBox="' . $map->viewBox() . '" role="img" aria-label="Karte von Deutschland mit den Herkunftsregionen der Spezis" preserveAspectRatio="xMidYMid meet">'
            . '<path class="map__land" d="' . $map->outlinePath() . '"></path>'
            . $dots . '</svg>'
            . '<figcaption class="map__legend"><span class="map__legend-dot"></span>'
            . 'Punkt = PLZ-Region, Größe = Anzahl.</figcaption></figure>'
            . '<div class="map__side map__scroller" data-map-scroller>'
            . '<span class="map__more" aria-hidden="true"></span>'
            . '<div class="map__readout" data-map-readout hidden></div>'
            . '<div class="map__list" data-map-list>' . $entries
            . ($elsewhere !== ''
                ? '<section class="map__entry map__entry--rest"><h3 class="map__entry-title">Nicht verortet</h3>'
                    . '<ul class="map__rest">' . $elsewhere . '</ul></section>'
                : '')
            . '</div></div></div></div></section>';
    }

    /**
     * The three category bars plus the weighted total. Each category bar can be
     * hovered or focused to reveal what the individual testers gave it.
     */
    private function ratingBreakdown(RatedDrink $drink): string
    {
        $result = $drink->result;

        if ($result === null) {
            return '';
        }

        /** @var list<array{string, string, float}> $categories */
        $categories = [
            ['Optik', 'optik', $result->optikAverage()],
            ['Süffigkeit', 'sueffigkeit', $result->sueffigkeitAverage()],
            ['Geschmack', 'geschmack', $result->geschmackAverage()],
        ];

        $html = '<div class="stack"><span class="eyebrow">Einzelkriterien</span><div class="ratings">';

        foreach ($categories as [$label, $key, $value]) {
            $peek = $this->testerPeek($drink, $key);
            $bar = '<span class="rating__bar"><i style="width:' . Html::barWidth($value, Html::CATEGORY_MAX) . '%"></i></span>';
            $attributes = $peek === ''
                ? ''
                : ' tabindex="0" role="img" aria-label="' . $this->testerPeekLabel($drink, $label, $key, $value) . '"';
            $html .= '<div class="rating' . ($peek === '' ? '' : ' rating--peek') . '"' . $attributes . '>'
                . '<span class="rating__label">' . Html::e($label) . '</span>'
                . '<span class="rating__val">' . Html::grade($value) . '</span>'
                . ($peek === '' ? $bar : '<span class="rating__track">' . $bar . $peek . '</span>')
                . '</div>';
        }

        $html .= '<div class="rating rating--total">'
            . '<span class="rating__label">Gesamtwertung</span>'
            . '<span class="rating__val">' . Html::grade($result->gesamt()) . '</span>'
            . '<span class="rating__bar"><i style="width:' . Html::barWidth($result->gesamt(), Html::GESAMT_MAX) . '%"></i></span></div>'
            . '<div class="rating__scale"><span>0 · niedrig</span><span>höher ist besser</span></div></div></div>';

        return $html;
    }

    /**
     * A simple previous/next pager by Gesamtwertung rank, spanning the full
     * page width rather than living inside the sidebar.
     */
    private function previousNextNav(RatedDrink $drink, RatedDrinkCollection $collection): string
    {
        if (!$drink->isTested()) {
            return '';
        }

        $ranked = $collection->ranked();
        $position = null;

        foreach ($ranked as $index => $candidate) {
            if ($candidate->id === $drink->id) {
                $position = $index;

                break;
            }
        }

        if ($position === null) {
            return '';
        }

        $previous = $ranked[$position - 1] ?? null;
        $next = $ranked[$position + 1] ?? null;

        if ($previous === null && $next === null) {
            return '';
        }

        // Back on the left, forward on the right, each with an arrow, so the
        // direction is readable before the labels are.
        return '<section class="wrap section" style="padding-top:0">'
            . '<nav class="pager-nav" aria-label="Weitere Spezis nach Wertung">'
            . ($previous !== null ? $this->neighbourRow($previous, 'Vorheriger', 'prev') : '')
            . ($next !== null ? $this->neighbourRow($next, 'Nächster', 'next') : '')
            . '</nav></section>';
    }

    /** @param 'prev'|'next' $direction */
    private function neighbourRow(RatedDrink $drink, string $label, string $direction): string
    {
        $result = $drink->result;
        $subParts = array_filter([
            $drink->manufacturer,
            $result !== null ? Html::gradeOfMax($result->gesamt(), Html::GESAMT_MAX) : null,
        ]);

        return '<a class="neighbour neighbour--' . $direction . '" '
            . 'rel="' . ($direction === 'prev' ? 'prev' : 'next') . '" '
            . 'href="/spezi/' . Html::e($drink->slug()) . '">'
            . '<span class="neighbour__arrow" aria-hidden="true"></span>'
            . $this->productImage($drink, 'pimg--thumb')
            . '<span class="neighbour__text">'
            . '<span class="neighbour__label">' . Html::e($label) . ' · #' . ($drink->rank ?? '') . '</span>'
            . '<span class="neighbour__name">' . Html::e($drink->name) . '</span>'
            . '<span class="neighbour__sub">' . Html::e(implode(' · ', $subParts)) . '</span></span>'
            . '</a>';
    }

    /**
     * Previous / next either side of the page numbers. Long lists collapse to
     * first · … · a window around the current page · … · last, so the pager
     * never wraps into a wall of numbers.
     */
    private function pagination(CatalogPage $page): string
    {
        if ($page->pageCount <= 1) {
            return '';
        }

        $links = '';

        foreach ($this->pageNumbers($page->page, $page->pageCount) as $number) {
            if ($number === null) {
                $links .= '<span class="pagination__gap" aria-hidden="true">…</span>';

                continue;
            }

            $links .= $number === $page->page
                ? '<span aria-current="page">' . $number . '</span>'
                : '<a href="' . Html::e($page->query->url($number)) . '">' . $number . '</a>';
        }

        return '<nav class="pagination" aria-label="Seiten">'
            . $this->pageStep($page, $page->page - 1, 'Zurück', 'prev')
            . '<span class="pagination__pages">' . $links . '</span>'
            . $this->pageStep($page, $page->page + 1, 'Weiter', 'next')
            . '</nav>';
    }

    private function pageStep(CatalogPage $page, int $target, string $label, string $direction): string
    {
        $class = 'pagination__step pagination__step--' . $direction;

        if ($target < 1 || $target > $page->pageCount) {
            return '<span class="' . $class . '" aria-disabled="true">' . Html::e($label) . '</span>';
        }

        return '<a class="' . $class . '" rel="' . ($direction === 'prev' ? 'prev' : 'next') . '" href="'
            . Html::e($page->query->url($target)) . '">' . Html::e($label) . '</a>';
    }

    /**
     * The page numbers to render: always the first and last page, plus a window
     * around the current one. `null` marks an elided run.
     *
     * @return list<int|null>
     */
    private function pageNumbers(int $current, int $count): array
    {
        if ($count <= 7) {
            return range(1, $count);
        }

        $window = [1, $count];

        for ($number = $current - 1; $number <= $current + 1; ++$number) {
            if ($number >= 1 && $number <= $count) {
                $window[] = $number;
            }
        }

        $window = array_values(array_unique($window));
        sort($window);

        $numbers = [];
        $previous = 0;

        foreach ($window as $number) {
            if ($number - $previous > 1) {
                $numbers[] = null;
            }

            $numbers[] = $number;
            $previous = $number;
        }

        return $numbers;
    }

    /**
     * The result count, the card grid, "Mehr laden" and the pager. Rendered as
     * one block so the client-side "Mehr laden" can swap it in place; the same
     * markup is what a plain page load produces.
     */
    private function catalogResults(CatalogPage $page): string
    {
        $query = $page->query;

        if ($page->items === []) {
            return '<p class="meta"><strong style="color:var(--navy)">0 Ergebnisse</strong></p>'
                . '<div class="empty"><p class="empty__title">Keine Spezis gefunden</p>'
                . '<p>Andere Suchbegriffe oder Filter probieren.</p>'
                . ($query->isFiltered() ? '<p><a class="btn btn--secondary btn--sm" href="/spezis">Filter zurücksetzen</a></p>' : '')
                . '</div>';
        }

        $count = '<p class="meta"><strong style="color:var(--navy)">' . $page->totalMatches . ' '
            . ($page->totalMatches === 1 ? 'Ergebnis' : 'Ergebnisse') . '</strong>'
            . ' · ' . $page->firstItemNumber() . '–' . $page->lastItemNumber() . ' angezeigt'
            . ($page->pageCount > 1 ? ' · Seite ' . $page->page . ' von ' . $page->pageCount : '') . '</p>';

        return $count
            . '<div class="grid grid--cards">' . implode('', array_map($this->catalogCard(...), $page->items)) . '</div>'
            . $this->loadMore($page)
            . $this->pagination($page);
    }

    /**
     * Grows the page by one more block of Spezis. It is a real link to a real
     * URL, so it works without JavaScript; `spezitest.js` upgrades it to an
     * in-place append that keeps the scroll position.
     */
    private function loadMore(CatalogPage $page): string
    {
        if (!$page->hasMoreItems()) {
            return '';
        }

        $remaining = $page->totalMatches - $page->lastItemNumber();

        if (!$page->query->canLoadMore()) {
            // The page cannot grow any further; the pager below takes over.
            return '<p class="load-more__note meta">Noch ' . $remaining . ' weitere. Weiter geht es über '
                . 'die Seiten unten.</p>';
        }

        $step = min($remaining, CatalogQuery::PER_PAGE);
        $target = $page->query->withMoreItems();

        return '<div class="load-more">'
            . '<a class="btn btn--secondary load-more__btn" href="' . Html::e($target->url()) . '#ergebnisse" '
            . 'data-load-more>' . $step . ' weitere laden</a>'
            . '<p class="load-more__note meta">' . $page->lastItemNumber() . ' von ' . $page->totalMatches . ' angezeigt</p>'
            . '</div>';
    }

    /**
     * The recorded price and the Preis/Leistung figure derived from it, shown
     * in the verdict row beside the Gesamtwertung. Absent when no price is
     * recorded — the row then simply has fewer figures, not empty placeholders.
     * A container other than 0,5 l carries its normalised basis as a tooltip,
     * because that is the number the 0–100 figure is actually built from.
     */
    private function priceScores(RatedDrink $drink): string
    {
        if ($drink->priceAmount === null || $drink->priceVolumeMl === null) {
            return '';
        }

        $title = $drink->priceVolumeMl === 500
            ? ''
            : ' title="' . Html::e(Html::price(
                (string) (new PriceNormalizer())->perReferenceVolume($drink->priceAmount, $drink->priceVolumeMl),
            )) . ' je 0,5 l"';

        $html = '<div class="score score--price"' . $title . '>'
            . '<span class="score__num">' . Html::e(Html::price($drink->priceAmount)) . '</span>'
            . '<span class="score__label">Preis / ' . $drink->priceVolumeMl . ' ml</span></div>';

        $pricePerformance = $drink->pricePerformance;

        if ($pricePerformance !== null) {
            $html .= '<div class="score score--pp">'
                . '<span class="score__num">' . Html::grade((float) $pricePerformance->normalized() * 100, 0) . '</span>'
                . '<span class="score__label">Preis / Leistung · von 100</span></div>';
        }

        return $html;
    }

    private function streamLink(RatedDrink $drink): string
    {
        $segment = $drink->stream;

        if ($segment === null) {
            return '';
        }

        // Two quiet routes to the same tasting: the evening's own page, which
        // always exists, and the recording, which only appears once an address
        // is on file. Both stay understated — the verdict above is the headline.
        $links = '<a class="btn btn--quiet" href="/streams/' . $segment->runNumber . '">'
            . 'Zum Testabend ' . $segment->runNumber . '</a>';

        $url = $segment->watchUrl();
        $offset = $segment->formattedOffset();

        if ($url !== null) {
            $links .= '<a class="btn btn--quiet stream-link__watch" href="' . Html::e($url) . '" '
                . 'target="_blank" rel="noopener noreferrer">'
                . ($offset === null ? 'Auf YouTube ansehen' : 'Auf YouTube ab ' . Html::e($offset))
                . '</a>';
        }

        return '<div class="stream-link">' . $links
            . '<span class="meta stream-link__note">' . Html::e($segment->title()) . '</span></div>';
    }

    /**
     * The hover/focus panel that reveals each tester's grade for one category.
     * Empty (feature simply absent) unless all three canonical testers have a
     * grade for that category.
     */
    private function testerPeek(RatedDrink $drink, string $category): string
    {
        $parts = '';

        foreach (self::TESTERS as $code => $name) {
            $grade = $drink->testerGrades[$code][$category] ?? null;

            if (!is_string($grade) || $grade === '') {
                return '';
            }

            $parts .= '<span>' . Html::e($name) . '<b>' . Html::e($this->gradeInteger($grade)) . '</b></span>';
        }

        return '<span class="rating__peek" aria-hidden="true">' . $parts . '</span>';
    }

    private function testerPeekLabel(RatedDrink $drink, string $label, string $category, float $value): string
    {
        $parts = [];

        foreach (self::TESTERS as $code => $name) {
            $parts[] = $name . ' ' . $this->gradeInteger((string) ($drink->testerGrades[$code][$category] ?? ''));
        }

        return Html::e(
            $label . ' ' . Html::grade($value) . ' von 10. Einzelnoten: ' . implode(', ', $parts) . '.',
        );
    }

    private function gradeInteger(string $grade): string
    {
        return (string) (int) round((float) $grade);
    }

    private function testerCard(string $name, string $description, string $image): string
    {
        return '<div class="card"><figure class="pimg pimg--square pimg--bare"><img src="/assets/testers/'
            . Html::e($image) . '.webp" alt="Porträt von ' . Html::e($name)
            . '" width="640" height="640" loading="lazy"></figure>'
            . '<div class="card__body"><span class="card__title">' . Html::e($name) . '</span>'
            . '<p class="meta">' . Html::e($description) . '</p></div></div>';
    }

    /**
     * One stage of the drink lifecycle in the vertical diagram on /ueber: the
     * status pill, a plain-language line, and a connector down to the next
     * stage (omitted for the last).
     */
    private function lifecycleStep(string $status, string $meaning, bool $last): string
    {
        $connector = $last
            ? ''
            : '<span aria-hidden="true" style="align-self:flex-start;width:var(--bw-strong);height:22px;'
                . 'margin:var(--sp-2) 0 var(--sp-2) 17px;background:var(--line-strong)"></span>';

        return '<div class="stack-sm">'
            . Html::stateBadge($status, true)
            . '<p class="meta" style="margin:0">' . Html::e($meaning) . '</p>'
            . '</div>'
            . $connector;
    }
}
