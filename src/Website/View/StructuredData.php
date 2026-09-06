<?php

declare(strict_types=1);

namespace Spezitest\Website\View;

use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\StreamEpisode;

/**
 * Builds the JSON-LD (schema.org) blocks the public pages embed in their head.
 *
 * Search engines use these to understand that the site is an editorial review
 * project: each tested Spezi is a {@see https://schema.org/Product} carrying the
 * combined Spezitest verdict as a {@see https://schema.org/Review}, which is
 * what can earn a star snippet in results. Nothing here invents data — a drink
 * only gets a rating node when it has a completed test, and the value is the
 * verified Gesamtwertung on its real 0–60 scale.
 *
 * @phpstan-type Node array<string, mixed>
 */
final class StructuredData
{
    private const GESAMT_MAX = 60;

    /** The organisation + website nodes every page shares. */
    private string $siteUrl;

    public function __construct(string $siteUrl)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * Wraps the shared Organization/WebSite graph plus any page-specific nodes
     * in a single `<script type="application/ld+json">`. Empty page nodes are
     * dropped, so callers can pass conditional builders straight through.
     *
     * @param list<array<string, mixed>> $nodes
     */
    public function script(array $nodes): string
    {
        $nodes = array_values(array_filter($nodes, static fn (array $node): bool => $node !== []));

        $graph = array_merge($this->baseGraph(), $nodes);

        // Slashes stay escaped (no JSON_UNESCAPED_SLASHES): that turns any
        // "</script>" in the data into "<\/script>", so the block cannot be
        // broken out of. Unicode stays readable for anyone viewing source.
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Breadcrumb trail matching the one shown on the page.
     *
     * @param list<array{name: string, path: ?string}> $items last item is the
     *        current page and carries a null path
     * @return array<string, mixed>
     */
    public function breadcrumb(array $items): array
    {
        $elements = [];
        $position = 1;

        foreach ($items as $item) {
            $element = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $item['name'],
            ];

            if ($item['path'] !== null) {
                $element['item'] = $this->absolute($item['path']);
            }

            $elements[] = $element;
            ++$position;
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /**
     * A tested Spezi as a reviewed product; an untested one as a plain catalog
     * entry with no rating.
     *
     * @return array<string, mixed>
     */
    public function drink(RatedDrink $drink, int $testedCount): array
    {
        $url = $this->absolute('/spezi/' . $drink->slug());

        $node = [
            '@type' => 'Product',
            '@id' => $url . '#product',
            'name' => $drink->name,
            'category' => 'Cola-Mix',
            'url' => $url,
        ];

        if ($drink->manufacturer !== null && $drink->manufacturer !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $drink->manufacturer];
        }

        if ($drink->hasImage) {
            $node['image'] = $this->absolute('/spezi/' . $drink->id . '/bild');
        }

        $result = $drink->result;

        if ($drink->isTested() && $result !== null) {
            // schema.org ratings use a decimal point, never a locale comma.
            $ratingValue = round($result->gesamt(), 2);

            $rating = [
                '@type' => 'Rating',
                'ratingValue' => $ratingValue,
                'worstRating' => 0,
                'bestRating' => self::GESAMT_MAX,
            ];

            $review = [
                '@type' => 'Review',
                'reviewRating' => $rating,
                'author' => ['@type' => 'Organization', 'name' => 'Spezitest', '@id' => $this->siteUrl . '/#organization'],
                'publisher' => ['@id' => $this->siteUrl . '/#organization'],
            ];

            $published = $this->date($drink->testedAt);

            if ($published !== null) {
                $review['datePublished'] = $published;
            }

            $node['review'] = $review;
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $ratingValue,
                'worstRating' => 0,
                'bestRating' => self::GESAMT_MAX,
                'ratingCount' => 3,
                'reviewCount' => 1,
            ];
        }

        return $node;
    }

    /**
     * The recording of a Testabend, when its video address is on file.
     *
     * @return array<string, mixed>
     */
    public function streamEpisode(StreamEpisode $episode): array
    {
        if ($episode->url === null || $episode->url === '') {
            return [];
        }

        $node = [
            '@type' => 'VideoObject',
            'name' => $episode->title,
            'description' => $episode->count() . ' Spezis im Test, verkostet und bewertet.',
            'contentUrl' => $episode->url,
            'url' => $this->absolute('/streams/' . $episode->number),
        ];

        $youtubeId = self::youtubeId($episode->url);

        if ($youtubeId !== null) {
            $node['thumbnailUrl'] = 'https://i.ytimg.com/vi/' . $youtubeId . '/hqdefault.jpg';
            $node['embedUrl'] = 'https://www.youtube.com/embed/' . $youtubeId;
        }

        $uploadDate = $this->date($episode->recordedOn);

        if ($uploadDate !== null) {
            $node['uploadDate'] = $uploadDate;
        }

        if ($episode->tastingSeconds !== null && $episode->tastingSeconds > 0) {
            $node['duration'] = 'PT' . $episode->tastingSeconds . 'S';
        }

        return $node;
    }

    /**
     * An ordered list of drink pages, best first — used on the ranking page.
     *
     * @param list<RatedDrink> $drinks
     * @return array<string, mixed>
     */
    public function ranking(array $drinks): array
    {
        if ($drinks === []) {
            return [];
        }

        $elements = [];
        $position = 1;

        foreach ($drinks as $drink) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'url' => $this->absolute('/spezi/' . $drink->slug()),
                'name' => $drink->name,
            ];
            ++$position;
        }

        return [
            '@type' => 'ItemList',
            'name' => 'Spezitest-Ranking',
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function baseGraph(): array
    {
        return [
            [
                '@type' => 'Organization',
                '@id' => $this->siteUrl . '/#organization',
                'name' => 'Spezitest',
                'url' => $this->siteUrl . '/',
                'description' => 'Privates, nicht kommerzielles Testprojekt für Cola-Mix aus Deutschland '
                    . 'und den Nachbarländern.',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $this->absolute('/assets/icon-512.png'),
                    'width' => 512,
                    'height' => 512,
                ],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $this->siteUrl . '/#website',
                'url' => $this->siteUrl . '/',
                'name' => 'Spezitest',
                'inLanguage' => 'de',
                'publisher' => ['@id' => $this->siteUrl . '/#organization'],
            ],
        ];
    }

    private function absolute(string $path): string
    {
        return $this->siteUrl . '/' . ltrim($path, '/');
    }

    /** A timestamp reduced to a W3C calendar date, or null when unparseable. */
    private function date(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        $time = strtotime($timestamp);

        return $time === false ? null : date('Y-m-d', $time);
    }

    private static function youtubeId(string $url): ?string
    {
        if (preg_match('~[?&]v=([A-Za-z0-9_-]{11})~', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('~(?:youtu\.be/|/embed/|/shorts/|/live/)([A-Za-z0-9_-]{11})~', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
