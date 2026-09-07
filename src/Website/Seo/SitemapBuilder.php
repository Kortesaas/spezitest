<?php

declare(strict_types=1);

namespace Spezitest\Website\Seo;

use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\Catalog\StreamEpisode;

/**
 * Builds `/sitemap.xml` from live catalog data: the fixed public pages, one
 * entry per Spezi (whatever its lifecycle state — every drink has a detail
 * page), and one per Spezistream. `lastmod` comes from the record's own
 * timestamp so search engines only re-crawl what actually changed.
 */
final readonly class SitemapBuilder
{
    private string $siteUrl;

    /** @var list<string> paths that always exist, most important first */
    private const STATIC_PATHS = [
        '/',
        '/spezis',
        '/ranking',
        '/statistik',
        '/streams',
        '/ueber',
        '/impressum',
        '/datenschutz',
    ];

    public function __construct(string $siteUrl)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @param list<StreamEpisode> $episodes
     */
    public function build(RatedDrinkCollection $drinks, array $episodes): string
    {
        $urls = '';

        foreach (self::STATIC_PATHS as $path) {
            $urls .= $this->url($path);
        }

        foreach ($drinks->all() as $drink) {
            $urls .= $this->url('/spezi/' . $drink->slug(), self::lastModified($drink));
        }

        foreach ($episodes as $episode) {
            $urls .= $this->url('/streams/' . $episode->number, self::day($episode->recordedOn));
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $urls
            . '</urlset>' . "\n";
    }

    private function url(string $path, ?string $lastmod = null): string
    {
        $loc = self::escape($this->siteUrl . $path);
        $line = '  <url><loc>' . $loc . '</loc>';

        if ($lastmod !== null) {
            $line .= '<lastmod>' . $lastmod . '</lastmod>';
        }

        return $line . '</url>' . "\n";
    }

    private static function lastModified(RatedDrink $drink): ?string
    {
        return self::day($drink->testedAt) ?? self::day($drink->updatedAt);
    }

    /** A timestamp reduced to a `YYYY-MM-DD` date, or null when unparseable. */
    private static function day(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        $time = strtotime($timestamp);

        return $time === false ? null : date('Y-m-d', $time);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
