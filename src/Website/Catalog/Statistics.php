<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

use Spezitest\Domain\Rating\PriceNormalizer;

/**
 * Figures that can be derived reliably from the current database contents.
 *
 * Everything here is computed from real rows only. When there are no tested
 * drinks the rating figures are null and the page shows an empty state rather
 * than an invented number.
 */
final readonly class Statistics
{
    /**
     * @param array{identified: int, acquired: int, tested: int} $lifecycleCounts
     * @param array{optik: ?float, sueffigkeit: ?float, geschmack: ?float} $averageByCategory
     * @param array{optik: ?array{name: string, value: float}, sueffigkeit: ?array{name: string, value: float}, geschmack: ?array{name: string, value: float}} $bestByCategory
     * @param array<string, ?float> $testerAverages Keyed by tester code.
     * @param list<array{label: string, count: int, drinks: list<array{name: string, slug: string, gesamt: float}>}> $gesamtDistribution
     * @param list<array{region: string, count: int}> $regionCounts
     * @param list<array{name: string, count: int, averageGesamt: ?float, best: ?array{name: string, gesamt: float}}> $manufacturers
     * @param ?array{name: string, slug: string, score: float, gesamt: float, price: float} $bestValue
     * @param list<array{name: string, slug: string, price: float, gesamt: float, score: ?float}> $priceScatter
     */
    public function __construct(
        public int $total,
        public array $lifecycleCounts,
        public int $testedCount,
        public ?float $averageGesamt,
        public array $averageByCategory,
        public array $bestByCategory,
        public array $testerAverages,
        public array $gesamtDistribution,
        public array $regionCounts,
        public array $manufacturers,
        public int $pricedCount,
        public ?float $averagePriceHalfLiter,
        public ?array $bestValue,
        public array $priceScatter,
    ) {
    }

    public static function fromCollection(RatedDrinkCollection $collection): self
    {
        $tested = $collection->tested();
        $counts = $collection->lifecycleCounts();

        $gesamtValues = [];
        $categoryValues = ['optik' => [], 'sueffigkeit' => [], 'geschmack' => []];
        $bestByCategory = ['optik' => null, 'sueffigkeit' => null, 'geschmack' => null];
        $testerTotals = [];
        $distribution = array_fill(0, 6, 0);
        /** @var list<list<array{name: string, slug: string, gesamt: float}>> $distributionDrinks */
        $distributionDrinks = array_fill(0, 6, []);
        $priceNormalizer = new PriceNormalizer();
        $priceScatter = [];
        $priceValues = [];
        /** @var ?array{name: string, slug: string, score: float, gesamt: float, price: float} $bestValue */
        $bestValue = null;

        foreach ($tested as $drink) {
            $result = $drink->result;

            if ($result === null) {
                continue;
            }

            $gesamtValues[] = $result->gesamt();

            $byCategory = [
                'optik' => $result->optikAverage(),
                'sueffigkeit' => $result->sueffigkeitAverage(),
                'geschmack' => $result->geschmackAverage(),
            ];

            foreach ($byCategory as $category => $value) {
                $categoryValues[$category][] = $value;
                $current = $bestByCategory[$category];

                if ($current === null || $value > $current['value']) {
                    $bestByCategory[$category] = ['name' => $drink->name, 'value' => $value];
                }
            }

            $bin = min(5, (int) ($result->gesamt() / 10));
            ++$distribution[$bin];
            $distributionDrinks[$bin][] = ['name' => $drink->name, 'slug' => $drink->slug(), 'gesamt' => $result->gesamt()];

            foreach ($drink->testerGrades as $code => $grades) {
                $testerTotals[$code] ??= ['sum' => 0.0, 'count' => 0];
                $testerTotals[$code]['sum'] += (float) $grades['optik'] + (float) $grades['sueffigkeit'] + (float) $grades['geschmack'];
                $testerTotals[$code]['count'] += 3;
            }

            if ($drink->priceAmount !== null && $drink->priceVolumeMl !== null) {
                $pricePerHalfLiter = $priceNormalizer->perReferenceVolume($drink->priceAmount, $drink->priceVolumeMl);
                $priceValues[] = $pricePerHalfLiter;
                $score = $drink->pricePerformance?->normalized();

                $priceScatter[] = [
                    'name' => $drink->name,
                    'slug' => $drink->slug(),
                    'price' => $pricePerHalfLiter,
                    'gesamt' => $result->gesamt(),
                    'score' => $score,
                ];

                if ($score !== null && ($bestValue === null || $score > $bestValue['score'])) {
                    $bestValue = [
                        'name' => $drink->name,
                        'slug' => $drink->slug(),
                        'score' => $score,
                        'gesamt' => $result->gesamt(),
                        'price' => $pricePerHalfLiter,
                    ];
                }
            }
        }

        $testerAverages = [];

        foreach (['manu', 'fabi', 'schorsch'] as $code) {
            $entry = $testerTotals[$code] ?? null;
            $testerAverages[$code] = $entry !== null
                ? $entry['sum'] / $entry['count']
                : null;
        }

        $gesamtDistribution = [];

        foreach ($distribution as $index => $count) {
            $binDrinks = $distributionDrinks[$index];
            usort($binDrinks, static fn (array $a, array $b): int => $b['gesamt'] <=> $a['gesamt']);
            $gesamtDistribution[] = [
                'label' => sprintf('%d–%d', $index * 10, ($index + 1) * 10),
                'count' => $count,
                'drinks' => $binDrinks,
            ];
        }

        return new self(
            $collection->count(),
            $counts,
            count($tested),
            self::mean($gesamtValues),
            [
                'optik' => self::mean($categoryValues['optik']),
                'sueffigkeit' => self::mean($categoryValues['sueffigkeit']),
                'geschmack' => self::mean($categoryValues['geschmack']),
            ],
            $bestByCategory,
            $testerAverages,
            $gesamtDistribution,
            self::regionCounts($collection),
            self::manufacturers($collection),
            count($priceScatter),
            self::mean($priceValues),
            $bestValue,
            $priceScatter,
        );
    }

    /**
     * @param list<float> $values
     */
    private static function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @return list<array{region: string, count: int}>
     */
    private static function regionCounts(RatedDrinkCollection $collection): array
    {
        $counts = [];

        foreach ($collection->all() as $drink) {
            $region = $drink->displayOrigin();

            if ($region === null || $region === '') {
                continue;
            }

            $counts[$region] = ($counts[$region] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];

        foreach ($counts as $region => $count) {
            $result[] = ['region' => (string) $region, 'count' => $count];
        }

        return $result;
    }

    /**
     * @return list<array{name: string, count: int, averageGesamt: ?float, best: ?array{name: string, gesamt: float}}>
     */
    private static function manufacturers(RatedDrinkCollection $collection): array
    {
        /** @var array<string, list<RatedDrink>> $byManufacturer */
        $byManufacturer = [];

        foreach ($collection->all() as $drink) {
            $manufacturer = $drink->manufacturer;

            if ($manufacturer === null || $manufacturer === '') {
                continue;
            }

            $byManufacturer[$manufacturer][] = $drink;
        }

        $result = [];

        foreach ($byManufacturer as $name => $drinks) {
            if (count($drinks) < 2) {
                continue;
            }

            $gesamtValues = [];
            $best = null;

            foreach ($drinks as $drink) {
                if ($drink->result === null) {
                    continue;
                }

                $gesamt = $drink->result->gesamt();
                $gesamtValues[] = $gesamt;

                if ($best === null || $gesamt > $best['gesamt']) {
                    $best = ['name' => $drink->name, 'gesamt' => $gesamt];
                }
            }

            $result[] = [
                'name' => (string) $name,
                'count' => count($drinks),
                'averageGesamt' => self::mean($gesamtValues),
                'best' => $best,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return $result;
    }
}
