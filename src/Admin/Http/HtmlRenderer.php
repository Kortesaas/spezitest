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

    /**
     * @param array{identified: int, acquired: int, tested: int} $counts
     * @param list<array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, has_primary_image: bool}> $waiting
     */
    public function dashboard(array $counts, array $waiting, string $csrfToken): string
    {
        $total = $counts['identified'] + $counts['acquired'] + $counts['tested'];
        $queue = '';

        foreach (array_slice($waiting, 0, 6) as $drink) {
            $queue .= '<li class="queue__row">'
                . $this->thumbnail($drink['id'], $drink['has_primary_image'])
                . '<span class="queue__body"><a class="queue__name" href="/admin/drinks/' . $drink['id'] . '/edit">'
                . $this->escape($drink['name']) . '</a>'
                . ($drink['manufacturer'] === null ? '' : '<span class="meta">' . $this->escape($drink['manufacturer']) . '</span>')
                . '</span>'
                . '<a class="btn btn--primary btn--sm" href="/admin/drinks/' . $drink['id'] . '/test">Testen</a></li>';
        }

        $body = $this->head('', 'Übersicht')
            . '<div class="grid grid--4" style="margin-bottom:var(--sp-6)">'
            . $this->countPanel('identified', $counts['identified'], '/admin/drinks?lifecycle_status=identified')
            . $this->countPanel('acquired', $counts['acquired'], '/admin/drinks?lifecycle_status=acquired')
            . $this->countPanel('tested', $counts['tested'], '/admin/drinks?lifecycle_status=tested')
            . '<a class="panel stat" href="/admin/drinks"><span class="badge">Gesamt</span>'
            . '<span class="stat__num">' . $total . '</span></a>'
            . '</div>'
            . '<div class="split split--wide">'
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Warten auf den Test</h2>'
            . '<a class="link-arrow" href="/admin/drinks?lifecycle_status=acquired">Alle ' . $counts['acquired'] . '</a></div>'
            . ($queue === ''
                ? '<p class="meta">Nichts offen – alle erworbenen Spezis sind getestet.</p>'
                : '<ul class="queue">' . $queue . '</ul>')
            . '</section>'
            . '<section class="panel panel--pad"><div class="panel__head"><h2 class="panel__title">Schnell erfassen</h2></div>'
            . $this->quickAddForm($csrfToken)
            . '</section>'
            . '</div>';

        return $this->document('Übersicht', $body, true, $csrfToken, 'dashboard');
    }

    private function thumbnail(int $id, bool $hasImage): string
    {
        return $hasImage
            ? '<figure class="pimg pimg--thumb"><img src="/admin/drinks/' . $id . '/image" alt="" loading="lazy"></figure>'
            : '<figure class="pimg pimg--thumb"><div class="pimg__ph"></div></figure>';
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
            $testAction = in_array($drink['lifecycle_status'], ['acquired', 'tested'], true)
                ? '<a class="btn btn--secondary btn--sm" href="/admin/drinks/' . $id . '/test">Test</a>'
                : '<span class="meta">–</span>';
            $rows .= '<tr><td>' . $this->thumbnail($id, $drink['has_primary_image']) . '</td>'
                . '<td><a href="/admin/drinks/' . $id . '/edit"><strong>' . $this->escape($drink['name']) . '</strong></a>'
                . ($drink['manufacturer'] === null ? '' : '<br><span class="meta">' . $this->escape($drink['manufacturer']) . '</span>') . '</td>'
                . '<td>' . $this->stateBadge($drink['lifecycle_status']) . '</td>'
                . '<td class="table__actions"><div class="cluster cluster--tight" style="justify-content:flex-end">'
                . '<form method="post" action="/admin/drinks/' . $id . '/status" class="cluster cluster--tight status-form">'
                . $this->csrfField($csrfToken)
                . $this->statusSelect($drink['lifecycle_status'], 'lifecycle_status', false, 'Status ändern')
                . '<noscript><button class="btn btn--secondary btn--sm" type="submit">Setzen</button></noscript></form>'
                . $testAction . '</div></td></tr>';
        }

        $filtered = $search !== '' || $status !== null;

        if ($rows === '') {
            $rows = '<tr><td colspan="4"><p class="meta">Nichts gefunden.</p></td></tr>';
        }

        $body = $this->head('', 'Spezis', '<a class="btn btn--accent" href="/admin/drinks/new">+ Spezi hinzufügen</a>')
            . $this->error($error)
            . '<div class="panel"><form class="toolbar" method="get" action="/admin/drinks" style="margin-bottom:var(--sp-4)">'
            . '<div class="search" style="flex:1;min-width:220px;max-width:420px"><label class="visually-hidden" for="q">Suchen</label>'
            . '<input id="q" name="q" type="search" placeholder="Name oder Hersteller" value="' . $this->escape($search) . '">'
            . '<button type="submit">Suchen</button></div>'
            . '<div class="cluster cluster--tight"><label class="label" for="st">Status</label>'
            . $this->statusSelect($status, 'lifecycle_status', true, 'Nach Status filtern', 'st')
            . '<button class="btn btn--secondary btn--sm" type="submit">Filtern</button>'
            . ($filtered ? '<a class="btn btn--ghost btn--sm" href="/admin/drinks">Zurücksetzen</a>' : '')
            . '</div></form>'
            . '<p class="meta" style="margin-bottom:var(--sp-3)"><strong style="color:var(--navy)">' . count($drinks) . '</strong> '
            . (count($drinks) === 1 ? 'Eintrag' : 'Einträge') . '</p>'
            . '<div class="table-scroll"><table class="table table--drinks"><thead><tr>'
            . '<th><span class="visually-hidden">Bild</span></th><th>Name</th><th>Status</th>'
            . '<th class="table__actions">Aktionen</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></div>';

        return $this->document('Spezis', $body, true, $csrfToken, 'drinks');
    }

    /** @param array<array-key, mixed> $values */
    public function createForm(string $csrfToken, array $values = [], ?string $error = null): string
    {
        $body = $this->head('', 'Spezi hinzufügen')
            . '<div style="max-width:var(--w-form)">'
            . '<form class="panel panel--pad stack-lg" method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . $this->error($error)
            . '<div class="field"><label class="label" for="name">Name <span class="req">*</span></label>'
            . '<input class="input" id="name" name="name" maxlength="255" required autofocus '
            . 'style="font-size:var(--fs-body-lg)" value="' . $this->value($values, 'name') . '"></div>'
            . '<div class="field"><span class="label" id="stl">Status <span class="req">*</span></span>'
            . $this->statusSegmented($this->stringValue($values, 'lifecycle_status') ?? 'identified', false) . '</div>'
            . '<div class="field"><span class="label">Bild <span class="meta" style="letter-spacing:0;text-transform:none">optional</span></span>'
            . '<label class="uploader"><input type="file" name="picture" accept="image/jpeg,image/png,image/webp" class="visually-hidden">'
            . '<strong>Foto auswählen</strong><span class="meta">JPEG, PNG, WebP</span></label></div>'
            . '<div class="form-actions" style="flex-direction:column;align-items:stretch">'
            . '<button class="btn btn--accent btn--lg btn--block" type="submit">Hinzufügen</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a></div>'
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
            ? '<figure class="pimg pimg--square" style="border:0"><img src="/admin/drinks/' . $id . '/image" alt="Primärbild"></figure>'
                . '<label class="check" style="margin-top:var(--sp-3)"><input type="checkbox" name="remove_image" value="1">'
                . '<span>Bild entfernen</span></label>'
            : '<figure class="pimg pimg--square"><div class="pimg__ph"><span>Kein Bild</span></div></figure>';

        $testLink = in_array($drink['lifecycle_status'], ['acquired', 'tested'], true)
            ? '<a class="btn btn--secondary btn--sm" href="/admin/drinks/' . $id . '/test">'
                . ($drink['lifecycle_status'] === 'tested' ? 'Test bearbeiten' : 'Test erfassen') . '</a>'
            : '';

        $body = '<div class="admin-head"><div><nav aria-label="Brotkrumen"><ol class="breadcrumb">'
            . '<li><a href="/admin/drinks">Spezis</a></li><li>' . $this->escape($drink['name']) . '</li></ol></nav>'
            . '<div class="cluster cluster--tight" style="margin-top:var(--sp-2)">'
            . '<h1 class="admin-title">' . $this->escape($drink['name']) . '</h1>'
            . $this->stateBadge($drink['lifecycle_status']) . '</div></div>'
            . '<div class="cluster cluster--tight">' . $testLink
            . '<a class="btn btn--ghost btn--sm" href="/spezi/' . $id . '">Öffentliche Seite</a></div></div>'
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
            . '<p class="hint" style="margin-top:var(--sp-3)">„Getestet“ nur über die Testerfassung.</p></section>'
            . '<div class="form-actions"><button class="btn btn--primary" type="submit">Änderungen speichern</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks">Abbrechen</a>'
            . '<a class="btn btn--ghost btn--danger" href="/admin/drinks/' . $id . '/delete" '
            . 'style="margin-left:auto">Löschen</a></div>'
            . '</div><aside class="stack-lg">'
            . '<section class="panel"><div class="panel__head"><h2 class="panel__title">Bild</h2></div>'
            . $imageBlock
            . $this->pictureField('pic', $hasImage ? 'Ersetzen' : 'Hinzufügen')
            . '</section>'
            . '</aside></div>'
            . '</form>';

        return $this->document('Spezi bearbeiten', $body, true, $csrfToken, 'drinks');
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} $drink
     */
    public function deleteConfirmation(array $drink, string $csrfToken, ?string $error = null): string
    {
        $id = $drink['id'];
        $body = $this->head('', 'Spezi löschen')
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
        bool $hasImage = false,
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
                . '<h2 class="panel__title">' . $this->escape($label) . '</h2></div>'
                . '<div class="stack-lg">' . $fields . '</div></section>';
        }

        $summary = '<div class="panel test-summary">'
            . $this->thumbnail($id, $hasImage)
            . '<div class="test-summary__body">'
            . '<a class="test-summary__name" href="/admin/drinks/' . $id . '/edit">' . $this->escape($drink['name']) . '</a>'
            . '<span class="meta">Noten 0–10 · höher ist besser</span></div>'
            . '<div class="score" style="align-items:flex-end"><span class="score__label">Gesamt</span>'
            . '<span class="score__num" style="font-size:2.25rem" data-gesamt-preview>'
            . ($data->result !== null ? $this->grade($data->result->gesamt()) : '–') . '</span></div></div>';

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
            . '<div class="sticky-actions">'
            . '<button class="btn btn--accent" type="submit" formaction="/admin/drinks/' . $id . '/test/complete">'
            . 'Abschließen</button>'
            . '<button class="btn btn--secondary" type="submit">Zwischenspeichern</button>'
            . '<a class="btn btn--ghost" href="/admin/drinks/' . $id . '/edit">Abbrechen</a>'
            . '<span class="meta" style="align-self:center">Abschließen setzt „Getestet“ – alle 9 Noten nötig.</span></div>'
            . '</form>';

        return $this->document('Test erfassen', $body, true, $csrfToken, 'drinks');
    }

    public function notFound(string $csrfToken): string
    {
        return $this->document(
            'Nicht gefunden',
            $this->head('', 'Nicht gefunden')
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
            . '<a class="admin-top__brand" href="/admin">'
            . '<img src="/assets/spezitest-logo-white.svg" alt="Spezitest" width="120" height="28">'
            . '<span class="admin-top__tag">Verwaltung</span></a>'
            . '<div class="cluster cluster--tight">'
            . ($authenticated ? '<a class="admin-top__link" href="/">Website ansehen</a>' : '')
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
            . '<link rel="stylesheet" href="/assets/spezitest.css?v=p16">'
            . '<link rel="icon" href="/assets/spezitest-icon.svg" type="image/svg+xml">'
            . '</head><body><a class="skip-link" href="#main">Zum Inhalt springen</a>' . $shellOpen
            . '<script src="/assets/spezitest.js?v=p16" defer></script>'
            . '</body></html>';
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
        return '<div class="admin-head"><div>'
            . ($eyebrow !== '' ? '<span class="eyebrow">' . $this->escape($eyebrow) . '</span>' : '')
            . '<h1 class="admin-title">' . $this->escape($title) . '</h1></div>'
            . ($actions !== '' ? '<div class="cluster cluster--tight">' . $actions . '</div>' : '')
            . '</div>';
    }

    private function countPanel(string $status, int $count, string $href): string
    {
        return '<a class="panel stat" href="' . $this->escape($href) . '">'
            . $this->stateBadge($status)
            . '<span class="stat__num">' . $count . '</span></a>';
    }

    private function quickAddForm(string $csrfToken): string
    {
        return '<form class="stack-lg" method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<div class="field"><label class="label" for="qn">Name <span class="req">*</span></label>'
            . '<input class="input" id="qn" name="name" maxlength="255" required placeholder="z. B. Talbach Cola-Mix"></div>'
            . '<div class="field"><span class="label">Status <span class="req">*</span></span>'
            . $this->statusSegmented('identified', false) . '</div>'
            . $this->pictureField('qp', 'Bild')
            . '<button class="btn btn--accent btn--block" type="submit">Spezi hinzufügen</button></form>';
    }

    /**
     * The branded upload control, used everywhere a picture is chosen so the
     * admin never falls back to the unstyled browser file input.
     */
    private function pictureField(string $id, string $label): string
    {
        return '<div class="field"><span class="label">' . $this->escape($label)
            . ' <span class="label__opt">optional</span></span>'
            . '<label class="uploader uploader--sm" for="' . $this->escape($id) . '">'
            . '<input type="file" id="' . $this->escape($id) . '" name="picture" '
            . 'accept="image/jpeg,image/png,image/webp" class="visually-hidden" data-uploader>'
            . '<strong>Foto auswählen</strong><span class="meta" data-uploader-name>JPEG, PNG, WebP</span></label></div>';
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

    private function statusSelect(
        ?string $selected,
        string $name,
        bool $includeAll,
        string $label = '',
        string $id = '',
    ): string {
        $options = $includeAll ? '<option value="">Alle</option>' : '';

        foreach (['identified' => 'Identifiziert', 'acquired' => 'Erworben', 'tested' => 'Getestet'] as $value => $optionLabel) {
            $options .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $this->escape($optionLabel) . '</option>';
        }

        // Row-level status changes submit on change; the noscript button in the
        // markup keeps the form usable without JavaScript.
        $submit = $includeAll ? '' : ' data-autosubmit';

        return '<select class="select select--sm" name="' . $this->escape($name) . '"'
            . ($id !== '' ? ' id="' . $this->escape($id) . '"' : '')
            . ($label !== '' ? ' aria-label="' . $this->escape($label) . '"' : '')
            . $submit . '>' . $options . '</select>';
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
