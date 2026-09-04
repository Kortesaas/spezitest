<?php

declare(strict_types=1);

namespace Spezitest\LegacyImport;

use Spezitest\Domain\Rating\CompetitionRanking;
use Spezitest\Domain\Rating\ExactNumber;
use Spezitest\Domain\Rating\PricePerformanceCalculator;
use Spezitest\Domain\Rating\RatingCalculator;
use Spezitest\Domain\Rating\TesterCode;
use Spezitest\Domain\Rating\TesterRating;

final class LegacyImportVerifier
{
    /** @return array<string, mixed> */
    public function verify(LegacyImportPlan $plan, string $projectRoot): array
    {
        $sourceHashes = $this->verifySources($plan, $projectRoot);
        $ratingCalculator = new RatingCalculator();
        $scores = [];
        $expectedRanks = [];
        $prices = [];
        $computedCounts = [
            'drinks' => 0,
            'identified' => 0,
            'acquired' => 0,
            'tested' => 0,
            'tests' => 0,
            'ratings' => 0,
            'images_attached' => 0,
        ];
        $seenDrinkKeys = [];
        $seenTests = [];
        $seenImagePaths = [];

        foreach ($plan->drinks() as $drink) {
            $planKey = $plan->requiredString($drink, 'plan_key');
            if (isset($seenDrinkKeys[$planKey])) {
                throw new LegacyImportException('Duplicate drink plan key: ' . $planKey);
            }
            $seenDrinkKeys[$planKey] = true;
            ++$computedCounts['drinks'];

            $status = $plan->requiredString($drink, 'lifecycle_status');
            if (!in_array($status, ['identified', 'acquired', 'tested'], true)) {
                throw new LegacyImportException('Invalid planned lifecycle status for ' . $planKey . '.');
            }
            ++$computedCounts[$status];
            $plan->requiredString($drink, 'name');

            foreach ($plan->requiredList($drink, 'tests') as $testValue) {
                if (!is_array($testValue)) {
                    throw new LegacyImportException('Planned tests must be objects.');
                }
                /** @var array<string, mixed> $test */
                $test = $testValue;
                $testSource = $plan->requiredString($test, 'source');
                if (isset($seenTests[$testSource])) {
                    throw new LegacyImportException('Duplicate planned test: ' . $testSource);
                }
                $seenTests[$testSource] = true;
                if ($plan->requiredString($test, 'status') !== 'completed') {
                    throw new LegacyImportException('Legacy plans may only contain completed historical tests.');
                }

                $ratingsMap = $plan->requiredMap($test, 'ratings');
                $ratings = [];
                foreach ([
                    'manu' => TesterCode::Manu,
                    'fabi' => TesterCode::Fabi,
                    'schorsch' => TesterCode::Schorsch,
                ] as $code => $tester) {
                    $rating = $plan->requiredMap($ratingsMap, $code);
                    $ratings[] = new TesterRating(
                        $tester,
                        $plan->requiredString($rating, 'optik'),
                        $plan->requiredString($rating, 'sueffigkeit'),
                        $plan->requiredString($rating, 'geschmack'),
                    );
                }
                if (count($ratingsMap) !== 3) {
                    throw new LegacyImportException('A historical test must contain exactly three canonical ratings.');
                }

                $result = $ratingCalculator->calculate($ratings);
                if ($result === null) {
                    throw new LegacyImportException('A historical test did not produce an official result.');
                }
                $historical = $plan->requiredMap($test, 'historical');
                $expectedGesamt = (float) $plan->requiredString($historical, 'gesamt');
                if (abs($result->gesamt() - $expectedGesamt) > 1.0E-12) {
                    throw new LegacyImportException(sprintf(
                        'Rating mismatch for %s: calculated %.14F, expected %.14F.',
                        $testSource,
                        $result->gesamt(),
                        $expectedGesamt,
                    ));
                }
                $scores[$testSource] = $result->gesamt();
                $expectedRanks[$testSource] = $plan->requiredInt($historical, 'rank');
                $price = $plan->optionalString($test, 'price_amount');
                if ($price !== null && ExactNumber::from($price)->isPositive()) {
                    $prices[$testSource] = $price;
                }
                ++$computedCounts['tests'];
                $computedCounts['ratings'] += 3;
            }

            foreach ($plan->requiredList($drink, 'images') as $imageValue) {
                if (!is_array($imageValue)) {
                    throw new LegacyImportException('Planned images must be objects.');
                }
                /** @var array<string, mixed> $image */
                $image = $imageValue;
                $relativePath = $plan->requiredString($image, 'staged_path');
                if (preg_match('#\Aimages/[a-f0-9]{64}\.(?:png|jpg)\z#D', $relativePath) !== 1) {
                    throw new LegacyImportException('A staged image path is not a safe generated path.');
                }
                if (isset($seenImagePaths[$relativePath])) {
                    throw new LegacyImportException('The plan attaches one staged image to more than one drink.');
                }
                $seenImagePaths[$relativePath] = true;
                $imagePath = $plan->directory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (!is_file($imagePath)) {
                    throw new LegacyImportException('A planned image file is missing: ' . $relativePath);
                }
                $expectedHash = $plan->requiredString($image, 'sha256');
                $actualImageHash = hash_file('sha256', $imagePath);
                if ($actualImageHash === false || !hash_equals($expectedHash, $actualImageHash)) {
                    throw new LegacyImportException('A planned image content hash does not match: ' . $relativePath);
                }
                $mime = $plan->requiredString($image, 'mime_type');
                $bytes = file_get_contents($imagePath, false, null, 0, 16);
                if ($bytes === false || !$this->matchesMime($bytes, $mime)) {
                    throw new LegacyImportException('A planned image signature does not match its MIME type.');
                }
                if ($plan->requiredInt($image, 'width') < 1 || $plan->requiredInt($image, 'height') < 1) {
                    throw new LegacyImportException('A planned image has invalid dimensions.');
                }
                ++$computedCounts['images_attached'];
            }
        }

        $ranks = (new CompetitionRanking())->rank($scores);
        foreach ($expectedRanks as $source => $expectedRank) {
            if (($ranks[$source] ?? null) !== $expectedRank) {
                throw new LegacyImportException(sprintf(
                    'Historical rank mismatch for %s: calculated %s, expected %d.',
                    $source,
                    (string) ($ranks[$source] ?? 'missing'),
                    $expectedRank,
                ));
            }
        }

        $intermediates = [];
        foreach ($prices as $source => $price) {
            $intermediates[$source] = ExactNumber::from($scores[$source])
                ->divideBy(ExactNumber::from($price))
                ->toFloat();
        }
        $normalized = [];
        $priceCalculator = new PricePerformanceCalculator();
        foreach ($prices as $source => $price) {
            $result = $priceCalculator->calculate($scores[$source], $price, $intermediates);
            if ($result === null) {
                throw new LegacyImportException('Dynamic price/performance was unavailable for eligible test ' . $source . '.');
            }
            $value = $result->normalized();
            if ($value < -1.0E-12 || $value > 1.0 + 1.0E-12) {
                throw new LegacyImportException('Dynamic price/performance fell outside its normalized range for ' . $source . '.');
            }
            $normalized[$source] = max(0.0, min(1.0, $value));
        }

        $this->assertCounts($plan, $computedCounts);

        return [
            'source_integrity' => [
                'verified' => true,
                'sha256' => $sourceHashes,
            ],
            'ratings' => [
                'verified' => count($scores),
                'mismatches' => 0,
            ],
            'ranking' => [
                'verified' => count($ranks),
                'mismatches' => 0,
                'method' => 'descending competition ranking over all eligible completed tests',
            ],
            'price_performance' => [
                'eligible' => count($normalized),
                'comparison_population' => 'all currently eligible completed/tested results with a valid positive price',
                'minimum_normalized' => $normalized === [] ? null : min($normalized),
                'maximum_normalized' => $normalized === [] ? null : max($normalized),
            ],
            'images' => [
                'verified_attachments' => $computedCounts['images_attached'],
                'safe_generated_filenames' => true,
            ],
            'computed_counts' => $computedCounts,
            'apply_ready' => $plan->applyReady(),
            'unresolved_review_ids' => $plan->unresolvedReviewIds(),
        ];
    }

    /** @return array<string, string> */
    private function verifySources(LegacyImportPlan $plan, string $projectRoot): array
    {
        $expectedPaths = [
            'primaerliste' => 'var/legacy-import/primaerliste.xlsx',
            'beschaffungsliste' => 'var/legacy-import/beschaffungsliste.xlsx',
        ];
        $hashes = [];

        foreach ($expectedPaths as $key => $expectedPath) {
            $source = $plan->source($key);
            if ($plan->requiredString($source, 'path') !== $expectedPath) {
                throw new LegacyImportException('Unexpected source path in import plan for ' . $key . '.');
            }
            $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $expectedPath);
            if (!is_file($path)) {
                throw new LegacyImportException('Required source workbook is missing: ' . $expectedPath);
            }
            $actual = hash_file('sha256', $path);
            if ($actual === false) {
                throw new LegacyImportException('Could not hash required source workbook ' . $key . '.');
            }
            $before = $plan->requiredString($source, 'sha256_before');
            $after = $plan->requiredString($source, 'sha256_after');
            if (!hash_equals($before, $after) || !hash_equals($before, $actual)) {
                throw new LegacyImportException('Source workbook integrity check failed for ' . $key . '.');
            }
            $hashes[$key] = $actual;
        }

        return $hashes;
    }

    private function matchesMime(string $bytes, string $mime): bool
    {
        return ($mime === 'image/png' && str_starts_with($bytes, "\x89PNG\r\n\x1a\n"))
            || ($mime === 'image/jpeg' && str_starts_with($bytes, "\xff\xd8"));
    }

    /** @param array<string, int> $computed */
    private function assertCounts(LegacyImportPlan $plan, array $computed): void
    {
        $counts = $plan->counts();
        $lifecycle = $plan->requiredMap($counts, 'lifecycle');
        $expected = [
            'drinks' => $plan->requiredInt($counts, 'drinks'),
            'identified' => $plan->requiredInt($lifecycle, 'identified'),
            'acquired' => $plan->requiredInt($lifecycle, 'acquired'),
            'tested' => $plan->requiredInt($lifecycle, 'tested'),
            'tests' => $plan->requiredInt($counts, 'tests'),
            'ratings' => $plan->requiredInt($counts, 'ratings'),
            'images_attached' => $plan->requiredInt($counts, 'images_attached'),
        ];

        if ($computed !== $expected) {
            throw new LegacyImportException('Computed import-plan counts do not match the declared summary.');
        }
    }
}
