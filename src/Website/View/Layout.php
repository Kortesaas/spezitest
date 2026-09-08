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
        'streams' => ['/streams', 'Streams'],
        'karte' => ['/karte', 'Karte'],
        'ueber' => ['/ueber', 'Über'],
    ];

    /**
     * Fallback canonical origin. The real value is passed in from configuration
     * (`APP_URL`); this only applies if a caller renders a page without it.
     * Link previews, the canonical tag and the feed all need absolute URLs.
     */
    private const SITE_URL = 'https://www.spezitest.de';

    /** Brand navy (`--navy` in the stylesheet); colours the mobile browser UI. */
    private const THEME_COLOR = '#002D55';

    /**
     * The picture a shared link shows. It has to be JPEG or PNG behind an
     * absolute URL: WhatsApp silently skips SVG and WebP and drops files much
     * over 300 kB. 1200x630 is the format every messenger crops from.
     */
    private const SHARE_IMAGE = '/assets/spezitest-share.png';
    private const SHARE_IMAGE_WIDTH = '1200';
    private const SHARE_IMAGE_HEIGHT = '630';

    private const DEFAULT_DESCRIPTION = 'Cola-Mix und Spezi im Test: Katalog, Ranking und '
        . 'Statistik der Abteilung Spezitest.';

    /**
     * @param ?string $path the page's own absolute path, used for the canonical
     *        URL and the share preview. Pages that should not be shared or
     *        indexed, such as the 404, pass null.
     * @param string $siteUrl canonical origin without a trailing slash
     * @param string $structuredData a ready `<script type="application/ld+json">`
     *        block, or an empty string
     * @param ?string $imagePath a page-specific share image (absolute path on
     *        this site); null uses the default Spezitest card
     * @param string $ogType the Open Graph object type, e.g. `article` for a
     *        single Spezi or Spezistream
     * @param string $headExtra markup appended to <head> (page-specific stylesheets)
     * @param string $bodyEndExtra markup appended just before </body> (page-specific scripts)
     */
    public static function page(
        string $title,
        string $main,
        string $active,
        ?string $description = null,
        ?string $path = null,
        string $siteUrl = self::SITE_URL,
        string $structuredData = '',
        ?string $imagePath = null,
        string $ogType = 'website',
        string $headExtra = '',
        string $bodyEndExtra = '',
    ): string {
        $siteUrl = rtrim($siteUrl, '/');
        $summary = $description ?? self::DEFAULT_DESCRIPTION;
        // The homepage leads with the project name; every other page leads with
        // its own and carries the project as the suffix.
        $shareTitle = $active === 'start' ? 'Spezitest' : $title . ' · Spezitest';
        $canonical = $path === null ? null : $siteUrl . $path;

        return '<!doctype html><html lang="de"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="theme-color" content="' . self::THEME_COLOR . '">'
            . '<title>' . Html::e($title) . ' · Spezitest</title>'
            . '<meta name="description" content="' . Html::e($summary) . '">'
            . ($path === null ? '<meta name="robots" content="noindex">' : '')
            . ($canonical === null ? '' : '<link rel="canonical" href="' . Html::e($canonical) . '">')
            . self::sharePreview($shareTitle, $summary, $canonical, $siteUrl, $imagePath, $ogType)
            . '<link rel="stylesheet" href="/assets/spezitest.css?v=p36">'
            . '<link rel="icon" href="/favicon.ico" sizes="32x32">'
            . '<link rel="icon" href="/assets/spezitest-icon.svg" type="image/svg+xml">'
            . '<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">'
            . '<link rel="manifest" href="/site.webmanifest">'
            . '<link rel="alternate" type="application/atom+xml" title="Spezitest – Neu getestet" href="/feed.xml">'
            . $structuredData
            . $headExtra
            . '</head><body>'
            . '<a class="skip-link" href="#main">Zum Inhalt springen</a>'
            . self::header($active)
            . '<main id="main">' . $main . '</main>'
            . self::footer()
            . '<script src="/assets/spezitest.js?v=p36" defer></script>'
            . $bodyEndExtra
            . '</body></html>';
    }

    /**
     * Open Graph plus the Twitter-card fallback: what WhatsApp, Signal, Slack,
     * Discord and X read when someone pastes a link. Without them a shared
     * link stays a bare URL with no picture.
     */
    private static function sharePreview(
        string $title,
        string $description,
        ?string $canonical,
        string $siteUrl,
        ?string $imagePath,
        string $ogType,
    ): string {
        // A page-specific image (a Spezi's own photo) wins; otherwise the
        // default 1200x630 card, whose dimensions we can safely declare.
        $image = $imagePath === null ? $siteUrl . self::SHARE_IMAGE : $siteUrl . $imagePath;

        $tags = [
            'og:type' => $ogType,
            'og:site_name' => 'Spezitest',
            'og:locale' => 'de_DE',
            'og:title' => $title,
            'og:description' => $description,
            'og:image' => $image,
        ];

        if ($imagePath === null) {
            $tags['og:image:type'] = 'image/png';
            $tags['og:image:width'] = self::SHARE_IMAGE_WIDTH;
            $tags['og:image:height'] = self::SHARE_IMAGE_HEIGHT;
            $tags['og:image:alt'] = 'Spezitest: Cola-Mix im Test';
        } else {
            $tags['og:image:alt'] = $title;
        }

        if ($canonical !== null) {
            $tags['og:url'] = $canonical;
        }

        $html = '';

        foreach ($tags as $property => $content) {
            $html .= '<meta property="' . $property . '" content="' . Html::e($content) . '">';
        }

        // X and a few other readers only look at the name-based variants.
        return $html
            . '<meta name="twitter:card" content="summary_large_image">'
            . '<meta name="twitter:title" content="' . Html::e($title) . '">'
            . '<meta name="twitter:description" content="' . Html::e($description) . '">'
            . '<meta name="twitter:image" content="' . Html::e($image) . '">';
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
            . '<div class="stack"><img src="/assets/spezitest-logo-white.svg" alt="Spezitest" width="120" height="28">'
            . '<p style="font-size:var(--fs-sm);color:rgba(255,255,255,.8);max-width:34ch">'
            . 'Cola-Mix aus Deutschland und den Nachbarländern im Test. Ein privates Hobbyprojekt '
            . 'ohne kommerzielles Interesse.</p></div>'
            . '<div class="grid grid--3" style="gap:var(--sp-5)">'
            . '<div><h3>Katalog</h3><ul class="stack-sm">'
            . '<li><a href="/spezis">Alle Spezis</a></li><li><a href="/ranking">Ranking</a></li>'
            . '<li><a href="/statistik">Statistik</a></li><li><a href="/streams">Streams</a></li>'
            . '<li><a href="/karte">Karte</a></li></ul></div>'
            . '<div><h3>Projekt</h3><ul class="stack-sm">'
            . '<li><a href="/ueber">Über Spezitest</a></li><li><a href="/ueber#methode">Testmethode</a></li><li><a href="/ueber#tester">Tester</a></li></ul></div>'
            . '<div><h3>Rechtliches</h3><ul class="stack-sm">'
            . '<li><a href="/impressum">Impressum</a></li><li><a href="/datenschutz">Datenschutz</a></li>'
            . '<li><a href="/admin">Verwaltung</a></li></ul></div>'
            . '</div></div>'
            . '<hr class="rule" style="margin-block:var(--sp-6)">'
            . '<p style="font-size:var(--fs-xs);color:rgba(255,255,255,.7);max-width:80ch">spezitest.de · Beta · '
            . 'Nicht kommerzielles Fan-Projekt. „Spezi“ und alle genannten Marken- und Produktnamen gehören '
            . 'ihren jeweiligen Inhabern; wir stehen mit keinem der Hersteller in Verbindung. '
            . '<a href="/impressum#marken">Hinweise zu Marken</a></p>'
            . '</div></footer>';
    }
}
