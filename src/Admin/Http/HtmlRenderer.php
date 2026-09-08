<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

use Spezitest\Admin\Testing\TestFormData;
use Spezitest\Admin\Testing\TestRun;
use Spezitest\Admin\Testing\TestStreamPosition;
use Spezitest\Domain\Rating\RatingResult;
use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\StreamEpisode;

/**
 * Server-rendered admin HTML, styled with the Spezitest Design System admin
 * shell. The admin is deliberately denser and more functional than the public
 * site, but uses the same tokens, type scale, colours and image treatment.
 *
 * Every authenticated page receives the lifecycle counts so the sidebar can
 * show live workload numbers and the filter chips can show their sizes; the
 * dashboard's "Schnell erfassen" widget stays a single short form (name +
 * status + optional picture), while the dedicated `/admin/drinks/new` page
 * shows every optional enrichment field inline so a Spezi can be made
 * test-ready in one step.
 *
 * All user-controlled values are escaped for their output context.
 */
final class HtmlRenderer
{
    private const GRADE_MIN = 0;

    private const GRADE_MAX = 10;

    /** The Gesamtwertung's theoretical maximum, shown next to every score. */
    private const GESAMT_MAX = 60;

    private const TESTERS = ['manu' => 'Manu', 'fabi' => 'Fabi', 'schorsch' => 'Schorsch'];

    private const CATEGORIES = ['optik' => 'Optik', 'sueffigkeit' => 'Süffigkeit', 'geschmack' => 'Geschmack'];

    /** The verified engine's category weights, shown as context on the forms. */
    private const WEIGHTS = ['optik' => 1, 'sueffigkeit' => 2, 'geschmack' => 3];

    private const STATUS_LABELS = [
        'identified' => 'Identifiziert',
        'acquired' => 'Erworben',
        'tested' => 'Getestet',
    ];

    private const SORT_LABELS = [
        'name' => 'Name A–Z',
        'name_desc' => 'Name Z–A',
        'region' => 'Herkunft A–Z',
        'region_desc' => 'Herkunft Z–A',
        'price_asc' => 'Preis: günstigste zuerst',
        'price_desc' => 'Preis: teuerste zuerst',
        'status' => 'Status: identifiziert zuerst',
        'status_desc' => 'Status: getestet zuerst',
        'recent' => 'Zuletzt geändert',
    ];

    /**
     * Sortable table columns, each with its ascending and descending key. The
     * header cycles ascending → descending → back to the default order.
     */
    private const SORTABLE_COLUMNS = [
        'name' => ['name', 'name_desc'],
        'region' => ['region', 'region_desc'],
        'price' => ['price_asc', 'price_desc'],
        'status' => ['status', 'status_desc'],
    ];

    /** The order a list falls back to, and the third click's destination. */
    private const DEFAULT_SORT = 'name';

    private const FLAG_LABELS = [
        'no_image' => 'Ohne Bild',
        'has_image' => 'Mit Bild',
        'no_price' => 'Ohne Preis',
        'needs_photo' => 'Neues Foto nötig',
    ];

    // --- pages -----------------------------------------------------------

    public function login(string $csrfToken, ?string $error = null): string
    {
        $body = '<div class="auth"><div class="auth__inner">'
            . '<a class="brand" href="/"><img src="/assets/spezitest-logo-color.svg" alt="Spezitest" width="150" height="35"></a>'
            . '<div class="panel panel--pad stack-lg">'
            . '<div class="stack-sm"><span class="eyebrow eyebrow--accent">Verwaltung</span>'
            . '<h1 class="h1">Anmelden</h1></div>'
            . $this->error($error)
            . '<form method="post" action="/admin/login" class="stack">'
            . $this->csrfField($csrfToken)
            . '<div class="field"><label class="label" for="u">Benutzername</label>'
            . '<input class="input" id="u" name="username" autocomplete="username" autofocus required></div>'
            . '<div class="field"><label class="label" for="p">Passwort</label>'
            . '<input class="input" id="p" type="password" name="password" autocomplete="current-password" required></div>'
            . '<button class="btn btn--primary btn--block" type="submit">Anmelden</button></form></div>'
            . '<p class="auth__foot">Interner Bereich der Abteilung Spezitest. '
            . '<a href="/">Zur Website</a></p>'
            . '</div></div>';

        return $this->document('Anmeldung', $body, null);
    }

    /**
     * @param array{identified: int, acquired: int, tested: int} $counts
     * @param array{no_image: int, no_price: int, needs_photo: int} $quality
     * @param list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, has_primary_image: bool, needs_new_photo: bool}> $waiting
     */
    public function dashboard(array $counts, array $quality, array $waiting, string $csrfToken): string
    {
        $total = $counts['identified'] + $counts['acquired'] + $counts['tested'];

        $body = $this->head(
            'Übersicht',
            $total === 0
                ? 'Noch keine Spezi erfasst. Der erste Eintrag startet den Katalog.'
                : '<strong>' . $counts['tested'] . '</strong> von ' . $total . ' Spezis getestet · '
                    . '<strong>' . $counts['acquired'] . '</strong> ' . ($counts['acquired'] === 1 ? 'wartet' : 'warten')
                    . ' auf den Spezistream.',
            '<a class="btn btn--accent" href="/admin/drinks/new">+ Spezi hinzufügen</a>',
        )
            . '<div class="grid grid--4" style="margin-bottom:var(--sp-5)">'
            . $this->statTile('identified', $counts['identified'], $total, 'noch nicht im Kasten')
            . $this->statTile('acquired', $counts['acquired'], $total, 'bereit zum Testen')
            . $this->statTile('tested', $counts['tested'], $total, 'Wertung liegt vor')
            . '<a class="panel stat stat--total" href="/admin/drinks">'
            . '<span class="badge badge--outline">Gesamt</span>'
            . '<span class="stat__num">' . $total . '</span>'
            . '<span class="stat__meter"><i style="width:100%"></i></span>'
            . '<span class="stat__foot"><span>im Katalog</span><span>Alle ansehen</span></span></a>'
            . '</div>'
            . $this->progressPanel($counts, $total)
            . '<div class="split split--wide" style="margin-top:var(--sp-5)">'
            . $this->queuePanel($waiting, $counts['acquired'])
            . '<div class="stack-lg">'
            . '<section class="panel panel--pad"><div class="panel__head">'
            . '<h2 class="panel__title">Schnell erfassen</h2><span class="meta">Name genügt</span></div>'
            . $this->quickAddForm($csrfToken)
            . '</section>'
            . $this->qualityPanel($quality, $total)
            . '</div></div>';

        return $this->document('Übersicht', $body, $counts, $csrfToken, 'dashboard');
    }

    /**
     * @param list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, has_primary_image: bool, needs_new_photo: bool}> $drinks
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function drinks(
        array $drinks,
        DrinkListFilters $filters,
        array $counts,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $rows = '';

        foreach ($drinks as $drink) {
            $rows .= $this->drinkRow($drink, $csrfToken);
        }

        $table = $rows === ''
            ? $this->emptyState(
                'Keine Spezis gefunden',
                'Andere Suchbegriffe oder Filter probieren.',
                $filters->isFiltered()
                    ? '<a class="btn btn--secondary btn--sm" href="/admin/drinks">Filter zurücksetzen</a>'
                    : '<a class="btn btn--accent btn--sm" href="/admin/drinks/new">+ Spezi hinzufügen</a>',
            )
            : '<section class="panel panel--flush"><div class="table-scroll">'
                . '<table class="table table--drinks"><thead><tr>'
                . '<th><span class="visually-hidden">Bild</span></th>'
                . $this->sortableHeader('Spezi', 'name', $filters)
                . $this->sortableHeader('Herkunft', 'region', $filters)
                . $this->sortableHeader('Preis / 0,5&nbsp;l', 'price', $filters, ' class="table__num"')
                . $this->sortableHeader('Status', 'status', $filters)
                . '<th class="table__actions">Aktionen</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div></section>';

        $body = $this->head(
            'Spezis',
            'Der vollständige Katalog mit allen erfassten Parametern.',
            '<a class="btn btn--accent" href="/admin/drinks/new">+ Spezi hinzufügen</a>',
        )
            . $this->error($error)
            . $this->filterBar($filters, $counts, count($drinks))
            . $table;

        return $this->document('Spezis', $body, $counts, $csrfToken, 'drinks');
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function createForm(array $counts, string $csrfToken, array $values = [], ?string $error = null): string
    {
        $body = $this->head(
            'Spezi hinzufügen',
            'Pflicht sind nur Name und Status. Alles andere lässt sich jederzeit nachtragen.',
            '<a class="btn btn--ghost" href="/admin/drinks">Zur Liste</a>',
            $this->breadcrumb([['/admin/drinks', 'Spezis'], [null, 'Neu']]),
        )
            . $this->error($error)
            . '<form method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<div class="split split--sidebar"><div class="stack-lg">'
            . $this->basicsPanel(
                'c',
                $this->stringValue($values, 'name') ?? '',
                $this->stringValue($values, 'manufacturer') ?? '',
                $this->stringValue($values, 'lifecycle_status') ?? 'identified',
                false,
                true,
            )
            . $this->originPanel(
                'c',
                $this->stringValue($values, 'origin_location') ?? '',
                $this->stringValue($values, 'origin_region') ?? '',
            )
            . $this->pricePanel(
                'c',
                $this->stringValue($values, 'price') ?? '',
                $this->stringValue($values, 'price_volume_ml') ?? '',
            )
            . $this->notesPanel('c', $this->stringValue($values, 'notes') ?? '')
            . '</div><aside class="stack-lg">'
            . '<section class="panel panel--pad aside-card aside-sticky">'
            . '<div class="panel__head"><h2 class="panel__title">Bild</h2><span class="meta">optional</span></div>'
            . '<figure class="pimg pimg--square"><div class="pimg__ph"><span>Kein Bild</span></div></figure>'
            . $this->pictureField('cpic', '', false)
            . '<p class="hint">Ein Foto lässt sich später jederzeit nachreichen.</p>'
            . '</section></aside></div>'
            . '<div class="formbar"><button class="btn btn--accent" type="submit">Spezi hinzufügen</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a>'
            . '<span class="meta formbar__end">Nach dem Speichern geht es direkt zur Detailseite.</span></div>'
            . '</form>';

        return $this->document('Spezi hinzufügen', $body, $counts, $csrfToken, 'create');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, needs_new_photo: bool} $drink
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function editForm(
        array $drink,
        bool $hasImage,
        array $counts,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $id = $drink['id'];
        $tested = $drink['lifecycle_status'] === 'tested';

        $actions = '';

        if (in_array($drink['lifecycle_status'], ['acquired', 'tested'], true)) {
            $actions .= '<a class="btn btn--secondary btn--sm" href="/admin/drinks/' . $id . '/test">'
                . ($tested ? 'Test bearbeiten' : 'Test erfassen') . '</a>';
        }

        if ($tested) {
            $actions .= '<a class="btn btn--secondary btn--sm" href="/admin/drinks/' . $id . '/test/result">Ergebnis</a>';
        }

        $actions .= '<a class="btn btn--ghost btn--sm" href="/spezi/' . $id . '">Öffentliche Seite</a>';

        $body = $this->head(
            $this->escape($drink['name']),
            'Alle Angaben außer Name und Status sind optional.',
            $actions,
            $this->breadcrumb([['/admin/drinks', 'Spezis'], [null, $drink['name']]]),
            $this->stateBadge($drink['lifecycle_status']),
        )
            . $this->error($error)
            . '<form method="post" action="/admin/drinks/' . $id . '" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<div class="split split--sidebar"><div class="stack-lg">'
            . $this->basicsPanel(
                'e',
                $drink['name'],
                $drink['manufacturer'] ?? '',
                $drink['lifecycle_status'],
                $tested,
                false,
            )
            . $this->originPanel('e', $drink['origin_location'] ?? '', $drink['origin_region'] ?? '')
            . $this->pricePanel(
                'e',
                $this->priceForDisplay($drink['price_amount']),
                $drink['price_volume_ml'] !== null ? (string) $drink['price_volume_ml'] : '',
            )
            . $this->notesPanel('e', $drink['notes'] ?? '')
            . '</div><aside class="stack-lg">'
            . $this->imagePanel($id, $hasImage, $drink['needs_new_photo'])
            . $this->factsPanel($drink, $hasImage)
            . '</aside></div>'
            . '<div class="formbar"><button class="btn btn--primary" type="submit">Änderungen speichern</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a>'
            . '<span class="formbar__end">'
            . '<a class="btn btn--ghost btn--danger btn--sm" href="/admin/drinks/' . $id . '/delete">Löschen</a>'
            . '</span></div>'
            . '</form>';

        return $this->document('Spezi bearbeiten', $body, $counts, $csrfToken, 'drinks');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, needs_new_photo: bool} $drink
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function deleteConfirmation(
        array $drink,
        array $counts,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $id = $drink['id'];
        $body = $this->head(
            'Spezi löschen',
            '',
            '',
            $this->breadcrumb([
                ['/admin/drinks', 'Spezis'],
                ['/admin/drinks/' . $id . '/edit', $drink['name']],
                [null, 'Löschen'],
            ]),
        )
            . '<section class="panel panel--pad panel--danger stack" style="max-width:var(--w-text)">'
            . '<div class="panel__head"><h2 class="panel__title">Löschen bestätigen</h2></div>'
            . $this->error($error)
            . '<p>Soll „<strong>' . $this->escape($drink['name']) . '</strong>“ wirklich gelöscht werden? '
            . 'Alle erfassten Angaben und das hinterlegte Bild werden dabei entfernt; '
            . 'das lässt sich nicht rückgängig machen.</p>'
            . '<p class="meta">Getestete Einträge mit Noten lassen sich nicht löschen. Dort bleibt nur '
            . 'das Bearbeiten.</p>'
            . '<form method="post" action="/admin/drinks/' . $id . '/delete" class="form-actions">'
            . $this->csrfField($csrfToken)
            . '<button class="btn btn--secondary btn--danger-solid" type="submit">Endgültig löschen</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks/' . $id . '/edit">Abbrechen</a></form></section>';

        return $this->document('Spezi löschen', $body, $counts, $csrfToken, 'drinks');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, needs_new_photo: bool} $drink
     * @param list<TestRun> $runs
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function testForm(
        array $drink,
        TestFormData $data,
        array $runs,
        array $counts,
        string $csrfToken,
        bool $hasImage = false,
        ?string $error = null,
    ): string {
        $id = $drink['id'];
        $panels = '';

        foreach (self::CATEGORIES as $category => $categoryLabel) {
            $rows = '';

            foreach (self::TESTERS as $code => $label) {
                $grade = $data->grade($code, $category);
                $rows .= '<div class="graderow"><div class="graderow__head">'
                    . '<span class="graderow__tester">' . $this->escape($label) . '</span>'
                    . '<span class="graderow__value">' . ($grade === '' ? 'keine Note' : $this->escape($grade) . ' / 10') . '</span>'
                    . '</div>'
                    . $this->gradeScale(
                        $code . '_' . $category,
                        $grade,
                        $categoryLabel . ' – ' . $label,
                    ) . '</div>';
            }

            $panels .= '<section class="panel panel--pad gradegroup"><div class="panel__head">'
                . '<h2 class="panel__title">' . $this->escape($categoryLabel) . '</h2>'
                . '<div class="cluster cluster--tight">'
                . '<span class="gradegroup__weight">Gewicht ×' . self::WEIGHTS[$category] . '</span>'
                . '<span class="gradegroup__avg" data-category-avg="' . $category . '">Ø '
                . $this->categoryAverage($data, $category) . '</span></div></div>'
                . '<div>' . $rows . '</div></section>';
        }

        $filled = $this->gradeCount($data);

        $body = $this->head(
            'Test erfassen',
            'Noten 0–10, höher ist besser. Erst alle neun Noten schließen den Test ab.',
            '<a class="btn btn--ghost btn--sm" href="/admin/drinks/' . $id . '/edit">Stammdaten bearbeiten</a>',
            $this->breadcrumb([
                ['/admin/drinks', 'Spezis'],
                ['/admin/drinks/' . $id . '/edit', $drink['name']],
                [null, 'Test'],
            ]),
        )
            . $this->error($error)
            . ($drink['lifecycle_status'] === 'identified'
                ? '<p class="notice notice--error"><span>Diese Spezi ist noch nicht erworben. '
                    . '<a href="/admin/drinks/' . $id . '/edit">Bitte zuerst auf „Erworben“ setzen.</a></span></p>'
                : '')
            . '<form method="post" action="/admin/drinks/' . $id . '/test" class="stack-lg" data-test-form>'
            . $this->csrfField($csrfToken)
            . $this->testBar($drink, $data, $hasImage, $filled)
            . $panels
            . $this->streamPanel($data, $runs)
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Testnotiz</h2>'
            . '<span class="meta">optional</span></div>'
            . '<div class="field"><label class="visually-hidden" for="tn">Testnotiz</label>'
            . '<textarea class="textarea" id="tn" name="notes" '
            . 'placeholder="Farbe, Kohlensäure, Süße, Orangenanteil …">' . $this->escape($data->notes) . '</textarea>'
            . '<span class="hint">Erscheint auf der öffentlichen Detailseite.</span></div>'
            . '</section>'
            . '<div class="sticky-actions">'
            . '<button class="btn btn--accent" type="submit" formaction="/admin/drinks/' . $id . '/test/complete">'
            . ($data->isCompleted() ? 'Änderungen speichern' : 'Test abschließen') . '</button>'
            . ($data->isCompleted()
                ? '<a class="btn btn--secondary" href="/admin/drinks/' . $id . '/test/result">Ergebnis ansehen</a>'
                : '<button class="btn btn--secondary" type="submit">Zwischenspeichern</button>')
            . '<a class="btn btn--ghost" href="/admin/test">Abbrechen</a>'
            . '<span class="meta formbar__end">' . ($data->isCompleted()
                ? 'Speichern rechnet die Wertung neu. Alle 9 Noten nötig.'
                : 'Abschließen setzt den Status auf „Getestet“. Alle 9 Noten nötig.') . '</span>'
            . '</div></form>';

        return $this->document('Test erfassen', $body, $counts, $csrfToken, 'test');
    }

    /**
     * @param list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, has_primary_image: bool, needs_new_photo: bool}> $drinks
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function testQueue(
        array $drinks,
        DrinkListFilters $filters,
        array $counts,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $rows = '';

        foreach ($drinks as $drink) {
            $flags = '';

            if (!$drink['has_primary_image']) {
                $flags .= '<span class="badge">Kein Bild</span>';
            }

            if ($drink['price_amount'] === null || $drink['price_volume_ml'] === null) {
                $flags .= '<span class="badge">Kein Preis</span>';
            }

            $rows .= '<li class="queue__row">'
                . $this->thumbnail($drink['id'], $drink['has_primary_image'])
                . '<span class="queue__body">'
                . '<a class="queue__name" href="/admin/drinks/' . $drink['id'] . '/test">'
                . $this->escape($drink['name']) . '</a>'
                . '<span class="queue__meta">'
                . ($drink['manufacturer'] === null ? '' : '<span>' . $this->escape($drink['manufacturer']) . '</span>')
                . ($drink['origin_region'] === null ? '' : '<span>' . $this->escape($drink['origin_region']) . '</span>')
                . $flags
                . '</span></span>'
                . '<span class="dt__actions">'
                . '<a class="btn btn--ghost btn--sm" href="/admin/drinks/' . $drink['id'] . '/edit">Bearbeiten</a>'
                . '<a class="btn btn--primary btn--sm" href="/admin/drinks/' . $drink['id'] . '/test">Testen</a>'
                . '</span></li>';
        }

        $list = $rows !== ''
            ? '<section class="panel panel--pad"><ul class="queue">' . $rows . '</ul></section>'
            : $this->emptyState(
                $filters->isFiltered() ? 'Nichts gefunden' : 'Nichts offen',
                $filters->isFiltered()
                    ? 'Andere Suchbegriffe oder Filter probieren.'
                    : 'Alle erworbenen Spezis sind getestet. Zeit für den nächsten Einkauf.',
                $filters->isFiltered()
                    ? '<a class="btn btn--secondary btn--sm" href="/admin/test">Filter zurücksetzen</a>'
                    : '<a class="btn btn--accent btn--sm" href="/admin/drinks/new">+ Spezi hinzufügen</a>',
            );

        $body = $this->head(
            'Spezistream',
            $counts['acquired'] === 0
                ? 'Keine erworbenen Spezis in der Warteschlange.'
                : '<strong>' . $counts['acquired'] . '</strong> '
                    . ($counts['acquired'] === 1 ? 'Spezi wartet' : 'Spezis warten') . ' auf ihre Noten.',
            '<a class="btn btn--ghost" href="/admin/drinks?lifecycle_status=acquired">In der Liste ansehen</a>',
        )
            . $this->error($error)
            . $this->filterBar($filters, $counts, count($drinks), false)
            . $list;

        return $this->document('Testen', $body, $counts, $csrfToken, 'test');
    }

    /** @param array{identified: int, acquired: int, tested: int} $counts */
    public function testResult(
        RatedDrink $drink,
        int $gesamtTotal,
        ?RatedDrink $rankAbove,
        ?RatedDrink $rankBelow,
        ?int $pricePosition,
        ?int $priceTotal,
        ?RatedDrink $priceAbove,
        ?RatedDrink $priceBelow,
        array $counts,
        string $csrfToken,
    ): string {
        $result = $drink->result;
        $subtitle = array_values(array_filter([$drink->manufacturer, $drink->displayOrigin()]));

        $hero = '<section class="panel panel--pad resulthero">'
            . $this->thumbnail($drink->id, $drink->hasImage)
            . '<div class="resulthero__text">'
            . '<span class="eyebrow eyebrow--accent">Test abgeschlossen</span>'
            . '<span class="testbar__name">' . $this->escape($drink->name) . '</span>'
            . ($subtitle === [] ? '' : '<span class="meta">' . $this->escape(implode(' · ', $subtitle)) . '</span>')
            . '</div>'
            . '<div class="resulthero__score"><span class="resulthero__num">'
            . ($result !== null ? $this->grade($result->gesamt()) : '–')
            . '<small>/ ' . self::GESAMT_MAX . '</small></span>'
            . '<span class="testbar__label">Gesamtwertung</span></div></section>';

        $body = $this->head(
            'Testergebnis',
            'Die Einzelnoten sind die erfassten Daten; die Platzierung bleibt verdeckt, bis ihr sie aufdeckt.',
            '<a class="btn btn--ghost btn--sm" href="/spezi/' . $drink->id . '">Öffentliche Seite</a>',
            $this->breadcrumb([
                ['/admin/drinks', 'Spezis'],
                ['/admin/drinks/' . $drink->id . '/edit', $drink->name],
                [null, 'Ergebnis'],
            ]),
        )
            . $hero
            . '<div class="grid grid--2" style="margin-top:var(--sp-5)">'
            . $this->resultDetail($drink)
            . $this->categoryBars($drink)
            . '</div>'
            . $this->placementPanel($drink, $gesamtTotal, $rankAbove, $rankBelow, $pricePosition, $priceTotal, $priceAbove, $priceBelow)
            . '<div class="form-actions" style="margin-top:var(--sp-5)">'
            . '<a class="btn btn--accent" href="/admin/test">Weiter zur nächsten Spezi</a>'
            . '<a class="btn btn--secondary" href="/admin/drinks/' . $drink->id . '/test">Ergebnis bearbeiten</a>'
            . '<a class="btn btn--ghost" href="/admin/drinks/' . $drink->id . '/edit">Stammdaten</a>'
            . '</div>';

        return $this->document('Testergebnis', $body, $counts, $csrfToken, 'test');
    }

    /**
     * Every Spezistream, newest first: how many Spezis it holds, whether its
     * video address is on file, and which evening is currently being recorded.
     *
     * @param list<TestRun> $runs
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function testRuns(
        array $runs,
        int $nextNumber,
        array $counts,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $open = null;

        foreach ($runs as $run) {
            if ($run->isOpen()) {
                $open = $run;

                break;
            }
        }

        $rows = '';

        foreach ($runs as $run) {
            $missingMarks = max(0, $run->testCount - $run->timedCount);
            $index = $run->streamUrl === null
                ? 'kein Video hinterlegt'
                : ($run->testCount === 0
                    ? 'Video hinterlegt'
                    : ($missingMarks === 0
                        ? 'Sprungmarken vollständig'
                        : $missingMarks . ' Spezi' . ($missingMarks === 1 ? '' : 's') . ' ohne Sprungmarke'));

            $rows .= '<tr><td><span class="run-num">' . $run->number . '</span></td>'
                . '<td>'
                . '<a class="dt__name" href="/admin/testabende/' . $run->number . '">'
                . '<strong>' . $this->escape($run->displayTitle()) . '</strong></a>'
                . '<span class="dt__sub">'
                . ($run->title === null ? '' : 'Spezistream #' . $run->number)
                . ($run->recordedOn === null
                    ? ''
                    : ($run->title === null ? '' : ' · ') . $this->escape($this->germanDate($run->recordedOn)))
                . '</span>'
                . '</td>'
                . '<td data-label="Verkostet">'
                . '<span><strong>' . $run->testCount . '</strong>&nbsp;Spezi' . ($run->testCount === 1 ? '' : 's') . '</span>'
                . '<span class="dt__sub">' . $index . '</span>'
                . '</td>'
                . '<td data-label="Status">' . $this->runBadge($run) . '</td>'
                . '<td class="table__actions" data-label="Aktionen"><div class="dt__actions">'
                . '<a class="btn btn--quiet" href="/admin/testabende/' . $run->number . '">Bericht</a>'
                . '</div></td></tr>';
        }

        $start = $open === null
            ? '<form method="post" action="/admin/testabende" class="cluster cluster--tight">'
                . $this->csrfField($csrfToken)
                . '<input type="hidden" name="number" value="' . $nextNumber . '">'
                . '<button class="btn btn--accent" type="submit">Spezistream #' . $nextNumber . ' starten</button></form>'
            : '<a class="btn btn--primary" href="/admin/testabende/' . $open->number . '">'
                . 'Spezistream #' . $open->number . ' öffnen</a>';

        $body = $this->head(
            'Spezistreams',
            $open === null
                ? 'Kein Spezistream läuft. Der nächste bekommt die Nummer <strong>' . $nextNumber . '</strong>.'
                : 'Spezistream <strong>#' . $open->number . '</strong> läuft. Abgeschlossene Tests werden '
                    . 'ihm zugeordnet.',
            $start,
        )
            . $this->error($error)
            . ($runs === []
                ? $this->emptyState(
                    'Noch kein Spezistream',
                    'Der erste Spezistream entsteht, sobald ihr einen startet.',
                )
                : '<section class="panel panel--flush"><div class="table-scroll">'
                    . '<table class="table table--drinks"><thead><tr>'
                    . '<th><span class="visually-hidden">Nummer</span></th>'
                    . '<th><span>Spezistream</span></th>'
                    . '<th><span>Verkostet</span></th>'
                    . '<th><span>Status</span></th><th class="table__actions">Aktionen</th>'
                    . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>');

        return $this->document('Spezistreams', $body, $counts, $csrfToken, 'runs');
    }

    /**
     * One Spezistream in full: its details form and the report of what was
     * tasted that evening, in the order the segments appear in the stream.
     *
     * @param list<array{drink_id: int, name: string, manufacturer: ?string, lifecycle_status: string, has_primary_image: bool, status: string, recorded_time: ?string, duration_value: ?int, notes: ?string, completed_at: ?string}> $tests
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    public function testRun(
        TestRun $run,
        array $tests,
        ?StreamEpisode $episode,
        array $counts,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $body = $this->head(
            $this->escape($run->displayTitle()),
            $run->isOpen()
                ? 'Dieser Spezistream läuft. Jeder abgeschlossene Test wird ihm automatisch zugeordnet.'
                : 'Abgeschlossener Spezistream.',
            $run->isOpen()
                ? '<form method="post" action="/admin/testabende/' . $run->number . '/complete" style="display:inline">'
                    . $this->csrfField($csrfToken)
                    . '<button class="btn btn--accent" type="submit">Spezistream abschließen</button></form>'
                    . '<a class="btn btn--ghost btn--sm" href="/admin/test">Zur Warteschlange</a>'
                : '<a class="btn btn--ghost btn--sm" href="/admin/testabende">Alle Spezistreams</a>',
            $this->breadcrumb([['/admin/testabende', 'Spezistreams'], [null, '#' . $run->number]]),
            $this->runBadge($run),
        )
            . $this->error($error)
            . '<div class="split split--sidebar"><div class="stack-lg">'
            . $this->runReport($run, $tests, $episode)
            . '</div><aside class="stack-lg">'
            . $this->runDetailsForm($run, $csrfToken)
            . '</aside></div>';

        return $this->document('Spezistream #' . $run->number, $body, $counts, $csrfToken, 'runs');
    }

    /** @param array{identified: int, acquired: int, tested: int} $counts */
    public function notFound(array $counts, string $csrfToken): string
    {
        $body = $this->head('Nicht gefunden', 'Dieser Eintrag existiert nicht (mehr).')
            . $this->emptyState(
                'Nichts gefunden',
                'Der Eintrag wurde gelöscht oder die Adresse stimmt nicht.',
                '<a class="btn btn--secondary btn--sm" href="/admin/drinks">Zur Spezi-Liste</a>'
                . '<a class="btn btn--ghost btn--sm" href="/admin">Zur Übersicht</a>',
            );

        return $this->document('Nicht gefunden', $body, $counts, $csrfToken, 'drinks');
    }

    // --- dashboard parts --------------------------------------------------

    private function statTile(string $status, int $count, int $total, string $caption): string
    {
        $share = $total === 0 ? 0.0 : $count / $total * 100;

        return '<a class="panel stat stat--' . $status . '" href="/admin/drinks?lifecycle_status=' . $status . '">'
            . $this->stateBadge($status)
            . '<span class="stat__num">' . $count . '</span>'
            . '<span class="stat__meter"><i style="width:' . $this->percent($share) . '%"></i></span>'
            . '<span class="stat__foot"><span>' . $this->escape($caption) . '</span>'
            . '<span>' . $this->percentLabel($share) . '</span></span></a>';
    }

    /** @param array{identified: int, acquired: int, tested: int} $counts */
    private function progressPanel(array $counts, int $total): string
    {
        if ($total === 0) {
            return '';
        }

        $bar = '';
        $legend = '';

        foreach (self::STATUS_LABELS as $status => $label) {
            $share = $counts[$status] / $total * 100;
            $bar .= '<i class="is-' . $status . '" style="width:' . $this->percent($share) . '%"></i>';
            $legend .= '<span><i class="is-' . $status . '"></i>' . $this->escape($label)
                . ' <b>' . $counts[$status] . '</b></span>';
        }

        return '<section class="panel panel--pad stack">'
            . '<div class="panel__head"><h2 class="panel__title">Katalogfortschritt</h2>'
            . '<span class="meta">' . $total . ' Spezis</span></div>'
            . '<div class="progress" role="img" aria-label="' . $counts['tested'] . ' von ' . $total . ' Spezis getestet">'
            . $bar . '</div>'
            . '<div class="progress-legend">' . $legend . '</div></section>';
    }

    /**
     * @param list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, has_primary_image: bool, needs_new_photo: bool}> $waiting
     */
    private function queuePanel(array $waiting, int $acquired): string
    {
        $rows = '';

        foreach (array_slice($waiting, 0, 6) as $drink) {
            $rows .= '<li class="queue__row">'
                . $this->thumbnail($drink['id'], $drink['has_primary_image'])
                . '<span class="queue__body"><a class="queue__name" href="/admin/drinks/' . $drink['id'] . '/test">'
                . $this->escape($drink['name']) . '</a>'
                . '<span class="queue__meta">'
                . ($drink['manufacturer'] === null ? '' : '<span>' . $this->escape($drink['manufacturer']) . '</span>')
                . ($drink['needs_new_photo'] ? '<span class="badge">Neues Foto nötig</span>' : '')
                . '</span></span>'
                . '<a class="btn btn--primary btn--sm" href="/admin/drinks/' . $drink['id'] . '/test">Testen</a></li>';
        }

        return '<section class="panel panel--pad"><div class="panel__head">'
            . '<h2 class="panel__title">Warten auf den Test</h2>'
            . ($acquired > 0 ? '<a class="link-arrow" href="/admin/test">Alle ' . $acquired . '</a>' : '')
            . '</div>'
            . ($rows === ''
                ? $this->emptyState(
                    'Nichts offen',
                    'Alle erworbenen Spezis sind getestet.',
                    '<a class="btn btn--secondary btn--sm" href="/admin/drinks/new">+ Spezi hinzufügen</a>',
                    true,
                )
                : '<ul class="queue">' . $rows . '</ul>')
            . '</section>';
    }

    /** @param array{no_image: int, no_price: int, needs_photo: int} $quality */
    private function qualityPanel(array $quality, int $total): string
    {
        $rows = [
            'no_image' => ['Ohne Bild', $quality['no_image']],
            'no_price' => ['Ohne Preis', $quality['no_price']],
            'needs_photo' => ['Neues Foto nötig', $quality['needs_photo']],
        ];
        $facts = '';

        foreach ($rows as $flag => [$label, $count]) {
            $facts .= '<div class="facts__row">'
                . ($count === 0
                    ? '<span class="facts__key">' . $this->escape($label) . '</span>'
                    : '<a href="/admin/drinks?filter=' . $flag . '">' . $this->escape($label) . '</a>')
                . '<span class="facts__val">' . $count . '</span></div>';
        }

        return '<section class="panel panel--pad stack">'
            . '<div class="panel__head"><h2 class="panel__title">Datenpflege</h2>'
            . '<span class="meta">von ' . $total . '</span></div>'
            . '<div class="facts">' . $facts . '</div></section>';
    }

    // --- list parts -------------------------------------------------------

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, has_primary_image: bool, needs_new_photo: bool} $drink
     */
    private function drinkRow(array $drink, string $csrfToken): string
    {
        $id = $drink['id'];
        // One quiet row action: dense tables read better when the button does
        // not shout. The Spezi name is still a link to the same edit form.
        $action = match ($drink['lifecycle_status']) {
            'tested' => '<a class="btn btn--quiet" href="/admin/drinks/' . $id . '/test/result">Ergebnis</a>',
            'acquired' => '<a class="btn btn--quiet" href="/admin/drinks/' . $id . '/test">Test</a>',
            default => '<a class="btn btn--quiet" href="/admin/drinks/' . $id . '/edit">Bearbeiten</a>',
        };

        $flags = '';

        if ($drink['needs_new_photo']) {
            $flags .= '<span class="badge">Neues Foto nötig</span>';
        }

        if (!$drink['has_primary_image']) {
            $flags .= '<span class="badge">Kein Bild</span>';
        }

        return '<tr><td>' . $this->thumbnail($id, $drink['has_primary_image']) . '</td>'
            . '<td data-label="Spezi">'
            . '<a class="dt__name" href="/admin/drinks/' . $id . '/edit"><strong>' . $this->escape($drink['name']) . '</strong></a>'
            . '<span class="dt__sub">' . $this->cellOrDash($drink['manufacturer']) . '</span>'
            . $this->notesCell($drink['notes'])
            . ($flags === '' ? '' : '<span class="dt__flags">' . $flags . '</span>')
            . '</td>'
            . '<td data-label="Herkunft"><span>' . $this->cellOrDash($drink['origin_location']) . '</span>'
            . ($drink['origin_region'] === null || trim($drink['origin_region']) === ''
                ? ''
                : '<span class="dt__sub">' . $this->escape($drink['origin_region']) . '</span>')
            . '</td>'
            . '<td class="table__num" data-label="Preis">' . $this->priceCell($drink['price_amount'], $drink['price_volume_ml']) . '</td>'
            // The Status column is the status control: one dropdown that both
            // shows the current state and changes it, instead of a read-only
            // badge here and a second copy of the same value under "Aktionen".
            . '<td data-label="Status">'
            . '<form method="post" action="/admin/drinks/' . $id . '/status" class="status-form">'
            . $this->csrfField($csrfToken)
            . $this->statusSelect(
                $drink['lifecycle_status'],
                'lifecycle_status',
                false,
                'Status von ' . $drink['name'] . ' ändern. Wird sofort gespeichert.',
            )
            . '<noscript><button class="btn btn--quiet" type="submit">Setzen</button></noscript></form>'
            . '</td>'
            . '<td class="table__actions" data-label="Aktionen"><div class="dt__actions">'
            . $action . '</div></td></tr>';
    }

    /**
     * A clickable column heading. One click sorts ascending, the next reverses
     * it, and the third returns the list to its default order — the same three
     * steps a spreadsheet gives you. `aria-sort` carries that state for screen
     * readers, and the link keeps every active search and filter intact.
     *
     * The label may contain markup (a non-breaking space), so it is passed in
     * already escaped and never carries user input.
     */
    private function sortableHeader(
        string $label,
        string $column,
        DrinkListFilters $filters,
        string $attributes = '',
    ): string {
        [$ascending, $descending] = self::SORTABLE_COLUMNS[$column];

        if ($filters->sort === $ascending) {
            $state = 'ascending';
            $next = $descending;
            $hint = 'absteigend sortieren';
        } elseif ($filters->sort === $descending) {
            $state = 'descending';
            $next = self::DEFAULT_SORT;
            $hint = 'Sortierung zurücksetzen';
        } else {
            $state = 'none';
            $next = $ascending;
            $hint = 'aufsteigend sortieren';
        }

        return '<th' . $attributes . ' aria-sort="' . $state . '">'
            . '<a class="th-sort" href="' . $this->escape($filters->withSort($next)->url()) . '">'
            . '<span>' . $label . '</span>'
            . '<span class="visually-hidden">' . $this->escape($hint) . '</span></a></th>';
    }

    /**
     * The shared filter bar of both list pages: search, status chips, the
     * data-quality shortcuts and the sort order, plus a live result summary.
     *
     * @param array{identified: int, acquired: int, tested: int} $counts
     */
    private function filterBar(DrinkListFilters $filters, array $counts, int $shown, bool $withStatus = true): string
    {
        $hidden = '';

        foreach ($filters->hiddenFields('q') as $name => $value) {
            $hidden .= '<input type="hidden" name="' . $this->escape($name) . '" value="' . $this->escape($value) . '">';
        }

        $sortHidden = '';

        foreach ($filters->hiddenFields('sort') as $name => $value) {
            $sortHidden .= '<input type="hidden" name="' . $this->escape($name) . '" value="' . $this->escape($value) . '">';
        }

        $options = '';

        foreach (self::SORT_LABELS as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($filters->sort === $value ? ' selected' : '') . '>'
                . $this->escape($label) . '</option>';
        }

        $statusRow = '';

        if ($withStatus) {
            $chips = '<a class="chip chip--all' . ($filters->status === null ? ' chip--active' : '') . '" href="'
                . $this->escape($filters->withStatus(null)->url()) . '">Alle '
                . '<span class="chip__count">' . array_sum($counts) . '</span></a>';

            foreach (self::STATUS_LABELS as $status => $label) {
                $chips .= '<a class="chip' . ($filters->status === $status ? ' chip--active' : '') . '" href="'
                    . $this->escape($filters->toggledStatus($status)->url()) . '">' . $this->escape($label)
                    . ' <span class="chip__count">' . $counts[$status] . '</span></a>';
            }

            $statusRow = '<div class="filterbar__row"><span class="filterbar__legend">Status</span>' . $chips . '</div>';
        }

        $flagChips = '';

        foreach (self::FLAG_LABELS as $flag => $label) {
            $flagChips .= '<a class="chip' . ($filters->flag === $flag ? ' chip--active' : '') . '" href="'
                . $this->escape($filters->toggledFlag($flag)->url()) . '">' . $this->escape($label) . '</a>';
        }

        $summary = '<span><span class="filterbar__count">' . $shown . '</span> '
            . ($shown === 1 ? 'Eintrag' : 'Einträge')
            . ($shown === 500 ? ' (Anzeigegrenze erreicht, bitte suchen)' : '') . '</span>';

        if ($filters->search !== '') {
            $summary .= '<span>Suche: „' . $this->escape($filters->search) . '“</span>';
        }

        if ($filters->flag !== '') {
            $summary .= '<span>Filter: ' . $this->escape(self::FLAG_LABELS[$filters->flag] ?? $filters->flag) . '</span>';
        }

        if ($filters->isFiltered() || $filters->isSorted()) {
            $summary .= '<a class="chip chip--reset" href="' . $this->escape($filters->path) . '">Alles zurücksetzen</a>';
        }

        return '<section class="panel panel--pad filterbar" style="margin-bottom:var(--sp-5)">'
            . '<div class="filterbar__top">'
            . '<form class="filterbar__search search-wrap" method="get" action="' . $this->escape($filters->path) . '" '
            . 'role="search" data-suggest data-suggest-href="/admin/drinks/{id}/edit">'
            . $hidden
            . '<div class="search"><label class="visually-hidden" for="admin-q">Suchen</label>'
            . '<input id="admin-q" name="q" type="search" placeholder="Name oder Hersteller …" '
            . 'autocomplete="off" role="combobox" aria-expanded="false" aria-controls="admin-q-suggest" '
            . 'aria-autocomplete="list" value="' . $this->escape($filters->search) . '">'
            . '<button type="submit">Suchen</button></div>'
            . '<ul class="suggest" id="admin-q-suggest" role="listbox" aria-label="Vorschläge" hidden></ul></form>'
            . '<form class="filterbar__sort" method="get" action="' . $this->escape($filters->path) . '">'
            . $sortHidden
            . '<label class="label" for="sort">Sortierung</label>'
            // Submitting happens in spezitest.js: the Content-Security-Policy
            // has no 'unsafe-inline', so an inline onchange would never run.
            . '<select class="select select--sm" id="sort" name="sort" data-autosubmit>' . $options . '</select>'
            . '<noscript><button class="btn btn--secondary btn--sm" type="submit">Sortieren</button></noscript>'
            . '</form></div>'
            . $statusRow
            . '<div class="filterbar__row"><span class="filterbar__legend">Datenpflege</span>' . $flagChips . '</div>'
            . '<div class="filterbar__summary">' . $summary . '</div>'
            . '</section>';
    }

    // --- form parts -------------------------------------------------------

    private function basicsPanel(
        string $prefix,
        string $name,
        string $manufacturer,
        string $status,
        bool $includeTested,
        bool $autofocus,
    ): string {
        return '<section class="panel panel--pad"><div class="panel__head">'
            . '<h2 class="panel__title">Stammdaten</h2><span class="meta">Pflicht</span></div>'
            . '<div class="form-row">'
            . '<div class="field"><label class="label" for="' . $prefix . 'name">Name <span class="req">*</span></label>'
            . '<input class="input" id="' . $prefix . 'name" name="name" maxlength="255" required'
            . ($autofocus ? ' autofocus' : '')
            . ' placeholder="z.B. Talbach Cola-Mix" style="font-size:var(--fs-body-lg)"'
            . ' value="' . $this->escape($name) . '"></div>'
            . '<div class="field"><span class="label">Status <span class="req">*</span></span>'
            . $this->statusSegmented($status, $includeTested)
            . ($includeTested
                ? '<span class="hint">„Getestet“ wird nur über die Testerfassung gesetzt.</span>'
                : '<span class="hint">„Erworben“ heißt: steht hier und wartet auf den Spezistream.</span>')
            . '</div>'
            . '<div class="field"><label class="label" for="' . $prefix . 'man">Hersteller '
            . '<span class="label__opt">optional</span></label>'
            . '<input class="input" id="' . $prefix . 'man" name="manufacturer" maxlength="255" '
            . 'placeholder="z.B. Talbach Brauerei" value="' . $this->escape($manufacturer) . '"></div>'
            . '</div></section>';
    }

    private function originPanel(string $prefix, string $location, string $region): string
    {
        return '<section class="panel panel--pad"><div class="panel__head">'
            . '<h2 class="panel__title">Herkunft</h2><span class="meta">optional</span></div>'
            . '<div class="form-row form-row--2">'
            . '<div class="field"><label class="label" for="' . $prefix . 'loc">Ort</label>'
            . '<input class="input" id="' . $prefix . 'loc" name="origin_location" maxlength="255" '
            . 'placeholder="z.B. 83022 Rosenheim" value="' . $this->escape($location) . '">'
            . '<span class="hint">Mit PLZ voranstellen, damit die Herkunftskarte den Ort platzieren kann.</span></div>'
            . '<div class="field"><label class="label" for="' . $prefix . 'reg">Region / Land</label>'
            . '<input class="input" id="' . $prefix . 'reg" name="origin_region" maxlength="128" '
            . 'placeholder="z.B. Bayern" value="' . $this->escape($region) . '">'
            . '<span class="hint">Gruppiert die Spezi auf der öffentlichen Karte.</span></div>'
            . '</div></section>';
    }

    private function pricePanel(string $prefix, string $price, string $volume): string
    {
        return '<section class="panel panel--pad stack" data-price-form><div class="panel__head">'
            . '<h2 class="panel__title">Preis</h2><span class="meta">optional</span></div>'
            . '<div class="form-row form-row--2">'
            . '<div class="field"><label class="label" for="' . $prefix . 'price">Preis</label>'
            . '<div class="affix"><input class="input" id="' . $prefix . 'price" name="price" inputmode="decimal" '
            . 'placeholder="0,89" value="' . $this->escape($price) . '"><span class="affix__unit">€</span></div>'
            . '<span class="hint">Komma oder Punkt, beides geht.</span></div>'
            . '<div class="field"><label class="label" for="' . $prefix . 'vol">Menge</label>'
            . '<div class="affix"><input class="input" id="' . $prefix . 'vol" name="price_volume_ml" '
            . 'inputmode="numeric" placeholder="500" value="' . $this->escape($volume) . '">'
            . '<span class="affix__unit">ml</span></div>'
            . '<span class="presets">'
            . '<button class="preset" type="button" data-fill="' . $prefix . 'vol" data-fill-value="330">330 ml</button>'
            . '<button class="preset" type="button" data-fill="' . $prefix . 'vol" data-fill-value="500">500 ml</button>'
            . '<button class="preset" type="button" data-fill="' . $prefix . 'vol" data-fill-value="1000">1 l</button>'
            . '</span></div>'
            . '</div>'
            . '<div class="readout"><span class="readout__label">Grundlage für Preis / Leistung</span>'
            . '<span class="readout__value"><span data-price-preview>–</span> je 0,5 l</span></div>'
            . '</section>';
    }

    private function notesPanel(string $prefix, string $notes): string
    {
        return '<section class="panel panel--pad"><div class="panel__head">'
            . '<h2 class="panel__title">Notizen</h2><span class="meta">optional</span></div>'
            . '<div class="field"><label class="visually-hidden" for="' . $prefix . 'notes">Notizen</label>'
            . '<textarea class="textarea" id="' . $prefix . 'notes" name="notes" '
            . 'placeholder="z.B. Gefunden im Getränkemarkt, auffällige Flasche …">' . $this->escape($notes) . '</textarea>'
            . '<span class="hint">Interne Notiz zur Beschaffung. Nicht die Testnotiz.</span></div>'
            . '</section>';
    }

    private function imagePanel(int $id, bool $hasImage, bool $needsNewPhoto): string
    {
        $figure = $hasImage
            ? '<figure class="pimg pimg--square"><img src="/admin/drinks/' . $id . '/image" alt="Primärbild"></figure>'
            : '<figure class="pimg pimg--square"><div class="pimg__ph"><span>Kein Bild</span></div></figure>';

        $remove = $hasImage
            ? '<label class="check"><input type="checkbox" name="remove_image" value="1">'
                . '<span>Bild beim Speichern entfernen</span></label>'
            : '';

        $hint = $needsNewPhoto
            ? '<p class="notice"><span><strong>Neues Foto nötig.</strong> '
                . 'Die Markierung verschwindet automatisch, sobald ein neues Bild hochgeladen wird.</span></p>'
            : '<p class="hint">JPEG, PNG oder WebP. Ein Bild pro Spezi; ein neues ersetzt das alte.</p>';

        return '<section class="panel panel--pad aside-card">'
            . '<div class="panel__head"><h2 class="panel__title">Bild</h2>'
            . '<span class="meta">' . ($hasImage ? 'vorhanden' : 'fehlt') . '</span></div>'
            . $figure
            . $remove
            . $this->pictureField('pic', $hasImage ? 'Bild ersetzen' : 'Bild hinzufügen', false)
            . $hint
            . '</section>';
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, needs_new_photo: bool} $drink
     */
    private function factsPanel(array $drink, bool $hasImage): string
    {
        $price = $drink['price_amount'] !== null && $drink['price_volume_ml'] !== null
            ? $this->priceForDisplay($drink['price_amount']) . ' € / ' . $drink['price_volume_ml'] . ' ml'
            : '–';

        $rows = [
            'Datensatz' => '#' . $drink['id'],
            'Status' => $this->statusLabel($drink['lifecycle_status']),
            'Preis' => $price,
            'Bild' => $hasImage ? 'vorhanden' : 'fehlt',
        ];
        $facts = '';

        foreach ($rows as $key => $value) {
            $facts .= '<div class="facts__row"><span class="facts__key">' . $this->escape($key) . '</span>'
                . '<span class="facts__val">' . $this->escape($value) . '</span></div>';
        }

        return '<section class="panel panel--pad stack">'
            . '<div class="panel__head"><h2 class="panel__title">Eckdaten</h2></div>'
            . '<div class="facts">' . $facts . '</div></section>';
    }

    private function quickAddForm(string $csrfToken): string
    {
        return '<form class="stack" method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<div class="field"><label class="label" for="qn">Name <span class="req">*</span></label>'
            . '<input class="input" id="qn" name="name" maxlength="255" required placeholder="z.B. Talbach Cola-Mix"></div>'
            . '<div class="field"><span class="label">Status <span class="req">*</span></span>'
            . $this->statusSegmented('identified', false) . '</div>'
            . $this->pictureField('qp', 'Bild')
            . '<button class="btn btn--accent btn--block" type="submit">Spezi hinzufügen</button>'
            . '<p class="hint">Für unterwegs: Rest lässt sich später ergänzen. '
            . '<a href="/admin/drinks/new">Ausführliches Formular</a></p></form>';
    }

    // --- test parts -------------------------------------------------------

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string, price_amount: ?string, price_volume_ml: ?int, needs_new_photo: bool} $drink
     */
    private function testBar(array $drink, TestFormData $data, bool $hasImage, int $filled): string
    {
        $id = $drink['id'];
        $priceLine = $drink['price_amount'] !== null && $drink['price_volume_ml'] !== null
            ? $this->priceForDisplay($drink['price_amount']) . ' € / ' . $drink['price_volume_ml'] . ' ml'
            : 'kein Preis erfasst';

        $steps = '';
        $total = count(self::TESTERS) * count(self::CATEGORIES);

        for ($index = 0; $index < $total; ++$index) {
            $steps .= '<i' . ($index < $filled ? ' class="is-set"' : '') . '></i>';
        }

        return '<div class="panel testbar">'
            . $this->thumbnail($id, $hasImage)
            . '<div class="testbar__body">'
            . '<a class="testbar__name" href="/admin/drinks/' . $id . '/edit">' . $this->escape($drink['name']) . '</a>'
            . '<span class="testbar__meta"><span>' . $this->escape($priceLine) . '</span>'
            . '<a href="/admin/drinks/' . $id . '/edit">Preis bearbeiten</a></span>'
            . '<span class="testbar__steps" data-progress-steps>' . $steps . '</span>'
            . '</div>'
            . '<div class="testbar__score">'
            . '<span class="testbar__num"><span data-gesamt-preview>'
            . ($data->result !== null ? $this->grade($data->result->gesamt()) : '–')
            . '</span><small>/ ' . self::GESAMT_MAX . '</small></span>'
            . '<span class="testbar__label">Gesamtwertung</span>'
            . '<span class="meta"><span data-progress-count>' . $filled . '</span> von ' . $total . ' Noten</span>'
            . '</div></div>';
    }

    /** The mean of the three tester grades, or an en dash while one is missing. */
    private function categoryAverage(TestFormData $data, string $category): string
    {
        $sum = 0.0;

        foreach (self::TESTERS as $code => $_label) {
            $grade = $data->grade($code, $category);

            if ($grade === '') {
                return '–';
            }

            $sum += (float) $grade;
        }

        return $this->grade($sum / count(self::TESTERS));
    }

    /**
     * Which Spezistream this test belongs to and where its segment sits in the
     * recording. Everything here is optional and can be filled in later, once
     * the video has been cut.
     *
     * @param list<TestRun> $runs
     */
    private function streamPanel(TestFormData $data, array $runs): string
    {
        $options = '<option value="">– keinem Spezistream zugeordnet –</option>';

        foreach ($runs as $run) {
            $options .= '<option value="' . $run->number . '"'
                . ($data->streamReference === $run->number ? ' selected' : '') . '>'
                . '#' . $run->number . ' ' . $this->escape($run->displayTitle())
                . ($run->isOpen() ? ' (läuft)' : '') . '</option>';
        }

        return '<section class="panel panel--pad"><div class="panel__head">'
            . '<h2 class="panel__title">Stream</h2><span class="meta">optional</span></div>'
            . '<div class="form-row form-row--2">'
            . '<div class="field form-row--wide"><label class="label" for="sr">Spezistream</label>'
            . '<select class="select" id="sr" name="stream_reference">' . $options . '</select>'
            . '<span class="hint">Ohne Auswahl übernimmt der Abschluss den laufenden Spezistream.</span></div>'
            . '<div class="field"><label class="label" for="rt">Zeitstempel im Stream</label>'
            . '<input class="input" id="rt" name="recorded_time" inputmode="numeric" placeholder="1:23:45" '
            . 'value="' . $this->escape(TestStreamPosition::formatOffset($data->recordedTime) ?? '') . '">'
            . '<span class="hint">h:mm:ss, mm:ss oder Sekunden. Sprungziel für den Video-Link.</span></div>'
            . '<div class="field"><label class="label" for="dv">Dauer</label>'
            . '<div class="affix"><input class="input" id="dv" name="duration_value" inputmode="numeric" '
            . 'placeholder="320" value="' . ($data->durationValue !== null ? (string) $data->durationValue : '') . '">'
            . '<span class="affix__unit">Sek.</span></div>'
            . '<span class="hint">Wie lang das Segment im Stream läuft.</span></div>'
            . '</div></section>';
    }

    private function runBadge(TestRun $run): string
    {
        return $run->isOpen()
            ? '<span class="state state--acquired">Läuft</span>'
            : '<span class="state state--tested">Abgeschlossen</span>';
    }

    private function runDetailsForm(TestRun $run, string $csrfToken): string
    {
        return '<section class="panel panel--pad stack">'
            . '<div class="panel__head"><h2 class="panel__title">Angaben</h2></div>'
            . '<form method="post" action="/admin/testabende/' . $run->number . '" class="stack">'
            . $this->csrfField($csrfToken)
            . '<div class="field"><label class="label" for="rt-title">Titel</label>'
            . '<input class="input" id="rt-title" name="title" maxlength="190" '
            . 'placeholder="Spezi mit den Spezis #' . $run->number . '" '
            . 'value="' . $this->escape($run->title ?? '') . '"></div>'
            . '<div class="field"><label class="label" for="rt-date">Aufnahmedatum</label>'
            . '<input class="input" id="rt-date" name="recorded_on" type="date" '
            . 'value="' . $this->escape($run->recordedOn ?? '') . '"></div>'
            . '<div class="field"><label class="label" for="rt-url">Stream-Adresse</label>'
            . '<input class="input" id="rt-url" name="stream_url" type="url" maxlength="500" '
            . 'placeholder="https://www.youtube.com/watch?v=…" '
            . 'value="' . $this->escape($run->streamUrl ?? '') . '">'
            . '<span class="hint">Nur http(s). Mit dieser Adresse springt jeder Zeitstempel direkt ins Video.</span></div>'
            . '<div class="field"><label class="label" for="rt-notes">Notiz</label>'
            . '<textarea class="textarea" id="rt-notes" name="notes">' . $this->escape($run->notes ?? '') . '</textarea></div>'
            . '<button class="btn btn--primary btn--block" type="submit">Angaben speichern</button>'
            . '</form></section>';
    }

    /**
     * What happened that evening: the Spezis in stream order with their
     * segment position, plus the totals that make the evening comparable.
     *
     * @param list<array{drink_id: int, name: string, manufacturer: ?string, lifecycle_status: string, has_primary_image: bool, status: string, recorded_time: ?string, duration_value: ?int, notes: ?string, completed_at: ?string}> $tests
     */
    private function runReport(TestRun $run, array $tests, ?StreamEpisode $episode = null): string
    {
        if ($tests === []) {
            return '<section class="panel panel--pad">'
                . '<div class="panel__head"><h2 class="panel__title">Bericht</h2></div>'
                . $this->emptyState(
                    'Noch nichts getestet',
                    $run->isOpen()
                        ? 'Sobald ein Test abgeschlossen wird, erscheint er hier.'
                        : 'Diesem Spezistream ist kein Test zugeordnet.',
                    $run->isOpen() ? '<a class="btn btn--accent btn--sm" href="/admin/test">Zur Warteschlange</a>' : '',
                    true,
                ) . '</section>';
        }

        $completed = 0;
        $timed = 0;
        $segmentSeconds = 0;
        $rows = '';

        foreach ($tests as $test) {
            if ($test['status'] === 'completed') {
                ++$completed;
            }

            if ($test['recorded_time'] !== null) {
                ++$timed;
            }

            $segmentSeconds += $test['duration_value'] ?? 0;
            $offset = TestStreamPosition::formatOffset($test['recorded_time']);
            $watch = $run->watchUrl(TestStreamPosition::offsetToSeconds($test['recorded_time']));

            $rows .= '<tr><td>' . $this->thumbnail($test['drink_id'], $test['has_primary_image']) . '</td>'
                . '<td data-label="Spezi">'
                . '<a class="dt__name" href="/admin/drinks/' . $test['drink_id'] . '/edit">'
                . '<strong>' . $this->escape($test['name']) . '</strong></a>'
                . '<span class="dt__sub">' . $this->cellOrDash($test['manufacturer']) . '</span>'
                . ($test['status'] === 'completed' ? '' : '<span class="dt__flags"><span class="badge">Entwurf</span></span>')
                . '</td>'
                . '<td data-label="Zeitstempel">' . ($offset === null
                    ? '<span class="meta">–</span>'
                    : '<span style="white-space:nowrap">' . $this->escape($offset) . '</span>') . '</td>'
                . '<td data-label="Dauer">' . ($test['duration_value'] === null
                    ? '<span class="meta">–</span>'
                    : $this->escape(TestStreamPosition::formatDuration($test['duration_value']) ?? '–')) . '</td>'
                . '<td class="table__actions" data-label="Aktionen"><div class="dt__actions">'
                . ($watch === null
                    ? ''
                    : '<a class="btn btn--quiet" href="' . $this->escape($watch) . '" target="_blank" rel="noopener">Im Stream</a>')
                . '<a class="btn btn--quiet" href="/admin/drinks/' . $test['drink_id'] . '/test">Test</a>'
                . '</div></td></tr>';
        }

        $facts = '<div class="facts">'
            . $this->factRow('Spezis getestet', (string) $completed . ' von ' . count($tests))
            . $this->factRow('Mit Zeitstempel', $timed . ' von ' . count($tests))
            . $this->factRow(
                'Segmentzeit gesamt',
                $segmentSeconds === 0 ? '–' : (TestStreamPosition::formatDuration($segmentSeconds) ?? '–'),
            )
            . ($episode?->averageSeconds === null
                ? ''
                : $this->factRow('Ø je Spezi', StreamEpisode::minutes($episode->averageSeconds) ?? '–'))
            . ($episode?->averageGesamt === null
                ? ''
                : $this->factRow('Ø Gesamtwertung', $this->grade($episode->averageGesamt) . ' / ' . self::GESAMT_MAX))
            . ($episode?->best === null
                ? ''
                : $this->factRow('Bester des Abends', $episode->best->name))
            . ($episode === null || $episode->worst === null || $episode->best === $episode->worst
                ? ''
                : $this->factRow('Schlusslicht', $episode->worst->name))
            . ($episode?->longest === null
                ? ''
                : $this->factRow(
                    'Längste Verkostung',
                    $episode->longest->name . ' · '
                        . (StreamEpisode::minutes($episode->longest->stream->durationSeconds ?? null) ?? ''),
                ))
            . ($episode === null || $episode->shortest === null || $episode->longest === $episode->shortest
                ? ''
                : $this->factRow(
                    'Kürzeste Verkostung',
                    $episode->shortest->name . ' · '
                        . (StreamEpisode::minutes($episode->shortest->stream->durationSeconds ?? null) ?? ''),
                ))
            . $this->factRow(
                'Stream',
                $run->streamUrl === null ? 'keine Adresse hinterlegt' : 'hinterlegt',
            )
            . '</div>';

        return '<section class="panel panel--pad stack-lg">'
            . '<div class="panel__head"><h2 class="panel__title">Bericht</h2>'
            . '<span class="meta">' . count($tests) . ' ' . (count($tests) === 1 ? 'Spezi' : 'Spezis') . '</span></div>'
            . $facts
            . '<div class="table-scroll"><table class="table table--drinks"><thead><tr>'
            . '<th><span class="visually-hidden">Bild</span></th><th><span>Spezi</span></th>'
            . '<th><span>Zeitstempel</span></th><th><span>Dauer</span></th>'
            . '<th class="table__actions">Aktionen</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '</section>';
    }

    private function factRow(string $key, string $value): string
    {
        return '<div class="facts__row"><span class="facts__key">' . $this->escape($key) . '</span>'
            . '<span class="facts__val">' . $this->escape($value) . '</span></div>';
    }

    /** `06.09.2026` from an ISO date, or the raw value when it is not one. */
    private function germanDate(string $isoDate): string
    {
        $parts = explode('-', $isoDate);

        return count($parts) === 3 ? $parts[2] . '.' . $parts[1] . '.' . $parts[0] : $isoDate;
    }

    private function gradeCount(TestFormData $data): int
    {
        $filled = 0;

        foreach (self::TESTERS as $code => $_label) {
            foreach (self::CATEGORIES as $category => $_categoryLabel) {
                if ($data->grade($code, $category) !== '') {
                    ++$filled;
                }
            }
        }

        return $filled;
    }

    /**
     * The concrete test result: every tester's nine raw grades plus the
     * category averages from the verified engine. Not a spoiler — this is the
     * recorded data, not the competition placement.
     */
    private function resultDetail(RatedDrink $drink): string
    {
        $result = $drink->result;
        $head = '<div class="panel__head"><h2 class="panel__title">Einzelnoten</h2>'
            . '<a class="btn btn--secondary btn--sm" href="/admin/drinks/' . $drink->id . '/test">Ergebnis bearbeiten</a></div>';

        if ($result === null || !$this->hasAllTesterGrades($drink)) {
            return '<section class="panel panel--pad stack">' . $head
                . '<p class="meta">Die Einzelnoten dieses Tests sind unvollständig. Über „Ergebnis '
                . 'bearbeiten“ ergänzen.</p></section>';
        }

        $bodyRows = '';

        foreach (self::TESTERS as $code => $name) {
            $grades = $drink->testerGrades[$code];
            $bodyRows .= '<tr><td>' . $this->escape($name) . '</td>'
                . '<td>' . $this->escape($this->gradeInteger($grades['optik'])) . '</td>'
                . '<td>' . $this->escape($this->gradeInteger($grades['sueffigkeit'])) . '</td>'
                . '<td>' . $this->escape($this->gradeInteger($grades['geschmack'])) . '</td></tr>';
        }

        $footRow = '<tr><td>Ø</td><td>' . $this->grade($result->optikAverage()) . '</td>'
            . '<td>' . $this->grade($result->sueffigkeitAverage()) . '</td>'
            . '<td>' . $this->grade($result->geschmackAverage()) . '</td></tr>';

        $note = $drink->testNotes !== null && trim($drink->testNotes) !== ''
            ? '<div class="stack-sm"><span class="eyebrow">Testnotiz</span>'
                . '<p style="white-space:pre-wrap">' . $this->escape($drink->testNotes) . '</p></div>'
            : '';

        return '<section class="panel panel--pad stack">' . $head
            . '<div class="table-scroll"><table class="table table--compact table--matrix result-matrix">'
            . '<thead><tr><th>Tester</th><th>Optik</th><th>Süffigkeit</th><th>Geschmack</th></tr></thead>'
            . '<tbody>' . $bodyRows . '</tbody>'
            . '<tfoot>' . $footRow . '</tfoot></table></div>'
            . $note . '</section>';
    }

    /** The same category bars the public detail page uses, from the same engine. */
    private function categoryBars(RatedDrink $drink): string
    {
        $result = $drink->result;

        if ($result === null) {
            return '';
        }

        $averages = [
            'optik' => $result->optikAverage(),
            'sueffigkeit' => $result->sueffigkeitAverage(),
            'geschmack' => $result->geschmackAverage(),
        ];
        $bars = '';

        foreach (self::CATEGORIES as $category => $label) {
            $value = $averages[$category];
            $bars .= '<div class="rating"><span class="rating__label">' . $this->escape($label)
                . ' <span class="gradegroup__weight">×' . self::WEIGHTS[$category] . '</span></span>'
                . '<span class="rating__val">' . $this->grade($value) . ' / 10</span>'
                . '<span class="rating__bar"><i style="width:'
                . $this->percent($value / self::GRADE_MAX * 100) . '%"></i></span></div>';
        }

        $gesamt = $result->gesamt();
        $bars .= '<div class="rating rating--total"><span class="rating__label">Gesamtwertung</span>'
            . '<span class="rating__val">' . $this->grade($gesamt) . ' / ' . self::GESAMT_MAX . '</span>'
            . '<span class="rating__bar"><i style="width:'
            . $this->percent($gesamt / self::GESAMT_MAX * 100) . '%"></i></span></div>';

        return '<section class="panel panel--pad stack">'
            . '<div class="panel__head"><h2 class="panel__title">Kategorien</h2>'
            . '<span class="meta">Ø aller drei Tester</span></div>'
            . '<div class="ratings">' . $bars . '</div>'
            . '<p class="hint">Gesamtwertung = Optik ×1 + Süffigkeit ×2 + Geschmack ×3.</p></section>';
    }

    private function placementPanel(
        RatedDrink $drink,
        int $gesamtTotal,
        ?RatedDrink $rankAbove,
        ?RatedDrink $rankBelow,
        ?int $pricePosition,
        ?int $priceTotal,
        ?RatedDrink $priceAbove,
        ?RatedDrink $priceBelow,
    ): string {
        $rank = '<div class="spoiler-card"><span class="eyebrow">Platz im Ranking</span>'
            . '<span class="spoiler-card__num spoiler">Platz ' . $drink->rank . ' von ' . $gesamtTotal . '</span>'
            . $this->neighborRow($rankAbove, $rankBelow) . '</div>';

        $price = $drink->pricePerformance === null || $pricePosition === null
            ? '<div class="spoiler-card"><span class="eyebrow">Preis / Leistung</span>'
                . '<p class="meta">Kein Preis erfasst. '
                . '<a href="/admin/drinks/' . $drink->id . '/edit">Jetzt nachtragen</a>.</p></div>'
            : '<div class="spoiler-card"><span class="eyebrow">Preis / Leistung</span>'
                . '<span class="spoiler-card__num spoiler">Platz ' . $pricePosition . ' von ' . $priceTotal . '</span>'
                . $this->neighborRow($priceAbove, $priceBelow) . '</div>';

        return '<section class="panel panel--pad stack" data-reveal-scope style="margin-top:var(--sp-5)">'
            . '<div class="panel__head"><h2 class="panel__title">Platzierung</h2>'
            . '<button type="button" class="btn btn--secondary btn--sm" data-reveal-trigger>Aufdecken</button></div>'
            . '<p class="hint">Bleibt unscharf, bis ihr geraten habt.</p>'
            . '<div class="grid grid--2">' . $rank . $price . '</div></section>';
    }

    private function neighborRow(?RatedDrink $above, ?RatedDrink $below): string
    {
        $cell = fn (?RatedDrink $drink, string $label): string => $drink === null
            ? ''
            : '<div class="spoiler"><span class="eyebrow">' . $label . '</span>'
                . '<strong>' . $this->escape($drink->name) . '</strong></div>';

        if ($above === null && $below === null) {
            return '';
        }

        return '<div class="spoiler-neighbours">'
            . $cell($above, 'Platz davor') . $cell($below, 'Platz danach') . '</div>';
    }

    private function hasAllTesterGrades(RatedDrink $drink): bool
    {
        foreach (self::TESTERS as $code => $_label) {
            $grades = $drink->testerGrades[$code] ?? null;

            if (
                $grades === null
                || $grades['optik'] === ''
                || $grades['sueffigkeit'] === ''
                || $grades['geschmack'] === ''
            ) {
                return false;
            }
        }

        return true;
    }

    private function gradeInteger(string $grade): string
    {
        return (string) (int) round((float) $grade);
    }

    // --- shell -----------------------------------------------------------

    /** @param array{identified: int, acquired: int, tested: int}|null $counts */
    private function document(
        string $title,
        string $content,
        ?array $counts,
        string $csrfToken = '',
        string $active = '',
    ): string {
        $shell = '<div class="admin"><div class="admin-top">'
            . '<a class="admin-top__brand" href="/admin">'
            . '<img src="/assets/spezitest-logo-white.svg" alt="Spezitest" width="120" height="28">'
            . '<span class="admin-top__tag">Verwaltung</span></a>'
            . '<div class="cluster cluster--tight">'
            . ($counts !== null ? '<a class="admin-top__link" href="/">Website ansehen</a>' : '')
            . ($counts !== null ? $this->logoutButton($csrfToken) : '')
            . '</div></div>';

        // `.admin-main` is width-capped in the stylesheet but never got an auto
        // margin, so past ~1616px it pins left and its content drifts
        // off-centre. Authenticated pages keep a full-width main (so tables and
        // grids still stretch) but centre the capped box in its track; the
        // login screen drops the cap entirely and lets `.auth` centre the card.
        $shell .= $counts !== null
            ? '<div class="admin-body">' . $this->sidebar($active, $counts)
                . '<main class="admin-main" id="main" style="width:100%;margin-inline:auto">' . $content . '</main></div>'
            : '<main class="admin-main" id="main" style="padding:0;max-width:none">' . $content . '</main>';

        $shell .= '</div>';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->escape($title) . ' · Spezitest Verwaltung</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<link rel="stylesheet" href="/assets/spezitest.css?v=p44">'
            . '<link rel="icon" href="/assets/spezitest-icon.svg" type="image/svg+xml">'
            . '</head><body><a class="skip-link" href="#main">Zum Inhalt springen</a>' . $shell
            . '<script src="/assets/spezitest.js?v=p44" defer></script>'
            . '</body></html>';
    }

    /** @param array{identified: int, acquired: int, tested: int} $counts */
    private function sidebar(string $active, array $counts): string
    {
        $total = array_sum($counts);
        $groups = [
            'Katalog' => [
                'dashboard' => ['/admin', 'Übersicht', null],
                'drinks' => ['/admin/drinks', 'Spezis', $total],
                'create' => ['/admin/drinks/new', 'Spezi hinzufügen', null],
            ],
            'Spezistream' => [
                'test' => ['/admin/test', 'Warteschlange', $counts['acquired']],
                'runs' => ['/admin/testabende', 'Spezistreams', null],
            ],
        ];
        $links = '';

        foreach ($groups as $group => $items) {
            $links .= '<span class="admin-side__group">' . $this->escape($group) . '</span>';

            foreach ($items as $key => [$href, $label, $count]) {
                $links .= '<a href="' . $href . '"' . ($key === $active ? ' aria-current="page"' : '') . '>'
                    . '<span>' . $this->escape($label) . '</span>'
                    . ($count === null ? '' : '<span class="admin-side__count">' . $count . '</span>')
                    . '</a>';
            }
        }

        $share = $total === 0 ? 0.0 : $counts['tested'] / $total * 100;
        $links .= '<div class="admin-side__foot">'
            . '<span class="meta"><strong>' . $counts['tested'] . '</strong> von ' . $total . ' getestet</span>'
            . '<span class="stat__meter" style="margin-top:var(--sp-2)"><i style="width:'
            . $this->percent($share) . '%"></i></span></div>';

        return '<nav class="admin-side" aria-label="Verwaltung">' . $links . '</nav>';
    }

    private function head(
        string $title,
        string $subtitle = '',
        string $actions = '',
        string $breadcrumb = '',
        string $badge = '',
    ): string {
        return '<div class="admin-head"><div class="admin-head__text">'
            . $breadcrumb
            . ($badge === ''
                ? '<h1 class="admin-title">' . $title . '</h1>'
                : '<div class="cluster cluster--tight"><h1 class="admin-title">' . $title . '</h1>' . $badge . '</div>')
            . ($subtitle !== '' ? '<p class="admin-head__sub">' . $subtitle . '</p>' : '')
            . '</div>'
            . ($actions !== '' ? '<div class="cluster cluster--tight">' . $actions . '</div>' : '')
            . '</div>';
    }

    /** @param list<array{0: ?string, 1: string}> $items */
    private function breadcrumb(array $items): string
    {
        $html = '';

        foreach ($items as [$href, $label]) {
            $html .= '<li>' . ($href === null
                ? $this->escape($label)
                : '<a href="' . $this->escape($href) . '">' . $this->escape($label) . '</a>') . '</li>';
        }

        return '<nav aria-label="Brotkrumen"><ol class="breadcrumb">' . $html . '</ol></nav>';
    }

    private function emptyState(string $title, string $text, string $actions = '', bool $inPanel = false): string
    {
        return '<div class="empty' . ($inPanel ? ' empty--panel' : '') . '">'
            . '<p class="empty__title">' . $this->escape($title) . '</p>'
            . '<p>' . $this->escape($text) . '</p>'
            . ($actions === '' ? '' : '<div class="cluster cluster--tight">' . $actions . '</div>')
            . '</div>';
    }

    private function thumbnail(int $id, bool $hasImage): string
    {
        return $hasImage
            ? '<figure class="pimg pimg--tile"><img src="/admin/drinks/' . $id . '/image" alt="" loading="lazy"></figure>'
            : '<figure class="pimg pimg--tile"><div class="pimg__ph"></div></figure>';
    }

    /**
     * The branded upload control, used everywhere a picture is chosen so the
     * admin never falls back to the unstyled browser file input.
     */
    private function pictureField(string $id, string $label, bool $showOptionalTag = true): string
    {
        $optionalTag = $showOptionalTag ? ' <span class="label__opt">optional</span>' : '';
        $labelHtml = $label === '' ? '' : '<span class="label">' . $this->escape($label) . $optionalTag . '</span>';

        return '<div class="field">' . $labelHtml
            . '<label class="uploader uploader--sm" for="' . $this->escape($id) . '">'
            . '<input type="file" id="' . $this->escape($id) . '" name="picture" '
            . 'accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-uploader>'
            . '<strong>Foto auswählen</strong><span class="meta" data-uploader-name>JPEG, PNG, WebP</span></label></div>';
    }

    private function gradeScale(string $name, string $selected, string $label): string
    {
        $buttons = '';

        for ($value = self::GRADE_MIN; $value <= self::GRADE_MAX; ++$value) {
            $checked = $selected !== '' && (int) $selected === $value ? ' checked' : '';
            $buttons .= '<label><input type="radio" name="' . $this->escape($name) . '" value="' . $value . '"' . $checked . '>'
                . '<span>' . $value . '</span></label>';
        }

        return '<div class="grade-scale" role="group" aria-label="' . $this->escape($label) . '">' . $buttons . '</div>';
    }

    private function statusSegmented(string $selected, bool $includeTested): string
    {
        $options = ['identified' => 'Identifiziert', 'acquired' => 'Erworben'];

        if ($includeTested) {
            $options['tested'] = 'Getestet';
        }

        $buttons = '';

        foreach ($options as $value => $label) {
            $checked = $selected === $value ? ' checked' : '';
            $buttons .= '<label><input type="radio" name="lifecycle_status" value="' . $value . '"' . $checked . ' required>'
                . '<span>' . $this->escape($label) . '</span></label>';
        }

        if (!$includeTested && $selected === 'tested') {
            $buttons .= '<label><input type="radio" name="lifecycle_status" value="tested" checked><span>Getestet</span></label>';
        }

        return '<div class="segmented" role="group">' . $buttons . '</div>';
    }

    private function statusSelect(
        ?string $selected,
        string $name,
        bool $includeAll,
        string $label = '',
        string $id = '',
    ): string {
        $options = $includeAll ? '<option value="">Alle</option>' : '';

        foreach (self::STATUS_LABELS as $value => $optionLabel) {
            $options .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>'
                . $this->escape($optionLabel) . '</option>';
        }

        // Row-level status changes submit on change; the noscript button in the
        // markup keeps the form usable without JavaScript. The row control is
        // wrapped so it can carry the state's colour dot and a caret of its own
        // — a bare <select> in a table cell does not read as something you open.
        $rowControl = !$includeAll;
        $submit = $rowControl ? ' data-autosubmit' : '';
        $classes = 'select' . ($rowControl ? ' select--status' : ' select--sm');

        $select = '<select class="' . $classes . '" name="' . $this->escape($name) . '"'
            . ($id !== '' ? ' id="' . $this->escape($id) . '"' : '')
            . ($label !== '' ? ' aria-label="' . $this->escape($label) . '" title="' . $this->escape($label) . '"' : '')
            . $submit . '>' . $options . '</select>';

        if (!$rowControl) {
            return $select;
        }

        $modifier = array_key_exists((string) $selected, self::STATUS_LABELS) ? (string) $selected : 'identified';

        return '<span class="statuspick statuspick--' . $modifier . '">' . $select . '</span>';
    }

    private function stateBadge(string $status): string
    {
        $modifier = array_key_exists($status, self::STATUS_LABELS) ? $status : 'identified';

        return '<span class="state state--' . $modifier . '">' . $this->escape($this->statusLabel($status)) . '</span>';
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'Unbekannt';
    }

    private function logoutButton(string $csrfToken): string
    {
        return '<form method="post" action="/admin/logout" style="display:inline">'
            . $this->csrfField($csrfToken)
            . '<button class="btn btn--sm admin-top__logout" type="submit">Abmelden</button></form>';
    }

    private function csrfField(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->escape($token) . '">';
    }

    private function error(?string $error): string
    {
        return $error === null
            ? ''
            : '<p class="notice notice--error" role="alert" style="margin-bottom:var(--sp-4)">'
                . '<span>' . $this->escape($error) . '</span></p>';
    }

    private function grade(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    /** A CSS width, clamped to the bar and written with a decimal point. */
    private function percent(float $value): string
    {
        return number_format(max(0.0, min(100.0, $value)), 2, '.', '');
    }

    private function percentLabel(float $value): string
    {
        return number_format(max(0.0, min(100.0, $value)), 0, ',', '.') . ' %';
    }

    private function priceForDisplay(?string $decimal): string
    {
        if ($decimal === null) {
            return '';
        }

        return $this->escape(rtrim(rtrim(number_format((float) $decimal, 4, ',', ''), '0'), ','));
    }

    private function cellOrDash(?string $value): string
    {
        return $value === null || trim($value) === ''
            ? '<span class="meta">–</span>'
            : $this->escape($value);
    }

    private function notesCell(?string $notes): string
    {
        $notes = $notes === null ? '' : trim($notes);

        if ($notes === '') {
            return '';
        }

        $short = mb_substr($notes, 0, 90);

        if ($short !== $notes) {
            $short = rtrim($short) . '…';
        }

        return '<span class="dt__note" title="' . $this->escape($notes) . '">' . $this->escape($short) . '</span>';
    }

    private function priceCell(?string $amount, ?int $volumeMl): string
    {
        if ($amount === null || $volumeMl === null) {
            return '<span class="meta">–</span>';
        }

        // The column is the €-per-0,5-l basis and is always shown to the cent
        // (x,xx). A non-standard container adds its raw price/volume underneath.
        $perHalfLitre = $this->escape(number_format((float) $amount * 500 / $volumeMl, 2, ',', ''));

        if ($volumeMl === 500) {
            return '<span style="white-space:nowrap">' . $perHalfLitre . ' €</span>';
        }

        return '<span style="white-space:nowrap">' . $perHalfLitre . ' €</span>'
            . '<span class="dt__sub" style="white-space:nowrap">' . $this->priceForDisplay($amount) . ' € / ' . $volumeMl . ' ml</span>';
    }

    /** @param array<array-key, mixed> $values */
    private function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
