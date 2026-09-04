<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spezitest\Tests\Support\CatalogFixture;
use Spezitest\Website\Catalog\CatalogPage;
use Spezitest\Website\Catalog\CatalogQuery;
use Spezitest\Website\Catalog\RatedDrinkCollection;

final class CatalogPageTest extends TestCase
{
    private function collection(): RatedDrinkCollection
    {
        return new RatedDrinkCollection([
            CatalogFixture::tested('Alpha Spezi', [
                'manu' => [9, 9, 9], 'fabi' => [9, 9, 9], 'schorsch' => [9, 9, 9],
            ], 'Alpha Brauerei', 'Bayern'),
            CatalogFixture::tested('Beta Cola-Mix', [
                'manu' => [4, 4, 4], 'fabi' => [4, 4, 4], 'schorsch' => [4, 4, 4],
            ], 'Beta Quelle', 'Tirol'),
            CatalogFixture::untested('Gamma Mix', 'acquired', 'Gamma GmbH'),
            CatalogFixture::untested('Delta Spezi', 'identified'),
        ]);
    }

    public function testFiltersByLifecycleStatus(): void
    {
        $page = CatalogPage::build(
            $this->collection(),
            CatalogQuery::fromQueryParams(['status' => ['identified']]),
        );

        self::assertSame(1, $page->totalMatches);
        self::assertSame('Delta Spezi', $page->items[0]->name);
    }

    public function testFiltersBySearchAcrossNameManufacturerAndRegion(): void
    {
        $collection = $this->collection();

        self::assertSame(1, CatalogPage::build($collection, CatalogQuery::fromQueryParams(['q' => 'tirol']))->totalMatches);
        self::assertSame(1, CatalogPage::build($collection, CatalogQuery::fromQueryParams(['q' => 'gamma gmbh']))->totalMatches);
    }

    public function testSortsByBestRatingWithTestedDrinksFirst(): void
    {
        $page = CatalogPage::build($this->collection(), CatalogQuery::fromQueryParams(['sort' => 'best']));

        $names = array_map(static fn ($drink): string => $drink->name, $page->items);
        self::assertSame('Alpha Spezi', $names[0]);
        self::assertSame('Beta Cola-Mix', $names[1]);
        self::assertContains('Gamma Mix', $names);
        self::assertContains('Delta Spezi', $names);
        self::assertLessThan(array_search('Gamma Mix', $names, true), array_search('Beta Cola-Mix', $names, true));
    }

    public function testSortByNameIsAlphabetical(): void
    {
        $page = CatalogPage::build($this->collection(), CatalogQuery::fromQueryParams(['sort' => 'name']));

        self::assertSame(
            ['Alpha Spezi', 'Beta Cola-Mix', 'Delta Spezi', 'Gamma Mix'],
            array_map(static fn ($drink): string => $drink->name, $page->items),
        );
    }

    public function testPaginationClampsAndSlices(): void
    {
        $drinks = [];

        for ($i = 1; $i <= 30; ++$i) {
            $drinks[] = CatalogFixture::untested(sprintf('Spezi %02d', $i));
        }

        $collection = new RatedDrinkCollection($drinks);

        $first = CatalogPage::build($collection, CatalogQuery::fromQueryParams([]));
        self::assertCount(CatalogQuery::PER_PAGE, $first->items);
        self::assertSame(2, $first->pageCount);

        $beyond = CatalogPage::build($collection, CatalogQuery::fromQueryParams(['page' => '99']));
        self::assertSame(2, $beyond->page);
        self::assertCount(30 - CatalogQuery::PER_PAGE, $beyond->items);
    }
}
