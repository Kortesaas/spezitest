<?php

declare(strict_types=1);

namespace Spezitest\Website\View;

use Spezitest\Website\Catalog\CatalogPage;
use Spezitest\Website\Catalog\CatalogQuery;
use Spezitest\Website\Catalog\OriginMap;
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
            . '<span class="eyebrow eyebrow--accent">Spezitest</span>'
            . '<h1 class="display-1">' . Html::e($this->headline($counts['tested'])) . '</h1>'
            . '<p class="lede">Wir haben jedes Cola-Mix-Getränk aus Deutschland und den Nachbarländern gefunden, gekauft '
            . 'und nach Optik, Süffigkeit und Geschmack bewertet.</p></div>'
            . '<div class="cluster"><a class="btn btn--primary btn--lg" href="/spezis">Zum Katalog</a>'
            . '<a class="btn btn--secondary btn--lg" href="/ranking">Zum Ranking</a></div></div>';

        if ($top !== [] && $best !== null) {
            $leader = $top[0];
            $hero .= '<a class="winner" href="/spezi/' . Html::e($leader->slug()) . '">'
                . $this->productImage($leader, 'pimg--hero')
                . '<span class="winner__tag"><span class="winner__score">' . Html::grade($best->gesamt()) . '</span>'
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

        return Layout::page(
            'Start',
            $hero . $topSection . $tailSection . $this->figuresSection($collection),
            'start',
            'Cola-Mix und Spezi im Test: Katalog, Ranking und Statistik der Abteilung Spezitest.',
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
            . '</div></div>';

        return Layout::page('Spezis', $body, 'spezis', 'Alle katalogisierten Cola-Mix- und Spezi-Getränke mit Status und Gesamtwertung.');
    }

    public function detail(RatedDrink $drink, RatedDrinkCollection $collection): string
    {
        $result = $drink->result;
        $origin = $drink->displayOrigin();
        $subtitleParts = array_values(array_filter([$drink->manufacturer, $origin]));

        $hero = '<div class="wrap" style="padding-top:var(--sp-4)"><nav aria-label="Brotkrumen"><ol class="breadcrumb">'
            . '<li><a href="/">Start</a></li><li><a href="/spezis">Spezis</a></li><li>' . Html::e($drink->name) . '</li></ol></nav></div>'
            . '<article><section class="wrap section" style="padding-top:var(--sp-5)"><div class="hero hero--detail">'
            . '<div>' . $this->productImage($drink, 'pimg--hero') . '</div>'
            . '<div class="stack-lg"><div class="stack">'
            . '<div class="cluster cluster--tight">' . Html::stateBadge($drink->lifecycleStatus, true)
            . '</div><h1 class="display-2">' . Html::e($drink->name) . '</h1>'
            . ($subtitleParts !== [] ? '<p class="lede">' . Html::e(implode(' · ', $subtitleParts)) . '</p>' : '')
            . '</div>';

        if ($result !== null) {
            $hero .= '<div class="verdict-row">'
                . ($drink->rank !== null
                    ? '<div class="verdict-rank"><span class="verdict-rank__num">#' . $drink->rank . '</span>'
                        . '<span class="verdict-rank__label">von ' . count($collection->tested()) . ' getesteten</span></div>'
                    : '')
                . '<div class="score"><span class="score__num">' . Html::grade($result->gesamt()) . '</span>'
                . '<span class="score__label">Gesamtwertung · 0–60</span></div></div>'
                . $this->ratingBreakdown($result)
                . $this->testerGrid($drink);
        } else {
            $hero .= '<div class="notice"><span>Noch nicht getestet – Wertung und Einzelnoten folgen nach dem Testabend.</span></div>';
        }

        $hero .= '</div></div></section>';

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
            . '<h1 class="display-2" style="color:#fff">Ranking</h1>'
            . '<p style="font-weight:700;font-size:var(--fs-body-lg)">'
            . ($ranked === []
                ? 'Noch kein Test abgeschlossen.'
                : count($ranked) . ' getestete Spezis · nach Gesamtwertung (0–60)')
            . '</p></div></div></div>';

        if ($ranked === []) {
            return Layout::page('Ranking', $band . '<div class="wrap section"><div class="empty">'
                . '<p class="empty__title">Noch kein Ranking</p>'
                . '<p>Es wurde noch kein Test abgeschlossen.</p></div></div>', 'ranking');
        }

        $body = $band . '<div class="wrap section">'
            . ($podium !== [] ? '<div class="podium" style="margin-block:var(--sp-6)">' . $this->podium($podium) . '</div>' : '')
            . '<div class="rank">' . $this->rankRows($rest, 0) . '</div>'
            . '<p class="meta" style="margin-top:var(--sp-6)">Gesamtwertung = Optik ×1 + Süffigkeit ×2 + Geschmack ×3, je 0–10. '
            . '<a href="/ueber#methode">Methode</a></p>'
            . '</div>';

        return Layout::page('Ranking', $body, 'ranking', 'Das vollständige Spezitest-Ranking nach Gesamtwertung.');
    }

    public function statistik(Statistics $stats, OriginMap $map): string
    {
        $intro = '<section class="wrap section">'
            . '<h1 class="display-2">Statistik</h1>';

        if ($stats->testedCount === 0) {
            $intro .= '<div class="empty" style="margin-top:var(--sp-6)"><p class="empty__title">Noch keine Auswertung</p>'
                . '<p>Erscheint mit dem ersten abgeschlossenen Test. Erfasst: ' . $stats->total . '.</p></div></section>';

            return Layout::page('Statistik', $intro, 'statistik');
        }

        $intro .= '<div class="figure-row" style="margin-top:var(--sp-6)">'
            . $this->figure((string) $stats->testedCount, 'getestet')
            . $this->figure((string) $stats->total, 'im Katalog')
            . $this->figure(Html::gradeOrDash($stats->averageGesamt), 'Ø Gesamt')
            . $this->figure((string) $stats->lifecycleCounts['identified'], 'noch gesucht')
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
            . '</aside></div></section>';

        return Layout::page(
            'Statistik',
            $intro . $this->originMapSection($map) . $distribution . $tables,
            'statistik',
            'Auswertung der Spezitest-Testabende: Herkunftskarte, Verteilung, Tester und Hersteller.',
        );
    }

    public function ueber(RatedDrinkCollection $collection): string
    {
        $counts = $collection->lifecycleCounts();
        $body = '<section class="wrap section"><div class="split" style="align-items:center">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Über das Projekt</span>'
            . '<h1 class="display-2">Wir trinken das, damit du es nicht musst.</h1>'
            . '<p class="lede">Ein privates Projekt mit einer Aufgabe: jedes Cola-Mix-Getränk finden, kaufen und '
            . 'nach denselben Kriterien bewerten. Bisher '
            . $counts['tested'] . ' ' . ($counts['tested'] === 1 ? 'getesteter Spezi' : 'getestete Spezis') . '.</p></div>'
            . '<figure class="team-photo"><img src="/assets/spezitest-team.jpg" '
            . 'alt="Manu, Fabi und Schorsch hinter einem Tisch voller Cola-Mix-Flaschen" '
            . 'width="1600" height="921" loading="lazy"></figure></div></section>'

            . '<section class="section section--tint" id="methode"><div class="wrap split split--sidebar">'
            . '<div class="prose stack-lg"><div class="stack"><span class="eyebrow">Methode</span>'
            . '<h2 class="display-3">Wie getestet wird</h2></div>'
            . '<p>Gleiche Temperatur, gleiches Glas. Jeder der drei Tester vergibt für Optik, Süffigkeit und '
            . 'Geschmack eine Note von 0 bis 10 – höher ist besser.</p>'
            . '<h3>Optik</h3><p>Farbe im Glas, Kohlensäure, Schaum, Flasche oder Dose.</p>'
            . '<h3>Süffigkeit</h3><p>Wie leicht sich das Glas leert. Süße, Säure, Abgang.</p>'
            . '<h3>Geschmack</h3><p>Verhältnis von Cola zu Orange, Aromatik, Eigenständigkeit.</p>'
            . '<h3>Gesamtwertung</h3><p>Gewichtet: Optik ×1, Süffigkeit ×2, Geschmack ×3. Ergebnis 0 bis 60.</p>'
            . '<h3>Preis / Leistung</h3><p>Nur, wenn ein Preis erfasst wurde.</p>'
            . '<p class="meta">Keine bezahlten Tests. Keine nachträgliche Änderung der Methodik.</p></div>'
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
            . '<h2 class="display-3" style="color:#fff">Fehlt uns ein Spezi?</h2>'
            . '<div class="stack"><p class="lede" style="color:rgba(255,255,255,.86)">Erst im Katalog nachsehen – '
            . 'was dort fehlt, suchen wir.</p>'
            . '<div class="cluster"><a class="btn btn--on-navy" href="/spezis">Katalog prüfen</a></div></div></div></section>';

        return Layout::page('Über Spezitest', $body, 'ueber', 'Die Testmethode und die Tester hinter Spezitest.');
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
            . '<div><h2>Haftung für Inhalte</h2>'
            . '<p>Wir sind gemäß § 7 Abs. 1 TMG als Diensteanbieter für eigene Inhalte auf diesen Seiten nach den '
            . 'allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 TMG sind wir jedoch nicht verpflichtet, '
            . 'übermittelte oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu forschen, '
            . 'die auf eine rechtswidrige Tätigkeit hinweisen. Verpflichtungen zur Entfernung oder Sperrung der '
            . 'Nutzung von Informationen nach den allgemeinen Gesetzen bleiben hiervon unberührt. Eine Haftung ist '
            . 'jedoch erst ab dem Zeitpunkt der Kenntnis einer konkreten Rechtsverletzung möglich. Bei '
            . 'Bekanntwerden von entsprechenden Rechtsverletzungen werden wir diese Inhalte umgehend entfernen.</p></div>'
            . '<div><h2>Haftung für Links</h2>'
            . '<p>Unser Blog kann Links zu externen Websites Dritter enthalten, auf deren Inhalte wir keinen '
            . 'Einfluss haben. Daher können wir für diese fremden Inhalte auch keine Gewähr übernehmen. Für die '
            . 'Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder Betreiber verantwortlich. '
            . 'Rechtswidrige Inhalte waren zum Zeitpunkt der Verlinkung nicht erkennbar. Eine permanente '
            . 'inhaltliche Kontrolle der verlinkten Seiten ist ohne konkrete Anhaltspunkte einer Rechtsverletzung '
            . 'nicht zumutbar. Bei Bekanntwerden von Rechtsverletzungen werden wir derartige Links umgehend '
            . 'entfernen.</p></div>'
            . '<div><h2>Urheberrecht</h2>'
            . '<p>Die durch uns erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen '
            . 'Urheberrecht. Beiträge Dritter sind als solche gekennzeichnet. Die Vervielfältigung, Bearbeitung, '
            . 'Verbreitung sowie jede Art der Verwertung außerhalb der Grenzen des Urheberrechts bedürfen der '
            . 'schriftlichen Zustimmung des jeweiligen Autors bzw. Erstellers. Downloads und Kopien dieser Seite '
            . 'sind nur für den privaten, nicht kommerziellen Gebrauch gestattet.</p></div>'
            . '</div></section>';

        return Layout::page('Impressum', $body, 'impressum', 'Impressum und Anbieterkennzeichnung von Spezitest.');
    }

    /**
     * Privacy policy, carried over from the previous spezitest.de site. The
     * address of the responsible party was corrected to Zeppelinstraße 16 1/2;
     * the wording is otherwise unchanged.
     */
    public function datenschutz(): string
    {
        $body = '<section class="wrap section"><div class="prose stack-lg">'
            . '<div class="stack"><span class="eyebrow eyebrow--accent">Rechtliches</span>'
            . '<h1 class="display-2">Datenschutz</h1></div>'

            . '<div><h2>1. Datenschutz auf einen Blick</h2>'
            . '<h3>Allgemeine Hinweise</h3>'
            . '<p>Die folgenden Hinweise geben einen einfachen Überblick darüber, was mit Ihren personenbezogenen '
            . 'Daten passiert, wenn Sie unsere Website besuchen. Personenbezogene Daten sind alle Daten, mit denen '
            . 'Sie persönlich identifiziert werden können. Ausführliche Informationen zum Thema Datenschutz '
            . 'entnehmen Sie unserer unter diesem Text aufgeführten Datenschutzerklärung.</p>'
            . '<h3>Wer ist verantwortlich für die Datenerfassung auf dieser Website?</h3>'
            . '<p>Die Datenverarbeitung auf dieser Website erfolgt durch den Websitebetreiber. Dessen Kontaktdaten '
            . 'können Sie dem <a href="/impressum">Impressum</a> dieser Website entnehmen.</p>'
            . '<h3>Wie erfassen wir Ihre Daten?</h3>'
            . '<p>Ihre Daten werden zum einen dadurch erhoben, dass Sie uns diese mitteilen. Hierbei kann es sich '
            . 'z.&nbsp;B. um Daten handeln, die Sie in ein Kontaktformular eingeben. Andere Daten werden automatisch '
            . 'beim Besuch der Website durch unsere IT-Systeme erfasst. Das sind vor allem technische Daten '
            . '(z.&nbsp;B. Internetbrowser, Betriebssystem oder Uhrzeit des Seitenaufrufs). Die Erfassung dieser '
            . 'Daten erfolgt automatisch, sobald Sie unsere Website betreten.</p>'
            . '<h3>Wofür nutzen wir Ihre Daten?</h3>'
            . '<p>Ein Teil der Daten wird erhoben, um eine fehlerfreie Bereitstellung der Website zu gewährleisten. '
            . 'Andere Daten können zur Analyse Ihres Nutzerverhaltens verwendet werden.</p>'
            . '<h3>Welche Rechte haben Sie bezüglich Ihrer Daten?</h3>'
            . '<p>Sie haben jederzeit das Recht unentgeltlich Auskunft über Herkunft, Empfänger und Zweck Ihrer '
            . 'gespeicherten personenbezogenen Daten zu erhalten. Sie haben außerdem ein Recht, die Berichtigung, '
            . 'Sperrung oder Löschung dieser Daten zu verlangen. Hierzu sowie zu weiteren Fragen zum Thema '
            . 'Datenschutz können Sie sich jederzeit unter der im Impressum angegebenen Adresse an uns wenden. Des '
            . 'Weiteren steht Ihnen ein Beschwerderecht bei der zuständigen Aufsichtsbehörde zu.</p>'
            . '<h3>Analyse-Tools und Tools von Drittanbietern</h3>'
            . '<p>Beim Besuch unserer Website kann Ihr Surf-Verhalten statistisch ausgewertet werden. Das geschieht '
            . 'vor allem mit Cookies und mit sogenannten Analyseprogrammen. Die Analyse Ihres Surf-Verhaltens '
            . 'erfolgt in der Regel anonym; das Surf-Verhalten kann nicht zu Ihnen zurückverfolgt werden. Sie '
            . 'können dieser Analyse widersprechen oder sie durch die Nichtbenutzung bestimmter Tools verhindern. '
            . 'Detaillierte Informationen dazu finden Sie in der folgenden Datenschutzerklärung. Sie können dieser '
            . 'Analyse widersprechen. Über die Widerspruchsmöglichkeiten werden wir Sie in dieser '
            . 'Datenschutzerklärung informieren.</p></div>'

            . '<div><h2>2. Allgemeine Hinweise und Pflichtinformationen</h2>'
            . '<h3>Hinweis zur verantwortlichen Stelle</h3>'
            . '<p>Die verantwortliche Stelle für die Datenverarbeitung auf dieser Website ist:</p>'
            . '<p>ABOUT US Media GmbH<br>Zeppelinstraße 16 1/2<br>86343 Königsbrunn</p>'
            . '<p>Telefon: <a href="tel:+4915902608764">+49 (0) 1590 2608764</a><br>'
            . 'E-Mail: <a href="mailto:hallo@aboutusmedia.de">hallo@aboutusmedia.de</a></p>'
            . '<p>Verantwortliche Stelle ist die natürliche oder juristische Person, die allein oder gemeinsam mit '
            . 'anderen über die Zwecke und Mittel der Verarbeitung von personenbezogenen Daten (z.&nbsp;B. Namen, '
            . 'E-Mail-Adressen o.&nbsp;Ä.) entscheidet.</p>'
            . '<h3>SSL- bzw. TLS-Verschlüsselung</h3>'
            . '<p>Diese Seite nutzt aus Sicherheitsgründen und zum Schutz der Übertragung vertraulicher Inhalte, '
            . 'wie zum Beispiel Bestellungen oder Anfragen, die Sie an uns als Seitenbetreiber senden, eine SSL- '
            . 'bzw. TLS-Verschlüsselung. Eine verschlüsselte Verbindung erkennen Sie daran, dass die Adresszeile '
            . 'des Browsers von „http://“ auf „https://“ wechselt und an dem Schloss-Symbol in Ihrer Browserzeile. '
            . 'Wenn die SSL- bzw. TLS-Verschlüsselung aktiviert ist, können die Daten, die Sie an uns übermitteln, '
            . 'nicht von Dritten mitgelesen werden.</p></div>'
            . '</div></section>';

        return Layout::page('Datenschutz', $body, 'datenschutz', 'Datenschutzerklärung von Spezitest.');
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

        return Layout::page('Seite nicht gefunden', $body, '');
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
                . '<span class="rank__score">' . Html::grade($result->gesamt()) . '<small>Wertung</small></span></a>';
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
                . '<span class="podium__score">' . Html::grade($result->gesamt()) . '<small>Wertung</small></span>'
                . '</a>';
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

        return '<div class="stack"><span class="eyebrow">Tester · Mittel</span>'
            . '<div class="testers">' . $cells . '</div></div>';
    }

    private function detailSidebar(RatedDrink $drink, RatedDrinkCollection $collection): string
    {
        // The hero subtitle already carries the manufacturer and the display
        // origin; the panel only adds what is not visible there.
        $shown = array_filter([$drink->manufacturer, $drink->displayOrigin()]);
        $facts = [];

        foreach ([['Ort', $drink->originLocation], ['Region', $drink->originRegion]] as [$term, $value]) {
            if ($value !== null && !in_array($value, $shown, true)) {
                $facts[] = [$term, $value];
            }
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
            $priceHtml = '<div class="stack"><span class="eyebrow">Preis / Leistung</span>'
                . '<div class="cluster" style="gap:var(--sp-6)">'
                . '<div class="score"><span class="score__num">' . Html::e(Html::price($drink->priceAmount)) . '</span>'
                . '<span class="score__label">pro Gebinde</span></div>'
                . ($pp !== null
                    ? '<div class="score"><span class="score__num">' . Html::grade((float) $pp->normalized() * 100, 0) . '</span>'
                        . '<span class="score__label">von 100 · Preis / Leistung</span></div>'
                    : '')
                . '</div>'
                . ($pp !== null ? '<p class="meta">100 = bestes Verhältnis aus Gesamtwertung und Preis unter allen '
                    . 'getesteten Spezis mit erfasstem Preis.</p>' : '')
                . '</div>';
        }

        $neighbours = $this->rankingNeighbours($drink, $collection);

        if ($noteHtml === '' && $priceHtml === '' && $factsHtml === '' && $neighbours === '') {
            return '';
        }

        return '<section class="section section--tint"><div class="wrap split split--sidebar"><div class="stack-lg">'
            . $noteHtml
            . $priceHtml
            . ($noteHtml === '' && $priceHtml === '' ? '<p class="meta">Keine weiteren Angaben erfasst.</p>' : '')
            . '</div><aside class="stack-lg">'
            . ($factsHtml !== ''
                ? '<div class="panel"><span class="eyebrow">Details</span><dl class="meta--dl meta--dl-stack" style="margin-top:var(--sp-3)">' . $factsHtml . '</dl></div>'
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
        $result = $drink->isTested() ? $drink->result : null;
        $foot = $result !== null
            ? '<div class="card__foot">'
                . ($drink->rank !== null
                    ? '<span class="card__rank">#' . $drink->rank . '</span>'
                    : '<span class="card__rank card__rank--none">Getestet</span>')
                . '<span class="card__score">' . Html::grade($result->gesamt()) . '<small>Wertung</small></span></div>'
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

    /**
     * Manufacturers that have a comparable average come first; the rest are
     * still listed, but they no longer push the useful rows off the screen.
     */
    private function manufacturerRows(Statistics $stats): string
    {
        $entries = $stats->manufacturers;
        usort($entries, static function (array $a, array $b): int {
            $left = $a['averageGesamt'];
            $right = $b['averageGesamt'];

            if ($left === null && $right === null) {
                return $b['count'] <=> $a['count'];
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return $right <=> $left;
        });

        $rows = '';

        foreach ($entries as $manufacturer) {
            $best = $manufacturer['best'];
            $rows .= '<tr><td><strong>' . Html::e($manufacturer['name']) . '</strong></td>'
                . '<td class="table__num">' . $manufacturer['count'] . '</td>'
                . '<td class="table__num">'
                . ($manufacturer['averageGesamt'] === null ? '–' : Html::grade($manufacturer['averageGesamt'])) . '</td>'
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
            . '<dl class="meta--dl meta--dl-stack" style="margin-top:var(--sp-3)">' . $items . '</dl></div>';
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

    private function testerCard(string $name, string $description): string
    {
        return '<div class="card"><figure class="pimg pimg--square"><div class="pimg__ph"><span>Foto folgt</span></div></figure>'
            . '<div class="card__body"><span class="card__title">' . Html::e($name) . '</span>'
            . '<p class="meta">' . Html::e($description) . '</p></div></div>';
    }
}
