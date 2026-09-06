<?php

declare(strict_types=1);

namespace Spezitest\Website\Seo;

use Spezitest\Website\Catalog\RatedDrink;
use Spezitest\Website\Catalog\RatedDrinkCollection;
use Spezitest\Website\View\Html;

/**
 * Builds the Atom feed at `/feed.xml`: the most recently tested Spezis, newest
 * first, one entry per completed test. It lets people follow the verdicts in a
 * reader without a social-media account and without the site setting a cookie.
 */
final readonly class FeedBuilder
{
    private const LIMIT = 20;

    private const GESAMT_MAX = 60;

    private string $siteUrl;

    public function __construct(string $siteUrl)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    public function build(RatedDrinkCollection $drinks): string
    {
        $tested = $drinks->tested();
        usort(
            $tested,
            static fn (RatedDrink $a, RatedDrink $b): int => [$b->testedAt ?? '', $b->updatedAt, $b->id]
                <=> [$a->testedAt ?? '', $a->updatedAt, $a->id],
        );
        $tested = array_slice($tested, 0, self::LIMIT);

        $newest = $tested[0] ?? null;
        $updated = ($newest === null ? null : self::timestamp($newest->testedAt ?? $newest->updatedAt))
            ?? date(DATE_ATOM);

        $entries = '';

        foreach ($tested as $drink) {
            $entries .= $this->entry($drink);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n"
            . '  <title>Spezitest – Neu getestet</title>' . "\n"
            . '  <subtitle>Die zuletzt getesteten Cola-Mix-Getränke, mit Gesamtwertung.</subtitle>' . "\n"
            . '  <link href="' . self::escape($this->siteUrl . '/feed.xml') . '" rel="self"/>' . "\n"
            . '  <link href="' . self::escape($this->siteUrl . '/') . '"/>' . "\n"
            . '  <id>' . self::escape($this->siteUrl . '/#feed') . '</id>' . "\n"
            . '  <updated>' . $updated . '</updated>' . "\n"
            . '  <author><name>Spezitest</name></author>' . "\n"
            . $entries
            . '</feed>' . "\n";
    }

    private function entry(RatedDrink $drink): string
    {
        $url = $this->siteUrl . '/spezi/' . $drink->slug();
        $result = $drink->result;
        $score = $result === null
            ? $drink->name
            : $drink->name . ' – ' . Html::grade($result->gesamt()) . ' / ' . self::GESAMT_MAX;

        $summaryParts = array_values(array_filter([
            $drink->manufacturer,
            $drink->displayOrigin(),
            $result === null ? null : 'Gesamtwertung ' . Html::grade($result->gesamt()) . ' von ' . self::GESAMT_MAX,
        ]));
        $summary = $summaryParts === [] ? $drink->name : implode(' · ', $summaryParts);

        $updated = self::timestamp($drink->testedAt ?? $drink->updatedAt) ?? date(DATE_ATOM);

        return '  <entry>' . "\n"
            . '    <title>' . self::escape($score) . '</title>' . "\n"
            . '    <link href="' . self::escape($url) . '"/>' . "\n"
            . '    <id>' . self::escape($url) . '</id>' . "\n"
            . '    <updated>' . $updated . '</updated>' . "\n"
            . '    <summary>' . self::escape($summary) . '</summary>' . "\n"
            . '  </entry>' . "\n";
    }

    private static function timestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date(DATE_ATOM, $time);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
