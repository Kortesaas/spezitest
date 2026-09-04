<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

final class HtmlRenderer
{
    public function login(string $csrfToken, ?string $error = null): string
    {
        $errorHtml = $error === null ? '' : '<p role="alert">' . $this->escape($error) . '</p>';

        return $this->layout(
            'Anmeldung',
            '<h1>Spezitest-Administration</h1>'
            . $errorHtml
            . '<form method="post" action="/admin/login">'
            . $this->csrfField($csrfToken)
            . '<label>Benutzername <input name="username" autocomplete="username" required></label>'
            . '<label>Passwort <input type="password" name="password" autocomplete="current-password" required></label>'
            . '<button type="submit">Anmelden</button>'
            . '</form>',
            false,
        );
    }

    /** @param array{identified: int, acquired: int, tested: int} $counts */
    public function dashboard(array $counts, string $csrfToken): string
    {
        return $this->layout(
            'Übersicht',
            '<h1>Übersicht</h1><dl class="counts">'
            . '<div><dt>Identifiziert</dt><dd>' . $counts['identified'] . '</dd></div>'
            . '<div><dt>Beschafft</dt><dd>' . $counts['acquired'] . '</dd></div>'
            . '<div><dt>Getestet</dt><dd>' . $counts['tested'] . '</dd></div>'
            . '</dl>',
            true,
            $csrfToken,
        );
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
                ? '<img class="thumb" src="/admin/drinks/' . $id . '/image" alt="">'
                : '<span aria-label="Kein Bild">–</span>';
            $manufacturer = $drink['manufacturer'] === null ? '–' : $this->escape($drink['manufacturer']);
            $rows .= '<tr><td>' . $image . '</td><td>' . $this->escape($drink['name']) . '</td>'
                . '<td>' . $manufacturer . '</td><td>' . $this->statusLabel($drink['lifecycle_status']) . '</td>'
                . '<td><a href="/admin/drinks/' . $id . '/edit">Bearbeiten</a> '
                . '<a href="/admin/drinks/' . $id . '/delete">Löschen</a>'
                . '<form class="inline" method="post" action="/admin/drinks/' . $id . '/status">'
                . $this->csrfField($csrfToken)
                . $this->statusSelect($drink['lifecycle_status'])
                . '<button type="submit">Status ändern</button></form></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">Keine Getränke gefunden.</td></tr>';
        }

        $errorHtml = $error === null ? '' : '<p role="alert">' . $this->escape($error) . '</p>';

        return $this->layout(
            'Getränke',
            '<h1>Getränke</h1>' . $errorHtml
            . '<p><a href="/admin/drinks/new">Neues Getränk</a></p>'
            . '<form method="get" action="/admin/drinks">'
            . '<label>Suche <input name="q" value="' . $this->escape($search) . '"></label>'
            . '<label>Status ' . $this->statusSelect($status, true) . '</label>'
            . '<button type="submit">Filtern</button></form>'
            . '<table><thead><tr><th>Bild</th><th>Name</th><th>Hersteller</th><th>Status</th><th>Aktionen</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>',
            true,
            $csrfToken,
        );
    }

    /** @param array<array-key, mixed> $values */
    public function createForm(string $csrfToken, array $values = [], ?string $error = null): string
    {
        return $this->layout(
            'Getränk anlegen',
            '<h1>Getränk anlegen</h1>'
            . $this->error($error)
            . '<form method="post" action="/admin/drinks" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<label>Name * <input name="name" maxlength="255" required value="' . $this->value($values, 'name') . '"></label>'
            . '<label>Status * ' . $this->statusSelect($this->stringValue($values, 'lifecycle_status') ?? 'identified', false, false) . '</label>'
            . '<label>Bild (optional) <input type="file" name="picture" accept="image/jpeg,image/png,image/webp"></label>'
            . '<button type="submit">Anlegen</button></form>',
            true,
            $csrfToken,
        );
    }

    /**
     * @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} $drink
     */
    public function editForm(
        array $drink,
        bool $hasImage,
        string $csrfToken,
        ?string $error = null,
    ): string {
        $id = $drink['id'];
        $image = $hasImage
            ? '<img class="preview" src="/admin/drinks/' . $id . '/image" alt="Primärbild">'
                . '<label><input type="checkbox" name="remove_image" value="1"> Vorhandenes Bild entfernen</label>'
            : '<p>Kein Bild vorhanden.</p>';

        return $this->layout(
            'Getränk bearbeiten',
            '<h1>Getränk bearbeiten</h1>'
            . $this->error($error)
            . '<form method="post" action="/admin/drinks/' . $id . '" enctype="multipart/form-data">'
            . $this->csrfField($csrfToken)
            . '<label>Name * <input name="name" maxlength="255" required value="' . $this->escape($drink['name']) . '"></label>'
            . '<label>Status * ' . $this->statusSelect($drink['lifecycle_status']) . '</label>'
            . '<label>Hersteller <input name="manufacturer" maxlength="255" value="' . $this->escape($drink['manufacturer'] ?? '') . '"></label>'
            . '<label>Ort <input name="origin_location" maxlength="255" value="' . $this->escape($drink['origin_location'] ?? '') . '"></label>'
            . '<label>Region <input name="origin_region" maxlength="128" value="' . $this->escape($drink['origin_region'] ?? '') . '"></label>'
            . '<label>Notizen <textarea name="notes">' . $this->escape($drink['notes'] ?? '') . '</textarea></label>'
            . $image
            . '<label>Neues Bild <input type="file" name="picture" accept="image/jpeg,image/png,image/webp"></label>'
            . '<button type="submit">Speichern</button></form>',
            true,
            $csrfToken,
        );
    }

    /** @param array{id: int, name: string, lifecycle_status: string, manufacturer: ?string, origin_location: ?string, origin_region: ?string, notes: ?string} $drink */
    public function deleteConfirmation(array $drink, string $csrfToken, ?string $error = null): string
    {
        $id = $drink['id'];

        return $this->layout(
            'Getränk löschen',
            '<h1>Getränk löschen</h1>' . $this->error($error)
            . '<p>Soll „' . $this->escape($drink['name']) . '“ wirklich gelöscht werden?</p>'
            . '<form method="post" action="/admin/drinks/' . $id . '/delete">'
            . $this->csrfField($csrfToken)
            . '<button type="submit">Endgültig löschen</button> '
            . '<a href="/admin/drinks">Abbrechen</a></form>',
            true,
            $csrfToken,
        );
    }

    public function notFound(string $csrfToken): string
    {
        return $this->layout(
            'Nicht gefunden',
            '<h1>Nicht gefunden</h1><p>Das Getränk wurde nicht gefunden.</p>',
            true,
            $csrfToken,
        );
    }

    private function layout(string $title, string $content, bool $authenticated, string $csrfToken = ''): string
    {
        $navigation = '';

        if ($authenticated) {
            $navigation = '<nav><a href="/admin">Übersicht</a> <a href="/admin/drinks">Getränke</a>'
                . '<form class="inline" method="post" action="/admin/logout">'
                . $this->csrfField($csrfToken)
                . '<button type="submit">Abmelden</button></form></nav>';
        }

        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->escape($title) . ' – Spezitest</title>'
            . '<style>body{font:16px/1.4 system-ui,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem}'
            . 'nav{margin-bottom:2rem}label{display:block;margin:.75rem 0}input,select,textarea,button{font:inherit}'
            . 'textarea{display:block;width:min(100%,40rem);min-height:8rem}.inline{display:inline;margin-left:.5rem}'
            . 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:.5rem;text-align:left}'
            . '.thumb{width:64px;height:64px;object-fit:contain}.preview{display:block;max-width:240px;max-height:240px}'
            . '.counts{display:flex;gap:2rem}.counts div{border:1px solid #ccc;padding:1rem}.counts dd{font-size:2rem;margin:0}</style>'
            . '</head><body>' . $navigation . '<main>' . $content . '</main></body></html>';
    }

    private function csrfField(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->escape($token) . '">';
    }

    private function statusSelect(
        ?string $selected,
        bool $includeAll = false,
        bool $includeTested = true,
    ): string
    {
        $options = $includeAll ? '<option value="">Alle</option>' : '';

        foreach (['identified' => 'Identifiziert', 'acquired' => 'Beschafft', 'tested' => 'Getestet'] as $value => $label) {
            if (!$includeTested && $value === 'tested') {
                $options .= '<option value="tested" disabled>Getestet (nach Testerfassung)</option>';

                continue;
            }

            $options .= '<option value="' . $value . '"'
                . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
        }

        return '<select name="lifecycle_status">' . $options . '</select>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'identified' => 'Identifiziert',
            'acquired' => 'Beschafft',
            'tested' => 'Getestet',
            default => 'Unbekannt',
        };
    }

    private function error(?string $error): string
    {
        return $error === null ? '' : '<p role="alert">' . $this->escape($error) . '</p>';
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
