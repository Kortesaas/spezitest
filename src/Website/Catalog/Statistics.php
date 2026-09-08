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
    /** Category weights in the Gesamtwertung — a single tester's total is optik·1 + süffigkeit·2 + geschmack·3. */
    private const WEIGHTS = ['optik' => 1.0, 'sueffigkeit' => 2.0, 'geschmack' => 3.0];

    private const TESTERS = ['manu' => 'Manu', 'fabi' => 'Fabi', 'schorsch' => 'Schorsch'];

    /**
     * @param array{identified: int, acquired: int, tested: int} $lifecycleCounts
     * @param array{optik: ?float, sueffigkeit: ?float, geschmack: ?float} $averageByCategory
     * @param array{optik: ?array{name: string, value: float}, sueffigkeit: ?array{name: string, value: float}, geschmack: ?array{name: string, value: float}} $bestByCategory
     * @param array<string, ?float> $testerAverages Keyed by tester code.
     * @param array{optik: list<array{name: string, slug: string, value: float}>, sueffigkeit: list<array{name: string, slug: string, value: float}>, geschmack: list<array{name: string, slug: string, value: float}>} $categoryLeaderboards
     * @param list<array{code: string, label: string, average: ?float, byCategory: array{optik: ?float, sueffigkeit: ?float, geschmack: ?float}, favourite: ?array{name: string, slug: string, value: float}, harshest: ?array{name: string, slug: string, value: float}}> $testerProfiles
     * @param ?array{pair: string, meanSpread: float} $testerAgreement
     * @param list<array{name: string, slug: string, gesamt: float, testerTotals: array<string, float>, spread: float}> $disagreements sorted by spread, widest first
     * @param list<array{label: string, count: int, drinks: list<array{name: string, slug: string, gesamt: float}>}> $gesamtDistribution
     * @param list<array{region: string, count: int}> $regionCounts
     * @param list<array{region: string, count: int, averageGesamt: float}> $regionScores regions with at least three tested drinks, best average first
     * @param list<array{stream: int, count: int, averageGesamt: ?float, runningAverage: ?float, best: ?array{name: string, slug: string}}> $timeline by Spezistream number, ascending
     * @param ?array{name: string, slug: string, score: float, gesamt: float, price: float} $bestValue
     * @param list<array{name: string, slug: string, price: float, gesamt: float, score: ?float}> $priceScatter
     */
    public function __construct(
        public int $total,
        public array $lifecycleCounts,
        public int $testedCount,
        public ?float $averageGesamt,
        public ?float $medianGesamt,
        public ?float $bestGesamt,
        public array $averageByCategory,
        public array $bestByCategory,
        public array $categoryLeaderboards,
        public array $testerAverages,
        public array $testerProfiles,
        public ?array $testerAgreement,
        public array $disagreements,
        public array $gesamtDistribution,
        public array $regionCounts,
        public array $regionScores,
        public array $timeline,
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
        /** @var array{optik: list<array{name: string, slug: string, value: float}>, sueffigkeit: list<array{name: string, slug: string, value: float}>, geschmack: list<array{name: string, slug: string, value: float}>} $categoryEntries */
        $categoryEntries = ['optik' => [], 'sueffigkeit' => [], 'geschmack' => []];
        $testerTotals = [];
        /** @var array<string, array{optik: float, sueffigkeit: float, geschmack: float, count: int}> $testerCategorySums */
        $testerCategorySums = [];
        /** @var array<string, array{fav: ?array{name: string, slug: string, value: float}, worst: ?array{name: string, slug: string, value: float}}> $testerPicks */
        $testerPicks = [];
        /** @var array<string, list<float>> $pairSpreads */
        $pairSpreads = [];
        $disagreements = [];
        $distribution = array_fill(0, 6, 0);
        /** @var list<list<array{name: string, slug: string, gesamt: float}>> $distributionDrinks */
        $distributionDrinks = array_fill(0, 6, []);
        /** @var array<int, array{gesamt: list<float>, best: ?array{name: string, slug: string, gesamt: float}}> $streams */
        $streams = [];
        /** @var array<string, list<float>> $regionGesamt */
        $regionGesamt = [];
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

            $gesamt = $result->gesamt();
            $gesamtValues[] = $gesamt;
            $slug = $drink->slug();

            $byCategory = [
                'optik' => $result->optikAverage(),
                'sueffigkeit' => $result->sueffigkeitAverage(),
                'geschmack' => $result->geschmackAverage(),
            ];

            foreach ($byCategory as $category => $value) {
                $categoryValues[$category][] = $value;
                $categoryEntries[$category][] = ['name' => $drink->name, 'slug' => $slug, 'value' => $value];
                $current = $bestByCategory[$category];

                if ($current === null || $value > $current['value']) {
                    $bestByCategory[$category] = ['name' => $drink->name, 'value' => $value];
                }
            }

            $bin = min(5, (int) ($gesamt / 10));
            ++$distribution[$bin];
            $distributionDrinks[$bin][] = ['name' => $drink->name, 'slug' => $slug, 'gesamt' => $gesamt];

            // Per-tester figures.
            $totals = [];

            foreach (self::TESTERS as $code => $_label) {
                $grades = $drink->testerGrades[$code] ?? null;

                if ($grades === null) {
                    continue;
                }

                $testerTotals[$code] ??= ['sum' => 0.0, 'count' => 0];
                $testerTotals[$code]['sum'] += (float) $grades['optik'] + (float) $grades['sueffigkeit'] + (float) $grades['geschmack'];
                $testerTotals[$code]['count'] += 3;

                $testerCategorySums[$code] ??= ['optik' => 0.0, 'sueffigkeit' => 0.0, 'geschmack' => 0.0, 'count' => 0];

                foreach (self::WEIGHTS as $category => $_weight) {
                    $testerCategorySums[$code][$category] += (float) $grades[$category];
                }

                ++$testerCategorySums[$code]['count'];

                $total = self::testerTotal($grades);
                $totals[$code] = $total;
                $pick = ['name' => $drink->name, 'slug' => $slug, 'value' => $total];

                $picks = $testerPicks[$code] ?? ['fav' => null, 'worst' => null];

                if ($picks['fav'] === null || $total > $picks['fav']['value']) {
                    $picks['fav'] = $pick;
                }

                if ($picks['worst'] === null || $total < $picks['worst']['value']) {
                    $picks['worst'] = $pick;
                }

                $testerPicks[$code] = $picks;
            }

            if (isset($totals['manu'], $totals['fabi'], $totals['schorsch'])) {
                $disagreements[] = [
                    'name' => $drink->name,
                    'slug' => $slug,
                    'gesamt' => $gesamt,
                    'testerTotals' => $totals,
                    'spread' => max($totals) - min($totals),
                ];

                $pairSpreads['Manu & Fabi'][] = abs($totals['manu'] - $totals['fabi']);
                $pairSpreads['Manu & Schorsch'][] = abs($totals['manu'] - $totals['schorsch']);
                $pairSpreads['Fabi & Schorsch'][] = abs($totals['fabi'] - $totals['schorsch']);
            }

            // Verlauf über die Testabende.
            $runNumber = $drink->stream?->runNumber;

            if ($runNumber !== null) {
                $stream = $streams[$runNumber] ?? ['gesamt' => [], 'best' => null];
                $stream['gesamt'][] = $gesamt;

                if ($stream['best'] === null || $gesamt > $stream['best']['gesamt']) {
                    $stream['best'] = ['name' => $drink->name, 'slug' => $slug, 'gesamt' => $gesamt];
                }

                $streams[$runNumber] = $stream;
            }

            $region = $drink->originRegion;

            if ($region !== null && $region !== '') {
                $regionGesamt[$region][] = $gesamt;
            }

            if ($drink->priceAmount !== null && $drink->priceVolumeMl !== null) {
                $pricePerHalfLiter = $priceNormalizer->perReferenceVolume($drink->priceAmount, $drink->priceVolumeMl);
                $priceValues[] = $pricePerHalfLiter;
                $score = $drink->pricePerformance?->normalized();

                $priceScatter[] = [
                    'name' => $drink->name,
                    'slug' => $slug,
                    'price' => $pricePerHalfLiter,
                    'gesamt' => $gesamt,
                    'score' => $score,
                ];

                if ($score !== null && ($bestValue === null || $score > $bestValue['score'])) {
                    $bestValue = [
                        'name' => $drink->name,
                        'slug' => $slug,
                        'score' => $score,
                        'gesamt' => $gesamt,
                        'price' => $pricePerHalfLiter,
                    ];
                }
            }
        }

        $testerAverages = [];

        foreach (self::TESTERS as $code => $_label) {
            $entry = $testerTotals[$code] ?? null;
            $testerAverages[$code] = $entry !== null ? $entry['sum'] / $entry['count'] : null;
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

        usort($disagreements, static fn (array $a, array $b): int => $b['spread'] <=> $a['spread'] ?: strcasecmp($a['name'], $b['name']));

        return new self(
            $collection->count(),
            $counts,
            count($tested),
            self::mean($gesamtValues),
            self::median($gesamtValues),
            $gesamtValues === [] ? null : max($gesamtValues),
            [
                'optik' => self::mean($categoryValues['optik']),
                'sueffigkeit' => self::mean($categoryValues['sueffigkeit']),
                'geschmack' => self::mean($categoryValues['geschmack']),
            ],
            $bestByCategory,
            [
                'optik' => self::topEntries($categoryEntries['optik']),
                'sueffigkeit' => self::topEntries($categoryEntries['sueffigkeit']),
                'geschmack' => self::topEntries($categoryEntries['geschmack']),
            ],
            $testerAverages,
            self::testerProfiles($testerAverages, $testerCategorySums, $testerPicks),
            self::testerAgreement($pairSpreads),
            $disagreements,
            $gesamtDistribution,
            self::regionCounts($collection),
            self::regionScores($regionGesamt),
            self::timeline($streams),
            count($priceScatter),
            self::mean($priceValues),
            $bestValue,
            $priceScatter,
        );
    }

    /**
     * @param array{optik: string, sueffigkeit: string, geschmack: string} $grades
     */
    private static function testerTotal(array $grades): float
    {
        $total = 0.0;

        foreach (self::WEIGHTS as $category => $weight) {
            $total += (float) $grades[$category] * $weight;
        }

        return $total;
    }

    /**
     * @param list<array{name: string, slug: string, value: float}> $entries
     * @return list<array{name: string, slug: string, value: float}>
     */
    private static function topEntries(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => $b['value'] <=> $a['value'] ?: strcasecmp($a['name'], $b['name']));

        return array_slice($entries, 0, 5);
    }

    /**
     * @param array<string, ?float> $testerAverages
     * @param array<string, array{optik: float, sueffigkeit: float, geschmack: float, count: int}> $categorySums
     * @param array<string, array{fav: ?array{name: string, slug: string, value: float}, worst: ?array{name: string, slug: string, value: float}}> $picks
     * @return list<array{code: string, label: string, average: ?float, byCategory: array{optik: ?float, sueffigkeit: ?float, geschmack: ?float}, favourite: ?array{name: string, slug: string, value: float}, harshest: ?array{name: string, slug: string, value: float}}>
     */
    private static function testerProfiles(array $testerAverages, array $categorySums, array $picks): array
    {
        $profiles = [];

        foreach (self::TESTERS as $code => $label) {
            $sums = $categorySums[$code] ?? null;
            $count = $sums !== null ? $sums['count'] : 0;

            $profiles[] = [
                'code' => $code,
                'label' => $label,
                'average' => $testerAverages[$code] ?? null,
                'byCategory' => [
                    'optik' => $sums !== null && $count > 0 ? $sums['optik'] / $count : null,
                    'sueffigkeit' => $sums !== null && $count > 0 ? $sums['sueffigkeit'] / $count : null,
                    'geschmack' => $sums !== null && $count > 0 ? $sums['geschmack'] / $count : null,
                ],
                'favourite' => $picks[$code]['fav'] ?? null,
                'harshest' => $picks[$code]['worst'] ?? null,
            ];
        }

        return $profiles;
    }

    /**
     * @param array<string, list<float>> $pairSpreads
     * @return ?array{pair: string, meanSpread: float}
     */
    private static function testerAgreement(array $pairSpreads): ?array
    {
        $closest = null;

        foreach ($pairSpreads as $label => $values) {
            $mean = self::mean($values);

            if ($mean === null) {
                continue;
            }

            if ($closest === null || $mean < $closest['meanSpread']) {
                $closest = ['pair' => (string) $label, 'meanSpread' => $mean];
            }
        }

        return $closest;
    }

    /**
     * @param array<string, list<float>> $regionGesamt
     * @return list<array{region: string, count: int, averageGesamt: float}>
     */
    private static function regionScores(array $regionGesamt): array
    {
        $result = [];

        foreach ($regionGesamt as $region => $values) {
            if (count($values) < 3) {
                continue;
            }

            $result[] = [
                'region' => (string) $region,
                'count' => count($values),
                'averageGesamt' => array_sum($values) / count($values),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['averageGesamt'] <=> $a['averageGesamt'] ?: strcmp($a['region'], $b['region']));

        return $result;
    }

    /**
     * @param array<int, array{gesamt: list<float>, best: ?array{name: string, slug: string, gesamt: float}}> $streams
     * @return list<array{stream: int, count: int, averageGesamt: ?float, runningAverage: ?float, best: ?array{name: string, slug: string}}>
     */
    private static function timeline(array $streams): array
    {
        ksort($streams);

        $result = [];
        $runningValues = [];

        foreach ($streams as $number => $entry) {
            foreach ($entry['gesamt'] as $value) {
                $runningValues[] = $value;
            }

            $best = $entry['best'];

            $result[] = [
                'stream' => (int) $number,
                'count' => count($entry['gesamt']),
                'averageGesamt' => self::mean($entry['gesamt']),
                'runningAverage' => self::mean($runningValues),
                'best' => $best === null ? null : ['name' => $best['name'], 'slug' => $best['slug']],
            ];
        }

        return $result;
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
     * @param list<float> $values
     */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /**
     * Counts by the drink's recorded region (a Bundesland or a country) — the
     * free-form "PLZ Ort" origin string is deliberately not folded in here.
     *
     * @return list<array{region: string, count: int}>
     */
    private static function regionCounts(RatedDrinkCollection $collection): array
    {
        $counts = [];

        foreach ($collection->all() as $drink) {
            $region = $drink->originRegion;

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
}
