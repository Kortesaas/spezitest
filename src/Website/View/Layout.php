<?php

declare(strict_types=1);

namespace Spezitest\Website\View;

/**
 * The shared public-site chrome: document head, sticky header with the primary
 * navigation, and the navy footer. Structure and class names follow the
 * Spezitest Design System previews.
 */
final class Layout
{
    private const NAV = [
        'start' => ['/', 'Start'],
        'spezis' => ['/spezis', 'Spezis'],
        'ranking' => ['/ranking', 'Ranking'],
        'statistik' => ['/statistik', 'Statistik'],
        'ueber' => ['/ueber', 'Über'],
    ];

    public static function page(string $title, string $main, string $active, ?string $description = null): string
    {
        $descriptionMeta = $description === null
            ? ''
            : '<meta name="description" content="' . Html::e($description) . '">';

        return '<!doctype html><html lang="de"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Html::e($title) . ' · Spezitest</title>'
            . $descriptionMeta
            . '<link rel="stylesheet" href="/assets/spezitest.css">'
            . '<link rel="icon" href="/assets/spezitest-icon.svg" type="image/svg+xml">'
            . '</head><body>'
            . '<a class="skip-link" href="#main">Zum Inhalt springen</a>'
            . self::header($active)
            . '<main id="main">' . $main . '</main>'
            . self::footer()
            . '<script src="/assets/spezitest.js" defer></script>'
            . '</body></html>';
    }

    private static function header(string $active): string
    {
        $desktop = '';
        $mobile = '';

        foreach (self::NAV as $key => [$href, $label]) {
            $current = $key === $active ? ' aria-current="page"' : '';
            $desktop .= '<a href="' . $href . '"' . $current . '>' . Html::e($label) . '</a>';
            $mobile .= '<a href="' . $href . '"' . $current . '>' . Html::e($label) . '</a>';
        }

        return '<header class="site-header"><div class="wrap site-header__inner">'
            . '<a class="brand" href="/"><img src="/assets/spezitest-logo-color.svg" alt="Spezitest" width="150" height="35"></a>'
            . '<nav class="nav" aria-label="Hauptnavigation">' . $desktop . '</nav>'
            . '<button class="nav-toggle" type="button" data-toggle="mnav" aria-expanded="false" aria-controls="mnav">Menü</button>'
            . '</div>'
            . '<nav class="mobile-nav" id="mnav" hidden aria-label="Hauptnavigation mobil">' . $mobile . '</nav>'
            . '</header>';
    }

    private static function footer(): string
    {
        return '<footer class="site-footer"><div class="wrap">'
            . '<div class="split" style="gap:var(--sp-6)">'
            . '<div class="stack"><img src="/assets/spezitest-logo-white.svg" alt="Abteilung Spezitest" width="120" height="28">'
            . '<p style="font-size:var(--fs-sm);color:rgba(255,255,255,.8);max-width:36ch">'
            . 'Wir identifizieren, erwerben und testen Cola-Mix-Getränke aus Deutschland und den Nachbarländern.</p></div>'
            . '<div class="grid grid--3" style="gap:var(--sp-5)">'
            . '<div><h3>Katalog</h3><ul class="stack-sm">'
            . '<li><a href="/spezis">Alle Spezis</a></li><li><a href="/ranking">Ranking</a></li><li><a href="/statistik">Statistik</a></li></ul></div>'
            . '<div><h3>Projekt</h3><ul class="stack-sm">'
            . '<li><a href="/ueber">Über Spezitest</a></li><li><a href="/ueber#methode">Testmethode</a></li><li><a href="/ueber#tester">Tester</a></li></ul></div>'
            . '<div><h3>Intern</h3><ul class="stack-sm"><li><a href="/admin">Verwaltung</a></li></ul></div>'
            . '</div></div>'
            . '<hr class="rule" style="margin-block:var(--sp-6)">'
            . '<p style="font-size:var(--fs-xs);color:rgba(255,255,255,.7)">spezitest.de · Beta</p>'
            . '</div></footer>';
    }
}
