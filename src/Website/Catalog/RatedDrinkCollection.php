<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * An in-memory collection of {@see RatedDrink}s with the read-side helpers the
 * public pages need (ranking order, lifecycle counts, recent activity).
 */
final readonly class RatedDrinkCollection
{
    /** @var list<RatedDrink> */
    private array $drinks;

    /** @param list<RatedDrink> $drinks */
    public function __construct(array $drinks)
    {
        $this->drinks = $drinks;
    }

    /** @return list<RatedDrink> */
    public function all(): array
    {
        return $this->drinks;
    }

    public function count(): int
    {
        return count($this->drinks);
    }

    public function isEmpty(): bool
    {
        return $this->drinks === [];
    }

    public function find(int $id): ?RatedDrink
    {
        foreach ($this->drinks as $drink) {
            if ($drink->id === $id) {
                return $drink;
            }
        }

        return null;
    }

    /** @return list<RatedDrink> */
    public function tested(): array
    {
        return array_values(array_filter(
            $this->drinks,
            static fn (RatedDrink $drink): bool => $drink->isTested(),
        ));
    }

    /**
     * Tested drinks ordered by rank (best first); ties keep a stable order by name.
     *
     * @return list<RatedDrink>
     */
    public function ranked(): array
    {
        $ranked = $this->tested();
        usort($ranked, static function (RatedDrink $a, RatedDrink $b): int {
            return [$a->rank ?? PHP_INT_MAX, mb_strtolower($a->name)]
                <=> [$b->rank ?? PHP_INT_MAX, mb_strtolower($b->name)];
        });

        return $ranked;
    }

    /** @return array{identified: int, acquired: int, tested: int} */
    public function lifecycleCounts(): array
    {
        $counts = ['identified' => 0, 'acquired' => 0, 'tested' => 0];

        foreach ($this->drinks as $drink) {
            if (array_key_exists($drink->lifecycleStatus, $counts)) {
                ++$counts[$drink->lifecycleStatus];
            }
        }

        return $counts;
    }

    /**
     * Most recently updated drinks first.
     *
     * @return list<RatedDrink>
     */
    public function recent(int $limit): array
    {
        $recent = $this->drinks;
        usort(
            $recent,
            static fn (RatedDrink $a, RatedDrink $b): int => strcmp($b->updatedAt, $a->updatedAt),
        );

        return array_slice($recent, 0, max(0, $limit));
    }
}
