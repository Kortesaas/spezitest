<?php

declare(strict_types=1);

namespace Spezitest\Website\View;

use Spezitest\Website\Catalog\CatalogPage;
use Spezitest\Website\Catalog\CatalogQuery;
use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\Statistics;

/**
 * Renders the six public pages from real catalog data using the Spezitest
 * Design System classes. Pages never show placeholder or invented values: when
 * there is no data yet, an honest empty state is shown instead.
 */
final class WebsiteRenderer
{
    private const TESTERS = ['manu' => 'Manu', 'fabi' => 'Fabi', 'schorsch' => 'Schorsch'];

    public function home(RatedDrinkCollection $collection): string
    {
        $counts = $collection->lifecycleCounts();
        $ranked = $collection->ranked();
        $top = array_slice($ranked, 0, 5);
        $best = $top[0]->result ?? null;

        $hero = '<section class="wrap section"><div class="hero">'
            . '<div class="stack-lg"><div class="stack">'
            . '<span class="eyebrow eyebrow--accent">Qualitätsurteil – Gesamt</span>'
            . '<h1 class="display-1">' . Html::e($this->headline($counts['tested'])) . '</h1>'
            . '<p class="lede">Die Abteilung Spezitest identifiziert, erwirbt und testet Cola-Mix-Getränke aus '
            . 'Deutschland und den Nachbarländern. Gleiche Kriterien, gleiche Gläser, drei Tester.</p></div>'
            . '<div class="cluster"><a class="btn btn--primary btn--lg" href="/spezis">Katalog durchsuchen</a>'
            . '<a class="btn btn--secondary btn--lg" href="/ranking">Zum Ranking</a></div>'
            . '<dl class="meta--dl" style="max-width:520px">'
            . '<dt>Kriterien</dt><dd>Optik · Süffigkeit · Geschmack</dd>'
            . '<dt>Tester</dt><dd>Manu · Fabi · Schorsch</dd>'
            . '<dt>Skala</dt><dd>0 bis 10 je Kriterium, höher ist besser</dd></dl></div>';

        if ($top !== [] && $best !== null) {
            $leader = $top[0];
            $hero .= '<div style="position:relative">'
                . $this->productImage($leader, 'pimg--hero')
                . '<div class="score score--block" style="position:absolute;left:0;bottom:0">'
                . '<span class="score__num" style="font-size:var(--fs-h1)">' . Html::grade($best->gesamt()) . '</span>'
                . '<span class="score__label">Testsieger · ' . Html::e($leader->name) . '</span></div></div>';
        } else {
            $hero .= '<div class="empty"><span class="eyebrow">Noch kein Testsieger</span>'
                . '<p class="empty__title">Der erste Test steht noch aus</p>'
                . '<p>Sobald ein Getränk getestet ist, erscheint hier das beste Gesamturteil.</p></div>';
        }

        $hero .= '</div></section>';

        $topSection = '<section class="section section--tint"><div class="wrap stack-lg">'
            . '<div class="cluster cluster--between"><div><span class="eyebrow">Bestenliste</span>'
            . '<h2 class="display-3">' . ($top === [] ? 'Die besten Spezis' : 'Die Top ' . count($top) . ' im Test') . '</h2></div>'
            . '<a class="link-arrow" href="/ranking">Vollständiges Ranking</a></div>'
            . ($top === []
                ? '<div class="empty"><p class="empty__title">Noch keine getesteten Spezis</p><p>Die Bestenliste füllt sich mit dem ersten abgeschlossenen Test.</p></div>'
                : '<div class="rank">' . $this->rankRows($top, 3) . '</div>')
            . '</div></section>';

        $activity = $collection->recent(5);
        $activitySection = '<section class="section"><div class="wrap split split--sidebar">'
            . '<div class="stack-lg"><div class="cluster cluster--between"><div>'
            . '<span class="eyebrow">Neu im Katalog</span><h2 class="display-3">Zuletzt passiert</h2></div>'
            . '<a class="link-arrow" href="/spezis?sort=recent">Alle ansehen</a></div>'
            . ($activity === []
                ? '<div class="empty"><p class="empty__title">Der Katalog ist noch leer</p><p>Neu erfasste Spezis erscheinen hier.</p></div>'
                : '<ul class="stack">' . implode('', array_map($this->activityRow(...), $activity)) . '</ul>')
            . '</div>'
            . '<aside class="stack-lg"><div class="panel card--strong">'
            . '<span class="eyebrow">Fehlt uns noch</span>'
            . '<p class="h3" style="margin-top:var(--sp-2);font-weight:700;color:var(--navy)">'
            . $counts['identified'] . ' ' . ($counts['identified'] === 1 ? 'Spezi ist' : 'Spezis sind')
            . ' identifiziert, aber noch nicht im Kasten.</p>'
            . '<p class="meta" style="margin-top:var(--sp-3)">Wer eines davon im Getränkemarkt sieht: '
            . 'Regal fotografieren, Standort melden.</p>'
            . '<p style="margin-top:var(--sp-4)"><a class="btn btn--secondary btn--block" href="/spezis?status%5B%5D=identified">Liste ansehen</a></p></div>'
            . '</aside></div></section>';

        return Layout::page(
            'Start',
            $hero . $topSection . $activitySection . $this->figuresSection($collection),
            'start',
            'Cola-Mix und Spezi im Test: Katalog, Ranking und Statistik der Abteilung Spezitest.',
        );
    }

    public function catalog(CatalogPage $page): string
    {
        $query = $page->query;

        $body = '<div class="wrap section" style="padding-bottom:var(--sp-5)">'
            . '<nav aria-label="Brotkrumen"><ol class="breadcrumb"><li><a href="/">Start</a></li><li>Spezis</li></ol></nav>'
            . '<div class="stack" style="margin-top:var(--sp-3)"><h1 class="display-3">Spezi-Katalog</h1>'
            . '<p class="lede">' . $this->catalogSummary($page) . '</p>'
            . '<form class="search" role="search" method="get" action="/spezis">'
            . '<label class="visually-hidden" for="q">Spezi suchen</label>'
            . '<input id="q" name="q" type="search" placeholder="Marke, Hersteller, Region …" value="' . Html::e($query->search) . '">'
            . $this->hiddenSortField($query)
            . '<button type="submit">Suchen</button></form></div></div>';

        $body .= '<div class="wrap" style="padding-bottom:var(--sp-9)"><div class="split split--filters">'
            . '<aside>' . $this->catalogFilters($page) . '</aside>'
            . '<div class="stack-lg">'
            . $this->catalogToolbar($page)
            . '<p class="meta"><strong style="color:var(--navy)">' . $page->totalMatches . ' '
            . ($page->totalMatches === 1 ? 'Ergebnis' : 'Ergebnisse') . '</strong>'
            . ($page->pageCount > 1 ? ' · Seite ' . $page->page . ' von ' . $page->pageCount : '') . '</p>'
            . ($page->items === []
                ? '<div class="empty"><p class="empty__title">Keine Spezis gefunden</p>'
                    . '<p>Andere Suchbegriffe oder Filter probieren.</p>'
                    . ($query->isFiltered() ? '<p><a class="btn btn--secondary btn--sm" href="/spezis">Filter zurücksetzen</a></p>' : '')
                    . '</div>'
                : '<div class="grid grid--cards">' . implode('', array_map($this->catalogCard(...), $page->items)) . '</div>')
            . $this->pagination($page)
            . '</div></div></div>';

        return Layout::page('Spezis', $body, 'spezis', 'Alle katalogisierten Cola-Mix- und Spezi-Getränke mit Status und Gesamtwertung.');
    }

    public function detail(RatedDrink $drink, RatedDrinkCollection $collection): string
    {
        $result = $drink->result;
        $origin = $drink->displayOrigin();
        $subtitleParts = array_values(array_filter([$drink->manufacturer, $origin]));

        $hero = '<div class="wrap" style="padding-top:var(--sp-4)"><nav aria-label="Brotkrumen"><ol class="breadcrumb">'
            . '<li><a href="/">Start</a></li><li><a href="/spezis">Spezis</a></li><li>' . Html::e($drink->name) . '</li></ol></nav></div>'
            . '<article><section class="wrap section" style="padding-top:var(--sp-5)"><div class="hero">'
            . '<div style="max-width:440px">' . $this->productImage($drink, 'pimg--hero') . '</div>'
            . '<div class="stack-lg"><div class="stack">'
            . '<div class="cluster cluster--tight">' . Html::stateBadge($drink->lifecycleStatus, true)
            . ($drink->isTested() && $drink->rank !== null
                ? '<span class="badge badge--outline">Rang ' . $drink->rank . ' von ' . count($collection->tested()) . '</span>'
                : '')
            . '</div><h1 class="display-2">' . Html::e($drink->name) . '</h1>'
            . ($subtitleParts !== [] ? '<p class="lede">' . Html::e(implode(' · ', $subtitleParts)) . '</p>' : '')
            . '</div>';

        if ($result !== null) {
            $hero .= '<div class="cluster" style="gap:var(--sp-6);align-items:flex-end;border-top:var(--bw-strong) solid var(--line-strong);padding-top:var(--sp-4)">'
                . '<div class="score score--hero"><span class="score__num">' . Html::grade($result->gesamt()) . '</span>'
                . '<span class="score__label">Gesamtwertung</span></div>'
                . '<p class="meta" style="max-width:26ch">Skala 0 bis 60, höher ist besser. Gewichtet aus Optik, '
                . 'Süffigkeit und Geschmack. Keine Sterne, keine Prozent.</p></div>'
                . $this->ratingBreakdown($result)
                . $this->testerGrid($drink);
        } else {
            $hero .= '<div class="notice"><span>Dieses Getränk ist noch nicht getestet. '
                . 'Sobald ein Testabend stattgefunden hat, erscheinen hier Gesamtwertung und Einzelnoten.</span></div>';
        }

        $hero .= '<div class="cluster"><a class="btn btn--ghost" href="/spezis">← Zurück zum Katalog</a></div>'
            . '</div></div></section>';

        $body = $hero . $this->detailSidebar($drink, $collection) . '</article>';

        return Layout::page(
            $drink->name,
            $body,
            'spezis',
            $result !== null
                ? $drink->name . ' im Spezitest: Gesamtwertung ' . Html::grade($result->gesamt()) . '.'
                : $drink->name . ' im Spezitest-Katalog.',
        );
    }

    public function ranking(RatedDrinkCollection $collection): string
    {
        $ranked = $collection->ranked();
        $podium = array_slice($ranked, 0, 3);
        $rest = array_slice($ranked, 3);

        $band = '<div class="band"><div class="wrap band__inner"><div class="cluster cluster--between" style="align-items:flex-end">'
            . '<div class="stack"><span class="eyebrow" style="color:rgba(255,255,255,.85)">Qualitätsurteil – Gesamt</span>'
            . '<h1 class="display-2" style="color:#fff">Ranking</h1></div>'
            . '<p style="max-width:36ch;font-weight:700;font-size:var(--fs-body-lg)">'
            . ($ranked === []
                ? 'Sobald Spezis getestet sind, stehen sie hier – sortiert nach Gesamtwertung.'
                : 'Alle ' . count($ranked) . ' getesteten Spezis, sortiert nach Gesamtwertung. Höher ist besser.')
            . '</p></div></div></div>';

        if ($ranked === []) {
            return Layout::page('Ranking', $band . '<div class="wrap section"><div class="empty">'
                . '<p class="empty__title">Noch kein Ranking</p>'
                . '<p>Es wurde noch kein Test abgeschlossen.</p></div></div>', 'ranking');
        }

        $body = $band . '<div class="wrap section">'
            . ($podium !== [] ? '<div class="podium" style="margin-block:var(--sp-6)">' . $this->podium($podium) . '</div>' : '')
            . '<div class="rank">' . $this->rankRows($rest, 0) . '</div>'
            . '<p class="notice" style="margin-top:var(--sp-6)"><span><strong>Zur Skala:</strong> '
            . 'Jedes Kriterium wird von 0 bis 10 bewertet, höher ist besser. Die Gesamtwertung entsteht gewichtet '
            . 'aus Optik (×1), Süffigkeit (×2) und Geschmack (×3) und wird nicht in Sterne oder Prozent umgerechnet.</span></p>'
            . '</div>';

        return Layout::page('Ranking', $body, 'ranking', 'Das vollständige Spezitest-Ranking nach Gesamtwertung.');
    }

    public function statistik(Statistics $stats): string
    {
        $intro = '<section class="wrap section"><div class="split" style="align-items:end">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Statistik</span>'
            . '<h1 class="display-2">Was die Testabende über Cola-Mix verraten.</h1></div>'
            . '<p class="lede">Der Katalog ist kein Dashboard. Alle Werte hier werden aus den erfassten Tests '
            . 'berechnet – nichts ist geschätzt oder erfunden.</p></div>';

        if ($stats->testedCount === 0) {
            $intro .= '<div class="empty" style="margin-top:var(--sp-7)"><p class="empty__title">Noch keine Auswertung</p>'
                . '<p>Statistiken erscheinen, sobald mindestens ein Test abgeschlossen ist. '
                . 'Aktuell erfasst: ' . $stats->total . ' ' . ($stats->total === 1 ? 'Eintrag' : 'Einträge') . '.</p></div></section>';

            return Layout::page('Statistik', $intro, 'statistik');
        }

        $intro .= '<div class="figure-row" style="margin-top:var(--sp-7)">'
            . $this->figure((string) $stats->testedCount, 'Spezis getestet')
            . $this->figure((string) $stats->total, 'Einträge im Katalog')
            . $this->figure(Html::gradeOrDash($stats->averageGesamt), 'Ø Gesamtwertung')
            . $this->figure((string) $stats->lifecycleCounts['identified'], 'noch nicht erworben')
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

        $tables = '<section class="wrap section"><div class="split split--sidebar"><div class="stack-lg">'
            . '<div class="stack"><span class="eyebrow">Hersteller</span><h2 class="display-3">Mehrfach im Katalog</h2></div>'
            . ($stats->manufacturers === []
                ? '<p class="meta">Noch kein Hersteller mit mehreren Einträgen.</p>'
                : '<div class="table-scroll"><table class="table"><caption class="visually-hidden">Hersteller mit mehreren Einträgen</caption>'
                    . '<thead><tr><th>Hersteller</th><th>Einträge</th><th>Ø Wertung</th><th>Bester Eintrag</th></tr></thead><tbody>'
                    . $this->manufacturerRows($stats) . '</tbody></table></div>')
            . '</div><aside class="stack-lg">'
            . $this->bestByCategoryPanel($stats)
            . $this->regionPanel($stats)
            . '</aside></div></section>';

        return Layout::page('Statistik', $intro . $distribution . $tables, 'statistik', 'Auswertung der Spezitest-Testabende: Verteilung, Tester, Hersteller, Herkunft.');
    }

    public function ueber(RatedDrinkCollection $collection): string
    {
        $counts = $collection->lifecycleCounts();
        $body = '<section class="wrap section"><div class="split" style="align-items:center">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Über das Projekt</span>'
            . '<h1 class="display-2">Wir trinken das, damit du es nicht musst.</h1>'
            . '<p class="lede">Spezitest ist eine private Abteilung mit einer Aufgabe: jedes Cola-Mix-Getränk finden, '
            . 'kaufen und nach denselben drei Kriterien bewerten. Aktuell '
            . $counts['tested'] . ' ' . ($counts['tested'] === 1 ? 'getesteter Spezi' : 'getestete Spezis') . '.</p></div>'
            . '<div style="background:var(--surface-tint);padding:var(--sp-6);display:flex;justify-content:center">'
            . '<img src="/assets/spezitest-icon.svg" alt="Logo der Abteilung Spezitest" width="180" height="180"></div></div></section>'

            . '<section class="section section--tint" id="methode"><div class="wrap split split--sidebar">'
            . '<div class="prose stack-lg"><div class="stack"><span class="eyebrow">Methode</span>'
            . '<h2 class="display-3">Wie getestet wird</h2></div>'
            . '<p>Jedes Getränk wird bei gleicher Temperatur aus dem gleichen Glas probiert. Jeder der drei Tester '
            . 'vergibt für Optik, Süffigkeit und Geschmack eine Note von 0 bis 10 – höher ist besser. '
            . 'Aus den neun Noten entsteht die Gesamtwertung.</p>'
            . '<h3>Optik</h3><p>Farbe im Glas, Kohlensäure, Schaum, Erscheinung der Flasche oder Dose.</p>'
            . '<h3>Süffigkeit</h3><p>Wie leicht sich das Glas leert. Süße, Säure, Kohlensäurestärke, Abgang.</p>'
            . '<h3>Geschmack</h3><p>Verhältnis von Cola zu Orange, Aromatik, Eigenständigkeit.</p>'
            . '<h3>Gesamtwertung</h3><p>Die Kriterien werden gewichtet zusammengezählt: Optik einfach, Süffigkeit '
            . 'doppelt, Geschmack dreifach. Das Ergebnis liegt zwischen 0 und 60.</p>'
            . '<h3>Preis / Leistung</h3><p>Optional. Wird nur ausgewiesen, wenn ein Preis erfasst wurde.</p>'
            . '<h2>Was wir nicht machen</h2><ul>'
            . '<li>Keine Sterne, keine Prozentwerte, keine Emoji.</li>'
            . '<li>Keine nachträgliche Änderung der Methodik.</li>'
            . '<li>Keine bezahlten Tests, keine Kooperationen.</li></ul></div>'
            . '<aside class="stack-lg"><div class="panel"><span class="eyebrow">Lebenszyklus</span>'
            . '<div class="cluster cluster--tight" style="margin-top:var(--sp-3)">'
            . Html::stateBadge('identified') . Html::stateBadge('acquired') . Html::stateBadge('tested') . '</div>'
            . '<p class="meta" style="margin-top:var(--sp-3)">Jeder Eintrag hat genau einen dieser Zustände: '
            . 'gesehen, im Kasten, getestet.</p></div></aside></div></section>'

            . '<section class="wrap section" id="tester"><div class="stack-lg">'
            . '<div class="stack"><span class="eyebrow">Die Abteilung</span><h2 class="display-3">Drei Tester, eine Skala</h2></div>'
            . '<div class="grid grid--3">'
            . $this->testerCard('Manu', 'Zuständig für Beschaffung und Kastenlogistik.')
            . $this->testerCard('Fabi', 'Achtet auf Süße und Abgang.')
            . $this->testerCard('Schorsch', 'Führt den Katalog und schreibt die Testnotizen.')
            . '</div></div></section>'

            . '<section class="section section--navy"><div class="wrap on-navy split" style="align-items:center">'
            . '<h2 class="display-3" style="color:#fff">Spezi im Regal entdeckt, das uns fehlt?</h2>'
            . '<div class="stack"><p class="lede" style="color:rgba(255,255,255,.86)">Foto, Marke, Markt – das genügt.</p>'
            . '<div class="cluster"><a class="btn btn--on-navy" href="/spezis">Katalog prüfen</a></div></div></div></section>';

        return Layout::page('Über Spezitest', $body, 'ueber', 'Die Testmethode und die Tester hinter Spezitest.');
    }

    public function notFound(): string
    {
        $body = '<section class="wrap section section--lg" style="min-height:60vh;display:flex;align-items:center">'
            . '<div class="split" style="align-items:center;width:100%"><div class="stack-lg"><div class="stack">'
            . '<span class="mark display-2" style="line-height:1">404</span>'
            . '<h1 class="display-3">Diese Flasche ist leer.</h1>'
            . '<p class="lede">Die Seite existiert nicht – oder hat es nie in den Katalog geschafft.</p></div>'
            . '<form class="search" role="search" method="get" action="/spezis" style="max-width:520px">'
            . '<label class="visually-hidden" for="q404">Spezi suchen</label>'
            . '<input id="q404" name="q" type="search" placeholder="Marke, Hersteller, Region …">'
            . '<button type="submit">Suchen</button></form>'
            . '<div class="cluster"><a class="btn btn--primary" href="/">Zur Startseite</a>'
            . '<a class="btn btn--secondary" href="/spezis">Alle Spezis</a>'
            . '<a class="btn btn--ghost" href="/ranking">Ranking</a></div></div>'
            . '<div class="empty" style="min-height:320px;align-content:center"><span class="eyebrow">Fehlercode 404</span>'
            . '<p class="empty__title">Kein Eintrag unter dieser Adresse</p></div></div></section>';

        return Layout::page('Seite nicht gefunden', $body, '');
    }

    // --- fragments --------------------------------------------------------

    private function headline(int $tested): string
    {
        if ($tested === 0) {
            return 'Cola-Mix. Ein Urteil.';
        }

        return $tested . ($tested === 1 ? ' Spezi. Ein Urteil.' : ' Spezis. Ein Urteil.');
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
                . '<span><span class="rank__name">' . Html::e($drink->name) . '</span><br>'
                . '<span class="rank__sub">' . Html::e($drink->manufacturer ?? '–') . '</span></span>'
                . '<span class="rank__score">' . Html::grade($result->gesamt()) . '</span></a>';
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

            $items .= '<div class="podium__item podium__item--' . ($index + 1) . '">'
                . '<span class="podium__num">' . ($drink->rank ?? ($index + 1)) . '</span>'
                . '<div class="stack-sm">'
                . ($drink->hasImage
                    ? '<img src="/spezi/' . $drink->id . '/bild" alt="' . Html::e($drink->name) . '" style="height:120px;width:auto;margin-bottom:var(--sp-2)" loading="lazy">'
                    : '')
                . '<a class="rank__name" href="/spezi/' . Html::e($drink->slug()) . '" style="display:block;text-decoration:none">' . Html::e($drink->name) . '</a>'
                . '<span class="rank__sub" style="display:block">' . Html::e($drink->manufacturer ?? '–') . '</span>'
                . '<span class="rank__score" style="display:block;text-align:left;font-size:var(--fs-h1);margin-top:var(--sp-2)">' . Html::grade($result->gesamt()) . '</span>'
                . '</div></div>';
        }

        return $items;
    }

    private function ratingBreakdown(\Spezitest\Domain\Rating\RatingResult $result): string
    {
        $rows = [
            ['Optik', $result->optikAverage(), Html::CATEGORY_MAX, false],
            ['Süffigkeit', $result->sueffigkeitAverage(), Html::CATEGORY_MAX, false],
            ['Geschmack', $result->geschmackAverage(), Html::CATEGORY_MAX, false],
            ['Gesamtwertung', $result->gesamt(), Html::GESAMT_MAX, true],
        ];

        $html = '<div class="stack"><span class="eyebrow">Einzelkriterien</span><div class="ratings">';

        foreach ($rows as [$label, $value, $max, $isTotal]) {
            $html .= '<div class="rating' . ($isTotal ? ' rating--total' : '') . '">'
                . '<span class="rating__label">' . Html::e($label) . '</span>'
                . '<span class="rating__val">' . Html::grade($value) . '</span>'
                . '<span class="rating__bar"><i style="width:' . Html::barWidth($value, $max) . '%"></i></span></div>';
        }

        $html .= '<div class="rating__scale"><span>0 · niedrig</span><span>höher ist besser</span></div></div></div>';

        return $html;
    }

    private function testerGrid(RatedDrink $drink): string
    {
        if ($drink->testerGrades === []) {
            return '';
        }

        $cells = '';

        foreach (self::TESTERS as $code => $label) {
            $grades = $drink->testerGrades[$code] ?? null;

            if ($grades === null) {
                continue;
            }

            $mean = ((float) $grades['optik'] + (float) $grades['sueffigkeit'] + (float) $grades['geschmack']) / 3;
            $cells .= '<div class="tester"><span class="tester__name">' . Html::e($label) . '</span><br>'
                . '<span class="tester__val">' . Html::grade($mean, 1) . '</span></div>';
        }

        return '<div class="stack"><span class="eyebrow">Einzelnoten der Tester <span class="meta" style="letter-spacing:0;text-transform:none">· Mittel über die drei Kriterien</span></span>'
            . '<div class="testers">' . $cells . '</div></div>';
    }

    private function detailSidebar(RatedDrink $drink, RatedDrinkCollection $collection): string
    {
        $facts = [];

        if ($drink->manufacturer !== null) {
            $facts[] = ['Hersteller', $drink->manufacturer];
        }

        if ($drink->originLocation !== null) {
            $facts[] = ['Ort', $drink->originLocation];
        }

        if ($drink->originRegion !== null) {
            $facts[] = ['Region', $drink->originRegion];
        }

        $testedDate = Html::isoToGermanDate($drink->testedAt);

        if ($testedDate !== null) {
            $facts[] = ['Getestet am', $testedDate];
        }

        $factsHtml = '';

        foreach ($facts as [$term, $value]) {
            $factsHtml .= '<dt>' . Html::e($term) . '</dt><dd>' . Html::e($value) . '</dd>';
        }

        $noteHtml = '';

        if ($drink->testNotes !== null) {
            $noteHtml = '<div class="stack"><span class="eyebrow">Testnotiz</span>'
                . '<div class="prose"><p>' . nl2br(Html::e($drink->testNotes)) . '</p></div></div>';
        }

        $priceHtml = '';

        if ($drink->priceAmount !== null) {
            $pp = $drink->pricePerformance;
            $priceHtml = '<div class="stack"><span class="eyebrow">Preis / Leistung</span><div class="barchart">'
                . ($pp !== null
                    ? '<div class="barchart__row barchart__row--accent"><span class="barchart__label">Preis / Leistung</span>'
                        . '<span class="barchart__track"><i style="width:' . Html::barWidth((float) $pp->normalized() * 100, 100) . '%"></i></span>'
                        . '<span class="barchart__val">' . Html::grade((float) $pp->normalized() * 100, 0) . ' / 100</span></div>'
                    : '')
                . '<div class="barchart__row"><span class="barchart__label">Preis</span>'
                . '<span class="barchart__track"><i style="width:0%"></i></span>'
                . '<span class="barchart__val">' . Html::e(Html::price($drink->priceAmount)) . '</span></div>'
                . '</div><p class="meta">Normalisiert über alle getesteten Spezis mit erfasstem Preis.</p></div>';
        }

        $neighbours = $this->rankingNeighbours($drink, $collection);

        return '<section class="section section--tint"><div class="wrap split split--sidebar"><div class="stack-lg">'
            . $noteHtml
            . $priceHtml
            . ($noteHtml === '' && $priceHtml === '' ? '<p class="meta">Für dieses Getränk sind noch keine weiteren Angaben erfasst.</p>' : '')
            . '</div><aside class="stack-lg">'
            . ($factsHtml !== ''
                ? '<div class="panel"><span class="eyebrow">Stammdaten</span><dl class="meta--dl" style="margin-top:var(--sp-3)">' . $factsHtml . '</dl></div>'
                : '')
            . $neighbours
            . '</aside></div></section>';
    }

    private function rankingNeighbours(RatedDrink $drink, RatedDrinkCollection $collection): string
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

        $rows = '';

        foreach ([$position - 1, $position + 1] as $neighbourIndex) {
            $neighbour = $ranked[$neighbourIndex] ?? null;

            if ($neighbour === null || $neighbour->result === null) {
                continue;
            }

            $rows .= '<a class="rank__row" href="/spezi/' . Html::e($neighbour->slug()) . '" style="grid-template-columns:auto 1fr auto">'
                . '<span class="rank__pos">' . ($neighbour->rank ?? '') . '</span>'
                . '<span class="rank__name" style="font-size:var(--fs-body)">' . Html::e($neighbour->name) . '</span>'
                . '<span class="rank__score" style="font-size:var(--fs-h4)">' . Html::grade($neighbour->result->gesamt()) . '</span></a>';
        }

        if ($rows === '') {
            return '';
        }

        return '<div class="stack"><span class="eyebrow">Nachbarn im Ranking</span><div class="rank">' . $rows . '</div></div>';
    }

    private function catalogCard(RatedDrink $drink): string
    {
        $foot = '<div class="card__foot">' . Html::stateBadge($drink->lifecycleStatus);

        if ($drink->isTested() && $drink->result !== null) {
            $foot .= '<span class="score"><span class="score__num">' . Html::grade($drink->result->gesamt()) . '</span></span>';
        } else {
            $foot .= '<span class="meta">–</span>';
        }

        $foot .= '</div>';

        return '<a class="card card-link" href="/spezi/' . Html::e($drink->slug()) . '">'
            . $this->productImage($drink)
            . '<div class="card__body"><span class="card__title">' . Html::e($drink->name) . '</span>'
            . '<span class="meta">' . Html::e($drink->manufacturer ?? $drink->displayOrigin() ?? '—') . '</span>'
            . $foot . '</div></a>';
    }

    private function catalogFilters(CatalogPage $page): string
    {
        $query = $page->query;
        $groups = '<div class="filter-group" style="border-top:0"><span class="filter-group__title">Status</span>';

        foreach (CatalogQuery::STATUSES as $status) {
            $checked = in_array($status, $query->statuses, true) ? ' checked' : '';
            $groups .= '<label class="check"><input type="checkbox" name="status[]" value="' . $status . '"' . $checked . '>'
                . '<span>' . Html::e(Html::stateLabel($status)) . '</span></label>';
        }

        $groups .= '</div><div class="filter-group"><span class="filter-group__title">Bild</span>'
            . '<label class="check"><input type="checkbox" name="with_image" value="1"' . ($query->withImageOnly ? ' checked' : '') . '>'
            . '<span>Nur mit Bild</span></label></div>'
            . '<div class="filter-group"><button class="btn btn--primary btn--block" type="submit">Anwenden</button>'
            . ($query->isFiltered() ? '<a class="btn btn--ghost" href="/spezis">Alle Filter zurücksetzen</a>' : '')
            . '</div>';

        $activeCount = count($query->statuses) + ($query->withImageOnly ? 1 : 0);

        return '<form method="get" action="/spezis">'
            . ($query->search !== '' ? '<input type="hidden" name="q" value="' . Html::e($query->search) . '">' : '')
            . $this->hiddenSortField($query)
            . '<details class="filter-panel"' . ($activeCount > 0 ? ' open' : '') . '>'
            . '<summary>Filter' . ($activeCount > 0 ? ' <span class="badge badge--red">' . $activeCount . '</span>' : '') . '</summary>'
            . '<div class="stack">' . $groups . '</div></details></form>';
    }

    private function catalogToolbar(CatalogPage $page): string
    {
        $query = $page->query;
        $chips = '';

        foreach (CatalogQuery::STATUSES as $status) {
            $active = in_array($status, $query->statuses, true);
            $target = $active ? $query->withoutStatus($status) : $query->withStatus($status);
            $href = '/spezis' . ($target->toQueryString() !== '' ? '?' . $target->toQueryString() : '');
            $chips .= '<a class="chip' . ($active ? ' chip--active' : '') . '" href="' . Html::e($href) . '">'
                . Html::e(Html::stateLabel($status)) . '</a>';
        }

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

        return '<div class="toolbar" style="margin:0"><div class="filters">' . $chips . '</div>'
            . '<form method="get" action="/spezis" class="cluster cluster--tight">'
            . ($query->search !== '' ? '<input type="hidden" name="q" value="' . Html::e($query->search) . '">' : '')
            . $this->hiddenStatusFields($query)
            . '<label class="label" for="sort">Sortierung</label>'
            . '<select class="select" id="sort" name="sort" style="width:auto" onchange="this.form.submit()">' . $options . '</select>'
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

    private function pagination(CatalogPage $page): string
    {
        if ($page->pageCount <= 1) {
            return '';
        }

        $links = '';

        for ($number = 1; $number <= $page->pageCount; ++$number) {
            if ($number === $page->page) {
                $links .= '<span aria-current="page">' . $number . '</span>';

                continue;
            }

            $qs = $page->query->toQueryString($number);
            $links .= '<a href="/spezis' . ($qs !== '' ? '?' . Html::e($qs) : '') . '">' . $number . '</a>';
        }

        return '<nav class="pagination" aria-label="Seiten">' . $links . '</nav>';
    }

    private function catalogSummary(CatalogPage $page): string
    {
        return sprintf('%d %s im Katalog.', $page->totalMatches, $page->totalMatches === 1 ? 'Eintrag' : 'Einträge');
    }

    private function figuresSection(RatedDrinkCollection $collection): string
    {
        $tested = $collection->tested();
        $best = $collection->ranked()[0]->result ?? null;

        return '<section class="section"><div class="wrap stack-lg">'
            . '<div class="cluster cluster--between"><div><span class="eyebrow">Zahlen zum Projekt</span>'
            . '<h2 class="display-3">Der Katalog in Zahlen</h2></div>'
            . '<a class="link-arrow" href="/statistik">Alle Statistiken</a></div>'
            . '<div class="figure-row">'
            . $this->figure((string) count($tested), 'Spezis getestet')
            . $this->figure((string) $collection->count(), 'Einträge im Katalog')
            . $this->figure($best !== null ? Html::grade($best->gesamt()) : '–', 'Beste Gesamtwertung')
            . $this->figure('3', 'Tester seit Beginn')
            . '</div></div></section>';
    }

    private function figure(string $value, string $label): string
    {
        return '<div class="figure"><span class="figure__num">' . Html::e($value) . '</span>'
            . '<p class="figure__label">' . Html::e($label) . '</p></div>';
    }

    private function activityRow(RatedDrink $drink): string
    {
        $line = match (true) {
            $drink->isTested() && $drink->result !== null => 'Getestet · Gesamtwertung ' . Html::grade($drink->result->gesamt()),
            $drink->lifecycleStatus === 'acquired' => 'Erworben, wartet auf den Testabend',
            default => 'Identifiziert, noch nicht im Kasten',
        };
        $date = Html::isoToGermanDate($drink->updatedAt) ?? '';

        return '<li class="card card--flat" style="padding-top:var(--sp-3)"><div class="cluster cluster--between" style="align-items:flex-start">'
            . '<div class="stack-sm"><a href="/spezi/' . Html::e($drink->slug()) . '" style="font-weight:700;text-decoration:none;font-size:var(--fs-h4)">'
            . Html::e($drink->name) . '</a><p class="meta">' . Html::e($line) . '</p></div>'
            . '<div class="cluster cluster--tight">' . Html::stateBadge($drink->lifecycleStatus)
            . '<span class="meta">' . Html::e($date) . '</span></div></div></li>';
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
            $rows .= '<div class="barchart__row' . $accent . '"><span class="barchart__label">' . Html::e($bin['label']) . '</span>'
                . '<span class="barchart__track"><i style="width:' . $width . '%"></i></span>'
                . '<span class="barchart__val">' . $bin['count'] . '</span></div>';
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

    private function manufacturerRows(Statistics $stats): string
    {
        $rows = '';

        foreach ($stats->manufacturers as $manufacturer) {
            $best = $manufacturer['best'];
            $rows .= '<tr><td><strong>' . Html::e($manufacturer['name']) . '</strong></td>'
                . '<td class="table__num">' . $manufacturer['count'] . '</td>'
                . '<td class="table__num">' . Html::gradeOrDash($manufacturer['averageGesamt']) . '</td>'
                . '<td>' . ($best !== null ? Html::e($best['name']) . ' (' . Html::grade($best['gesamt']) . ')' : '–') . '</td></tr>';
        }

        return $rows;
    }

    private function bestByCategoryPanel(Statistics $stats): string
    {
        $labels = ['optik' => 'Optik', 'sueffigkeit' => 'Süffigkeit', 'geschmack' => 'Geschmack'];
        $items = '';

        foreach ($labels as $key => $label) {
            $best = $stats->bestByCategory[$key];

            if ($best === null) {
                continue;
            }

            $items .= '<dt>' . Html::e($label) . '</dt><dd>' . Html::e($best['name']) . ' · ' . Html::grade($best['value'], 1) . '</dd>';
        }

        if ($items === '') {
            return '';
        }

        return '<div class="panel"><span class="eyebrow">Beste Einzelkriterien</span>'
            . '<dl class="meta--dl" style="margin-top:var(--sp-3)">' . $items . '</dl></div>';
    }

    private function regionPanel(Statistics $stats): string
    {
        if ($stats->regionCounts === []) {
            return '';
        }

        $items = '';

        foreach (array_slice($stats->regionCounts, 0, 8) as $entry) {
            $items .= '<dt>' . Html::e($entry['region']) . '</dt><dd>' . $entry['count'] . '</dd>';
        }

        return '<div class="panel"><span class="eyebrow">Herkunft</span>'
            . '<dl class="meta--dl" style="margin-top:var(--sp-3)">' . $items . '</dl>'
            . '<p class="meta" style="margin-top:var(--sp-3)">Aus dem Feld „Region“ bzw. „Ort“, soweit erfasst.</p></div>';
    }

    private function testerCard(string $name, string $description): string
    {
        return '<div class="card"><figure class="pimg pimg--square"><div class="pimg__ph"><span>Foto folgt</span></div></figure>'
            . '<div class="card__body"><span class="card__title">' . Html::e($name) . '</span>'
            . '<p class="meta">' . Html::e($description) . '</p></div></div>';
    }
}
