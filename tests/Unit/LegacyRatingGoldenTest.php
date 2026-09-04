<?php

declare(strict_types=1);

namespace Spezitest\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spezitest\Domain\Rating\CompetitionRanking;
use Spezitest\Domain\Rating\PricePerformanceCalculator;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterCode;
use Spezitest\Domain\Rating\TesterRating;

final class LegacyRatingGoldenTest extends TestCase
{
    /** @throws JsonException */
    public function testAllHistoricalGoldenCasesMatch(): void
    {
        $fixture = $this->fixture();
        $cases = $this->arrayAt($fixture, 'cases');
        $pricePopulation = $this->arrayAt($fixture, 'price_performance_population');
        $comparison = [
            $this->numericAt($pricePopulation, 'minimum'),
            $this->numericAt($pricePopulation, 'maximum'),
        ];
        $ratingCalculator = new RatingCalculator();
        $priceCalculator = new PricePerformanceCalculator();
        $checked = 0;

        foreach ($cases as $case) {
            if (!is_array($case)) {
                throw new RuntimeException('A golden case is not an object.');
            }

            $inputs = $this->arrayAt($case, 'inputs');
            $expected = $this->arrayAt($case, 'expected');
            $optik = $this->arrayAt($inputs, 'optik');
            $sueffigkeit = $this->arrayAt($inputs, 'sueffigkeit');
            $geschmack = $this->arrayAt($inputs, 'geschmack');
            $ratings = [];

            foreach ([
                'Manu' => TesterCode::Manu,
                'Fabi' => TesterCode::Fabi,
                'Schorsch' => TesterCode::Schorsch,
            ] as $displayName => $testerCode) {
                $ratings[] = new TesterRating(
                    $testerCode,
                    $this->numericAt($optik, $displayName),
                    $this->numericAt($sueffigkeit, $displayName),
                    $this->numericAt($geschmack, $displayName),
                );
            }

            $rating = $ratingCalculator->calculate($ratings);
            self::assertNotNull($rating);
            self::assertEqualsWithDelta($this->numericAt($expected, 'optik_average'), $rating->optikAverage(), 1.0E-14);
            self::assertEqualsWithDelta($this->numericAt($expected, 'sueffigkeit_average'), $rating->sueffigkeitAverage(), 1.0E-14);
            self::assertEqualsWithDelta($this->numericAt($expected, 'geschmack_average'), $rating->geschmackAverage(), 1.0E-14);
            self::assertEqualsWithDelta($this->numericAt($expected, 'gesamt'), $rating->gesamt(), 1.0E-14);

            $pricePerformance = $priceCalculator->calculate(
                $rating->gesamt(),
                $this->numericAt($inputs, 'price'),
                $comparison,
            );
            self::assertNotNull($pricePerformance);
            self::assertEqualsWithDelta(
                $this->numericAt($expected, 'price_performance_intermediate'),
                $pricePerformance->intermediate(),
                1.0E-12,
            );
            self::assertEqualsWithDelta(
                $this->numericAt($expected, 'price_performance'),
                $pricePerformance->normalized(),
                1.0E-12,
            );
            ++$checked;
        }

        self::assertSame(8, $checked);
    }

    /** @throws JsonException */
    public function testHistoricalRankingPopulationMatchesGoldenRanks(): void
    {
        $fixture = $this->fixture();
        $population = $this->arrayAt($fixture, 'ranking_population');
        $scores = [];

        foreach ($population as $row => $score) {
            if (!is_int($score) && !is_float($score) && !is_string($score)) {
                throw new RuntimeException('The ranking fixture has an invalid row or score.');
            }

            $scores[$row] = $score;
        }

        $ranks = (new CompetitionRanking())->rank($scores);

        foreach ($this->arrayAt($fixture, 'cases') as $case) {
            if (!is_array($case)) {
                throw new RuntimeException('A golden case is not an object.');
            }

            $row = $this->integerAt($case, 'source_row');
            $expected = $this->arrayAt($case, 'expected');
            self::assertSame($this->integerAt($expected, 'rank'), $ranks[$row] ?? null);
        }
    }

    /**
     * @return array<array-key, mixed>
     * @throws JsonException
     */
    private function fixture(): array
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/legacy-rating-golden.json');

        if ($contents === false) {
            throw new RuntimeException('Could not read the legacy rating fixture.');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('The legacy rating fixture is not an object.');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (!is_array($value)) {
            throw new RuntimeException('Expected fixture array at ' . $key . '.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private function numericAt(array $values, string $key): int|float
    {
        $value = $values[$key] ?? null;

        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Expected fixture number at ' . $key . '.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private function integerAt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (!is_int($value)) {
            throw new RuntimeException('Expected fixture integer at ' . $key . '.');
        }

        return $value;
    }
}
