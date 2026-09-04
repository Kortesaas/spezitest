<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * A filtered, sorted and paginated slice of the catalog for the Spezi browser.
 */
final readonly class CatalogPage
{
    /**
     * @param list<RatedDrink> $items
     */
    public function __construct(
        public array $items,
        public int $totalMatches,
        public int $page,
        public int $pageCount,
        public CatalogQuery $query,
    ) {
    }

    public static function build(RatedDrinkCollection $collection, CatalogQuery $query): self
    {
        $matches = array_values(array_filter(
            $collection->all(),
            static function (RatedDrink $drink) use ($query): bool {
                if ($query->statuses !== [] && !in_array($drink->lifecycleStatus, $query->statuses, true)) {
                    return false;
                }

                if ($query->withImageOnly && !$drink->hasImage) {
                    return false;
                }

                if ($query->search !== '') {
                    $haystack = mb_strtolower($drink->name . ' ' . ($drink->manufacturer ?? '') . ' ' . ($drink->displayOrigin() ?? ''));

                    if (!str_contains($haystack, mb_strtolower($query->search))) {
                        return false;
                    }
                }

                return true;
            },
        ));

        usort($matches, self::comparator($query->sort));

        $total = count($matches);
        $pageCount = max(1, (int) ceil($total / CatalogQuery::PER_PAGE));
        $page = min($query->page, $pageCount);
        $items = array_slice($matches, ($page - 1) * CatalogQuery::PER_PAGE, CatalogQuery::PER_PAGE);

        return new self($items, $total, $page, $pageCount, $query);
    }

    /**
     * @return callable(RatedDrink, RatedDrink): int
     */
    private static function comparator(string $sort): callable
    {
        return match ($sort) {
            'name' => static fn (RatedDrink $a, RatedDrink $b): int => mb_strtolower($a->name) <=> mb_strtolower($b->name),
            'recent' => static fn (RatedDrink $a, RatedDrink $b): int => [$b->updatedAt, mb_strtolower($a->name)] <=> [$a->updatedAt, mb_strtolower($b->name)],
            'worst' => static fn (RatedDrink $a, RatedDrink $b): int => self::byRating($a, $b, false),
            default => static fn (RatedDrink $a, RatedDrink $b): int => self::byRating($a, $b, true),
        };
    }

    private static function byRating(RatedDrink $a, RatedDrink $b, bool $bestFirst): int
    {
        $aTested = $a->isTested();
        $bTested = $b->isTested();

        if ($aTested !== $bTested) {
            return $aTested ? -1 : 1;
        }

        if ($aTested && $bTested && $a->result !== null && $b->result !== null) {
            $comparison = $a->result->gesamt() <=> $b->result->gesamt();

            if ($comparison !== 0) {
                return $bestFirst ? -$comparison : $comparison;
            }
        }

        return mb_strtolower($a->name) <=> mb_strtolower($b->name);
    }
}
