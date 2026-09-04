<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

use Spezitest\Admin\Testing\TestFormData;
use Spezitest\Domain\Rating\RatingResult;

/**
 * Server-rendered admin HTML, styled with the Spezitest Design System admin
 * shell. The admin is deliberately more compact and functional than the public
 * site; the quick-add workflow stays a single short form (name + status +
 * optional picture).
 *
 * All user-controlled values are escaped for their output context.
 */
final class HtmlRenderer
{
    private const GRADE_MIN = 0;

    private const GRADE_MAX = 10;

    private const TESTERS = ['manu' => 'Manu', 'fabi' => 'Fabi', 'schorsch' => 'Schorsch'];

    private const CATEGORIES = ['optik' => 'Optik', 'sueffigkeit' => 'Süffigkeit', 'geschmack' => 'Geschmack'];

    public function login(string $csrfToken, ?string $error = null): string
    {
        return $this->document(
            'Anmeldung',
            '<div class="wrap" style="max-width:var(--w-form);padding-block:var(--sp-9)">'
            . '<div class="stack-lg"><a class="brand" href="/"><img src="/assets/spezitest-logo-color.svg" alt="Spezitest" width="150" height="35"></a>'
            . '<div class="panel panel--pad stack-lg"><div class="stack"><span class="eyebrow">Verwaltung</span>'
            . '<h1 class="h1">Anmelden</h1></div>'
            . $this->error($error)
            . '<form method="post" action="/admin/login" class="stack-lg">'
            . $this->csrfField($csrfToken)
            . '<div class="field"><label class="label" for="u">Benutzername</label>'
            . '<input class="input" id="u" name="username" autocomplete="username" required></div>'
            . '<div class="field"><label class="label" for="p">Passwort</label>'
            . '<input class="input" id="p" type="password" name="password" autocomplete="current-password" required></div>'
            . '<button class="btn btn--primary btn--block" type="submit">Anmelden</button></form></div></div></div>',
            false,
        );
    }

    /** @param array{identified: int, acquired: int, tested: int} $counts */
    public function dashboard(array $counts, string $csrfToken): string
    {
        $total = $counts['identified'] + $counts['acquired'] + $counts['tested'];

        $body = $this->head('Verwaltung', 'Übersicht')
            . '<div class="grid grid--4" style="margin-bottom:var(--sp-6)">'
            . $this->countPanel('identified', $counts['identified'])
            . $this->countPanel('acquired', $counts['acquired'])
            . $this->countPanel('tested', $counts['tested'])
            . '<div class="panel"><span class="badge">Katalog gesamt</span>'
            . '<span class="figure__num" style="display:block;margin-top:var(--sp-3);font-size:2.25rem">' . $total . '</span></div>'
            . '</div>'
            . '<div class="split" style="gap:var(--sp-5)">'
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Schnell erfassen</h2>'
            . '<span class="meta">Bild optional</span></div>'
            . $this->quickAddForm($csrfToken)
            . '</section>'
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Wie es weitergeht</h2></div>'
            . '<ul class="stack-sm meta">'
            . '<li><strong style="color:var(--navy)">Identifiziert →</strong> im Getränkemarkt kaufen, dann auf „Erworben“ setzen.</li>'
            . '<li><strong style="color:var(--navy)">Erworben →</strong> Testabend, neun Noten erfassen, Test abschließen.</li>'
            . '<li><strong style="color:var(--navy)">Getestet →</strong> erscheint automatisch im öffentlichen Ranking.</li>'
            . '</ul>'
            . '<p style="margin-top:var(--sp-4)"><a class="btn btn--secondary btn--sm" href="/admin/drinks?lifecycle_status=acquired">Spezis, die auf einen Test warten</a></p>'
            . '</section></div>';

        return $this->document('Übersicht', $body, true, $csrfToken, 'dashboard');
    }

    /**
     * @param list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, has_primary_image: bool}> $drinks
     */
    public function drinks(
        array $drinks,
        string $search,
        ?string $status,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $rows = '';

        foreach ($drinks as $drink) {
            $id = $drink['id'];
            $image = $drink['has_primary_image']
                ? '<figure class="pimg pimg--thumb"><img src="/admin/drinks/' . $id . '/image" alt=""></figure>'
                : '<figure class="pimg pimg--thumb"><div class="pimg__ph"><span>–</span></div></figure>';
            $testAction = in_array($drink['lifecycle_status'], ['acquired', 'tested'], true)
                ? ' · <a href="/admin/drinks/' . $id . '/test">' . ($drink['lifecycle_status'] === 'tested' ? 'Test bearbeiten' : 'Testen') . '</a>'
                : '';
            $rows .= '<tr><td>' . $image . '</td>'
                . '<td><a href="/admin/drinks/' . $id . '/edit"><strong>' . $this->escape($drink['name']) . '</strong></a></td>'
                . '<td>' . ($drink['manufacturer'] === null ? '–' : $this->escape($drink['manufacturer'])) . '</td>'
                . '<td>' . $this->stateBadge($drink['lifecycle_status'])
                . '<form method="post" action="/admin/drinks/' . $id . '/status" class="cluster cluster--tight" style="margin-top:var(--sp-2)">'
                . $this->csrfField($csrfToken)
                . $this->statusSelect($drink['lifecycle_status'], 'lifecycle_status', false)
                . '<button class="btn btn--secondary btn--sm" type="submit">Setzen</button></form></td>'
                . '<td style="text-align:right;white-space:nowrap"><a href="/admin/drinks/' . $id . '/edit">Bearbeiten</a>'
                . $testAction
                . ' · <a href="/admin/drinks/' . $id . '/delete">Löschen</a></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">Keine Getränke gefunden.</td></tr>';
        }

        $body = $this->head('Katalog', 'Spezis', '<a class="btn btn--accent" href="/admin/drinks/new">+ Spezi hinzufügen</a>')
            . $this->error($error)
            . '<div class="panel"><form class="toolbar" method="get" action="/admin/drinks" style="margin-bottom:var(--sp-4)">'
            . '<div class="search" style="flex:1;min-width:220px;max-width:420px"><label class="visually-hidden" for="q">Suchen</label>'
            . '<input id="q" name="q" type="search" placeholder="Name oder Hersteller" value="' . $this->escape($search) . '"></div>'
            . '<div class="cluster cluster--tight"><label class="label" for="st">Status</label>'
            . $this->statusSelect($status, 'lifecycle_status', true) . '</div>'
            . '<button class="btn btn--secondary btn--sm" type="submit">Filtern</button></form>'
            . '<div class="table-scroll"><table class="table"><thead><tr><th style="width:64px">Bild</th><th>Name</th>'
            . '<th>Hersteller</th><th>Status</th><th><span class="visually-hidden">Aktionen</span></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></div>';

        return $this->document('Spezis', $body, true, $csrfToken, 'drinks');
    }

    /** @param array<array-key, mixed> $values */
    public function createForm(string $csrfToken, array $values = [], ?string $error = null): string
    {
        $body = $this->head('Schnellerfassung', 'Spezi hinzufügen')
            . '<div style="max-width:var(--w-form)">'
            . '<form class="panel panel--pad stack-lg" method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . $this->error($error)
            . '<div class="field"><label class="label" for="name">Name <span class="req">*</span></label>'
            . '<input class="input" id="name" name="name" maxlength="255" required autofocus '
            . 'style="font-size:var(--fs-body-lg)" value="' . $this->value($values, 'name') . '">'
            . '<p class="hint">Marke wie auf dem Etikett. Hersteller, Region und Bild später ergänzen.</p></div>'
            . '<div class="field"><span class="label" id="stl">Status <span class="req">*</span></span>'
            . $this->statusSegmented($this->stringValue($values, 'lifecycle_status') ?? 'identified', false)
            . '<p class="hint">Im Regal gesehen → Identifiziert. Im Kasten → Erworben.</p></div>'
            . '<div class="field"><span class="label">Bild <span class="meta" style="letter-spacing:0;text-transform:none">(optional)</span></span>'
            . '<label class="uploader"><input type="file" name="picture" accept="image/jpeg,image/png,image/webp" class="visually-hidden">'
            . '<strong>Foto auswählen</strong><span class="meta">JPEG, PNG oder WebP</span></label></div>'
            . '<div class="form-actions" style="flex-direction:column;align-items:stretch">'
            . '<button class="btn btn--accent btn--lg btn--block" type="submit">Spezi hinzufügen</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a></div>'
            . '<p class="notice"><span>Nach dem Speichern landet der Eintrag direkt im Katalog. '
            . 'Alles Weitere ist optional und jederzeit nachtragbar.</span></p>'
            . '</form></div>';

        return $this->document('Spezi hinzufügen', $body, true, $csrfToken, 'create');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} $drink
     */
    public function editForm(array $drink, bool $hasImage, string $csrfToken, ?string $error = null): string
    {
        $id = $drink['id'];
        $imageBlock = $hasImage
            ? '<figure class="pimg" style="border:0"><img src="/admin/drinks/' . $id . '/image" alt="Primärbild"></figure>'
                . '<label class="check"><input type="checkbox" name="remove_image" value="1"><span>Vorhandenes Bild entfernen</span></label>'
            : '<p class="meta">Kein Bild vorhanden.</p>';

        $testLink = in_array($drink['lifecycle_status'], ['acquired', 'tested'], true)
            ? '<a class="btn btn--secondary btn--sm" href="/admin/drinks/' . $id . '/test">'
                . ($drink['lifecycle_status'] === 'tested' ? 'Test bearbeiten' : 'Test erfassen') . '</a>'
            : '';

        $body = '<div class="admin-head"><div><nav aria-label="Brotkrumen"><ol class="breadcrumb">'
            . '<li><a href="/admin/drinks">Spezis</a></li><li>' . $this->escape($drink['name']) . '</li></ol></nav>'
            . '<h1 class="admin-title" style="margin-top:var(--sp-2)">' . $this->escape($drink['name']) . '</h1>'
            . '<div class="cluster cluster--tight" style="margin-top:var(--sp-2)">' . $this->stateBadge($drink['lifecycle_status']) . '</div></div>'
            . '<div class="cluster cluster--tight">'
            . '<a class="btn btn--ghost btn--sm" href="/spezi/' . $id . '">Öffentliche Seite</a>' . $testLink . '</div></div>'
            . $this->error($error)
            . '<form method="post" action="/admin/drinks/' . $id . '" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<div class="split split--sidebar" style="gap:var(--sp-5)"><div class="stack-lg">'
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Stammdaten</h2></div>'
            . '<div class="form-row" style="grid-template-columns:1fr 1fr">'
            . '<div class="field" style="grid-column:1/-1"><label class="label" for="e1">Name <span class="req">*</span></label>'
            . '<input class="input" id="e1" name="name" maxlength="255" required value="' . $this->escape($drink['name']) . '"></div>'
            . '<div class="field"><label class="label" for="e2">Hersteller</label>'
            . '<input class="input" id="e2" name="manufacturer" maxlength="255" value="' . $this->escape($drink['manufacturer'] ?? '') . '"></div>'
            . '<div class="field"><label class="label" for="e3">Ort</label>'
            . '<input class="input" id="e3" name="origin_location" maxlength="255" value="' . $this->escape($drink['origin_location'] ?? '') . '"></div>'
            . '<div class="field"><label class="label" for="e4">Region / Land</label>'
            . '<input class="input" id="e4" name="origin_region" maxlength="128" value="' . $this->escape($drink['origin_region'] ?? '') . '"></div>'
            . '<div class="field" style="grid-column:1/-1"><label class="label" for="e5">Notizen</label>'
            . '<textarea class="textarea" id="e5" name="notes">' . $this->escape($drink['notes'] ?? '') . '</textarea></div>'
            . '</div></section>'
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Status</h2></div>'
            . $this->statusSegmented($drink['lifecycle_status'], $drink['lifecycle_status'] === 'tested')
            . '<p class="hint" style="margin-top:var(--sp-3)">„Getestet“ lässt sich nur über die Testerfassung setzen '
            . '(neun Noten für alle drei Tester).</p></section>'
            . '</div><aside class="stack-lg">'
            . '<section class="panel"><div class="panel__head"><h2 class="panel__title">Bild</h2></div>'
            . $imageBlock
            . '<div class="field" style="margin-top:var(--sp-3)"><label class="label" for="pic">Bild ersetzen</label>'
            . '<input type="file" id="pic" name="picture" accept="image/jpeg,image/png,image/webp"></div></section>'
            . '</aside></div>'
            . '<div class="sticky-actions"><button class="btn btn--primary" type="submit">Änderungen speichern</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a></div>'
            . '</form>';

        return $this->document('Spezi bearbeiten', $body, true, $csrfToken, 'drinks');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} $drink
     */
    public function deleteConfirmation(array $drink, string $csrfToken, ?string $error = null): string
    {
        $id = $drink['id'];
        $body = $this->head('Katalog', 'Spezi löschen')
            . '<div class="panel panel--pad" style="max-width:var(--w-form)">'
            . $this->error($error)
            . '<p>Soll „<strong>' . $this->escape($drink['name']) . '</strong>“ wirklich gelöscht werden? '
            . 'Getestete Einträge mit Noten lassen sich nicht löschen.</p>'
            . '<form method="post" action="/admin/drinks/' . $id . '/delete" class="form-actions" style="margin-top:var(--sp-4)">'
            . $this->csrfField($csrfToken)
            . '<button class="btn btn--secondary" type="submit" style="border-color:var(--red);color:var(--red-900)">Endgültig löschen</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a></form></div>';

        return $this->document('Spezi löschen', $body, true, $csrfToken, 'drinks');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} $drink
     */
    public function testForm(
        array $drink,
        TestFormData $data,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $id = $drink['id'];

        $panels = '';

        foreach (self::TESTERS as $code => $label) {
            $fields = '';

            foreach (self::CATEGORIES as $category => $categoryLabel) {
                $fields .= '<div class="field"><span class="label">' . $this->escape($categoryLabel) . '</span>'
                    . $this->gradeScale($code . '_' . $category, $data->grade($code, $category)) . '</div>';
            }

            $panels .= '<section class="panel panel--pad"><div class="panel__head">'
                . '<h2 class="panel__title">' . $this->escape($label) . '</h2>'
                . '<span class="meta">Ganze Zahl 0–10 · 0 = niedrig, 10 = hoch</span></div>'
                . '<div class="stack-lg">' . $fields . '</div></section>';
        }

        $summary = '<div class="panel panel--pad" style="margin-bottom:var(--sp-5)"><div class="cluster cluster--between">'
            . '<div><span class="eyebrow">Getestet wird</span>'
            . '<p class="h3" style="font-weight:700;color:var(--navy)">' . $this->escape($drink['name']) . '</p>'
            . '<p class="meta">Status: ' . $this->escape($this->statusLabel($drink['lifecycle_status'])) . '</p></div>'
            . '<div class="score" style="align-items:flex-end"><span class="score__label">Gesamtwertung (berechnet)</span>'
            . '<span class="score__num" style="font-size:2.5rem" data-gesamt-preview>'
            . ($data->result !== null ? $this->grade($data->result->gesamt()) : '–') . '</span></div></div></div>';

        $priceValue = $this->escape($data->price);
        $notesValue = $this->escape($data->notes);

        $body = '<div class="admin-head"><div><nav aria-label="Brotkrumen"><ol class="breadcrumb">'
            . '<li><a href="/admin/drinks">Spezis</a></li>'
            . '<li><a href="/admin/drinks/' . $id . '/edit">' . $this->escape($drink['name']) . '</a></li>'
            . '<li>Test</li></ol></nav><h1 class="admin-title" style="margin-top:var(--sp-2)">Test erfassen</h1></div></div>'
            . $this->error($error)
            . ($drink['lifecycle_status'] === 'identified'
                ? '<p class="notice notice--error"><span>Bitte das Getränk zuerst auf „Erworben“ setzen.</span></p>'
                : '')
            . $summary
            . '<form method="post" action="/admin/drinks/' . $id . '/test" class="stack-lg" data-test-form>'
            . $this->csrfField($csrfToken)
            . $panels
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Notiz &amp; Preis</h2>'
            . '<span class="meta">optional</span></div>'
            . '<div class="form-row" style="grid-template-columns:2fr 1fr">'
            . '<div class="field" style="grid-column:1/-1"><label class="label" for="tn">Testnotiz</label>'
            . '<textarea class="textarea" id="tn" name="notes" placeholder="Farbe, Kohlensäure, Süße, Orangenanteil …">' . $notesValue . '</textarea></div>'
            . '<div class="field"><label class="label" for="tp">Preis pro Gebinde</label>'
            . '<input class="input" id="tp" name="price" inputmode="decimal" placeholder="0,89" value="' . $priceValue . '"></div>'
            . '</div></section>'
            . '<p class="notice"><span><strong>Prüfung vor dem Abschließen:</strong> Alle neun Noten müssen gesetzt sein. '
            . 'Erst dann wird der Status auf „Getestet“ gesetzt.</span></p>'
            . '<div class="sticky-actions">'
            . '<button class="btn btn--accent" type="submit" formaction="/admin/drinks/' . $id . '/test/complete">'
            . 'Test abschließen &amp; auf „Getestet“ setzen</button>'
            . '<button class="btn btn--secondary" type="submit">Zwischenspeichern</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks/' . $id . '/edit">Abbrechen</a></div>'
            . '</form>';

        return $this->document('Test erfassen', $body, true, $csrfToken, 'drinks');
    }

    public function notFound(string $csrfToken): string
    {
        return $this->document(
            'Nicht gefunden',
            $this->head('Verwaltung', 'Nicht gefunden')
            . '<div class="panel panel--pad"><p>Der Eintrag wurde nicht gefunden.</p>'
            . '<p style="margin-top:var(--sp-3)"><a class="btn btn--secondary btn--sm" href="/admin/drinks">Zur Übersicht</a></p></div>',
            true,
            $csrfToken,
            'drinks',
        );
    }

    // --- shell -----------------------------------------------------------

    private function document(string $title, string $content, bool $authenticated, string $csrfToken = '', string $active = ''): string
    {
        $shellOpen = '<div class="admin"><div class="admin-top">'
            . '<a class="admin-top__brand" href="/admin"><img src="/assets/spezitest-icon.svg" alt="" width="24" height="24"><span>Spezitest</span> Verwaltung</a>'
            . '<div class="cluster cluster--tight">'
            . ($authenticated ? '<a href="/" style="font-size:var(--fs-sm);font-weight:700">Website ansehen</a>' : '')
            . ($authenticated ? $this->logoutButton($csrfToken) : '')
            . '</div></div>';

        if ($authenticated) {
            $shellOpen .= '<div class="admin-body">' . $this->sidebar($active) . '<main class="admin-main" id="main">' . $content . '</main></div>';
        } else {
            $shellOpen .= '<main class="admin-main" id="main">' . $content . '</main>';
        }

        $shellOpen .= '</div>';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->escape($title) . ' · Spezitest Verwaltung</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<link rel="stylesheet" href="/assets/spezitest.css">'
            . '<link rel="icon" href="/assets/spezitest-icon.svg" type="image/svg+xml">'
            . '</head><body><a class="skip-link" href="#main">Zum Inhalt springen</a>' . $shellOpen . '</body></html>';
    }

    private function sidebar(string $active): string
    {
        $items = [
            'dashboard' => ['/admin', 'Übersicht'],
            'drinks' => ['/admin/drinks', 'Spezis'],
            'create' => ['/admin/drinks/new', 'Spezi hinzufügen'],
        ];
        $links = '<span class="admin-side__group">Katalog</span>';

        foreach ($items as $key => [$href, $label]) {
            $current = $key === $active ? ' aria-current="page"' : '';
            $links .= '<a href="' . $href . '"' . $current . '>' . $this->escape($label) . '</a>';
        }

        return '<nav class="admin-side" aria-label="Verwaltung">' . $links . '</nav>';
    }

    private function head(string $eyebrow, string $title, string $actions = ''): string
    {
        return '<div class="admin-head"><div><span class="eyebrow">' . $this->escape($eyebrow) . '</span>'
            . '<h1 class="admin-title">' . $this->escape($title) . '</h1></div>'
            . ($actions !== '' ? '<div class="cluster cluster--tight">' . $actions . '</div>' : '')
            . '</div>';
    }

    private function countPanel(string $status, int $count): string
    {
        return '<div class="panel"><div class="cluster cluster--between">' . $this->stateBadge($status) . '</div>'
            . '<span class="figure__num" style="display:block;margin-top:var(--sp-3);font-size:2.25rem">' . $count . '</span></div>';
    }

    private function quickAddForm(string $csrfToken): string
    {
        return '<form class="stack-lg" method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<div class="field"><label class="label" for="qn">Name <span class="req">*</span></label>'
            . '<input class="input" id="qn" name="name" maxlength="255" required placeholder="z. B. Talbach Cola-Mix"></div>'
            . '<div class="field"><span class="label">Status <span class="req">*</span></span>'
            . $this->statusSegmented('identified', false) . '</div>'
            . '<div class="field"><span class="label">Bild <span class="meta" style="letter-spacing:0;text-transform:none">(optional)</span></span>'
            . '<input type="file" name="picture" accept="image/jpeg,image/png,image/webp"></div>'
            . '<div class="form-actions"><button class="btn btn--accent" type="submit">Spezi hinzufügen</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks/new">Mit Details →</a></div></form>';
    }

    private function gradeScale(string $name, string $selected): string
    {
        $buttons = '';

        for ($value = self::GRADE_MIN; $value <= self::GRADE_MAX; ++$value) {
            $checked = $selected !== '' && (int) $selected === $value ? ' checked' : '';
            $buttons .= '<label><input type="radio" name="' . $this->escape($name) . '" value="' . $value . '"' . $checked . '>'
                . '<span>' . $value . '</span></label>';
        }

        return '<div class="grade-scale" role="group" aria-label="' . $this->escape($name) . '">' . $buttons . '</div>';
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

    private function statusSelect(?string $selected, string $name, bool $includeAll): string
    {
        $options = $includeAll ? '<option value="">Alle</option>' : '';

        foreach (['identified' => 'Identifiziert', 'acquired' => 'Erworben', 'tested' => 'Getestet'] as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $this->escape($label) . '</option>';
        }

        return '<select class="select" name="' . $this->escape($name) . '" style="width:auto">' . $options . '</select>';
    }

    private function stateBadge(string $status): string
    {
        $modifier = in_array($status, ['identified', 'acquired', 'tested'], true) ? $status : 'identified';

        return '<span class="state state--' . $modifier . '">' . $this->escape($this->statusLabel($status)) . '</span>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'identified' => 'Identifiziert',
            'acquired' => 'Erworben',
            'tested' => 'Getestet',
            default => 'Unbekannt',
        };
    }

    private function logoutButton(string $csrfToken): string
    {
        return '<form method="post" action="/admin/logout" style="display:inline">'
            . $this->csrfField($csrfToken)
            . '<button class="btn btn--sm" type="submit" style="background:var(--red);color:#fff">Abmelden</button></form>';
    }

    private function csrfField(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->escape($token) . '">';
    }

    private function error(?string $error): string
    {
        return $error === null
            ? ''
            : '<p class="notice notice--error" role="alert"><span>' . $this->escape($error) . '</span></p>';
    }

    private function grade(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    /** @param array<array-key, mixed> $values */
    private function value(array $values, string $key): string
    {
        return $this->escape($this->stringValue($values, $key) ?? '');
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
