<?php

declare(strict_types=1);

namespace Spezitest\LegacyImport;

use JsonException;

final class LegacyImportReportWriter
{
    /**
     * @param array<string, mixed> $verification
     * @param array<string, mixed>|null $application
     */
    public function write(
        LegacyImportPlan $plan,
        array $verification,
        string $outputDirectory,
        string $stage,
        ?array $application = null,
    ): void {
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
            throw new LegacyImportException('Could not create the legacy import report directory.');
        }

        $data = $plan->data();
        $report = [
            'stage' => $stage,
            'run_id' => $plan->runId(),
            'plan_sha256' => $plan->sha256(),
            'source_integrity_verified' => true,
            'sources' => $data['sources'] ?? null,
            'counts' => $data['counts'] ?? null,
            'exact_duplicate_merges' => $data['exact_duplicate_merges'] ?? [],
            'fuzzy_candidates' => $data['fuzzy_candidates'] ?? [],
            'corrections' => $data['corrections'] ?? [],
            'deferred_values' => $data['deferred_values'] ?? [],
            'missing_images' => $data['missing_images'] ?? [],
            'verification' => $verification,
            'application' => $application,
            'warnings' => $plan->applyReady() ? [] : [
                'Final apply is blocked until every fuzzy duplicate decision is explicit.',
            ],
            'errors' => [],
        ];

        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LegacyImportException('Could not encode the legacy import report.', 0, $exception);
        }

        $baseName = $stage === 'apply' ? 'apply-report' : 'dry-run-report';
        if (file_put_contents($outputDirectory . DIRECTORY_SEPARATOR . $baseName . '.json', $json . "\n") === false) {
            throw new LegacyImportException('Could not write the machine-readable legacy import report.');
        }
        if (file_put_contents($outputDirectory . DIRECTORY_SEPARATOR . $baseName . '.md', $this->markdown($plan, $verification, $stage, $application)) === false) {
            throw new LegacyImportException('Could not write the human-readable legacy import report.');
        }
    }

    /**
     * @param array<string, mixed> $verification
     * @param array<string, mixed>|null $application
     */
    private function markdown(LegacyImportPlan $plan, array $verification, string $stage, ?array $application): string
    {
        $data = $plan->data();
        $counts = $plan->counts();
        $lifecycle = $plan->requiredMap($counts, 'lifecycle');
        $primaSource = $plan->source('primaerliste');
        $beschaffungSource = $plan->source('beschaffungsliste');
        $lines = [
            '# Spezitest legacy import ' . ($stage === 'apply' ? 'apply' : 'dry-run') . ' report',
            '',
            '- Run ID: `' . $plan->runId() . '`',
            '- Plan SHA-256: `' . $plan->sha256() . '`',
            '- Source integrity: verified before and after planning, and again during PHP verification',
            '- Primärliste SHA-256: `' . $plan->requiredString($primaSource, 'sha256_before') . '`',
            '- Beschaffungsliste SHA-256: `' . $plan->requiredString($beschaffungSource, 'sha256_before') . '`',
            '- Apply ready: ' . ($plan->applyReady() ? 'yes' : 'no'),
            '',
            '## Counts',
            '',
            '| Item | Count |',
            '| --- | ---: |',
            '| Source rows | ' . $plan->requiredInt($plan->requiredMap($counts, 'source_rows'), 'total') . ' |',
            '| Primärliste rows | ' . $plan->requiredInt($plan->requiredMap($counts, 'source_rows'), 'primaerliste') . ' |',
            '| Beschaffungsliste rows | ' . $plan->requiredInt($plan->requiredMap($counts, 'source_rows'), 'beschaffungsliste') . ' |',
            '| Drinks | ' . $plan->requiredInt($counts, 'drinks') . ' |',
            '| Identified | ' . $plan->requiredInt($lifecycle, 'identified') . ' |',
            '| Acquired | ' . $plan->requiredInt($lifecycle, 'acquired') . ' |',
            '| Tested | ' . $plan->requiredInt($lifecycle, 'tested') . ' |',
            '| Completed tests | ' . $plan->requiredInt($counts, 'tests') . ' |',
            '| Raw ratings | ' . $plan->requiredInt($counts, 'ratings') . ' |',
            '| Images extracted | ' . $plan->requiredInt($counts, 'images_extracted') . ' |',
            '| Unique image files | ' . $plan->requiredInt($counts, 'unique_image_files') . ' |',
            '| Image attachments | ' . $plan->requiredInt($counts, 'images_attached') . ' |',
            '| Missing images | ' . $plan->requiredInt($counts, 'images_missing') . ' |',
            '| Image deduplications | ' . $plan->requiredInt($counts, 'image_deduplications') . ' |',
            '| Imported test prices | ' . $plan->requiredInt($counts, 'prices_imported') . ' |',
            '| Deferred untested prices | ' . $plan->requiredInt($counts, 'untested_prices_deferred') . ' |',
            '',
            '## Verification',
            '',
            '- Historical Gesamt results verified: ' . $this->nestedInt($verification, 'ratings', 'verified'),
            '- Historical ranking results verified: ' . $this->nestedInt($verification, 'ranking', 'verified'),
            '- Dynamic Preis/Leistung eligible population: ' . $this->nestedInt($verification, 'price_performance', 'eligible'),
            '- Verified staged image attachments: ' . $this->nestedInt($verification, 'images', 'verified_attachments'),
            '',
            '## Exact duplicate merges',
            '',
        ];

        foreach ($this->listOfMaps($data['exact_duplicate_merges'] ?? []) as $merge) {
            $lines[] = '- `' . $this->stringValue($merge, 'primaerliste') . '` + `' . $this->stringValue($merge, 'beschaffungsliste') . '` (audited corroboration and identical image hash)';
        }

        $lines[] = '';
        $lines[] = '## Fuzzy duplicate review';
        $lines[] = '';
        foreach ($this->listOfMaps($data['fuzzy_candidates'] ?? []) as $candidate) {
            $left = $plan->requiredMap($candidate, 'left');
            $right = $plan->requiredMap($candidate, 'right');
            $lines[] = sprintf(
                '- `%s`: %s (`%s`) ↔ %s (`%s`) — **%s**',
                $this->stringValue($candidate, 'id'),
                $this->stringValue($left, 'name'),
                $this->stringValue($left, 'source'),
                $this->stringValue($right, 'name'),
                $this->stringValue($right, 'source'),
                $this->stringValue($candidate, 'decision'),
            );
        }

        $lines[] = '';
        $lines[] = '## Intentional corrections';
        $lines[] = '';
        foreach ($this->listOfMaps($data['corrections'] ?? []) as $correction) {
            $lines[] = '- `' . $this->stringValue($correction, 'id') . '`: ' . $this->stringValue($correction, 'detail');
        }

        $lines[] = '';
        $lines[] = '## Deferred or unresolved information';
        $lines[] = '';
        $lines[] = '- ' . $plan->requiredInt($counts, 'untested_prices_deferred') . ' untested prices remain in the JSON report for later enrichment; no fake tests were created.';
        $lines[] = '- Price unit/basis and time/duration semantics remain unresolved.';
        $lines[] = '- Primärliste row 85 remains the valid no-image record.';
        if (!$plan->applyReady()) {
            $lines[] = '- Apply is blocked by: `' . implode('`, `', $plan->unresolvedReviewIds()) . '`.';
        }
        if ($application !== null) {
            $lines[] = '- Database apply completed with importer-record protection against a duplicate run.';
            $lines[] = '- Stored ratings reverified after insert: ' . $this->intValue($application, 'post_insert_ratings_verified') . '.';
            $lines[] = '- Stored ranks reverified after insert: ' . $this->intValue($application, 'post_insert_ranks_verified') . '.';
            $lines[] = '- Stored dynamic Preis/Leistung results reverified after insert: ' . $this->intValue($application, 'post_insert_price_performance_verified') . '.';
        }
        $lines[] = '';
        $lines[] = '## Errors';
        $lines[] = '';
        $lines[] = '- None. Any rating, ranking, source-integrity, image-integrity, or schema failure aborts the command.';

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed> $values */
    private function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function listOfMaps(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = $item;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $values */
    private function nestedInt(array $values, string $section, string $key): int
    {
        $map = $values[$section] ?? null;
        $value = is_array($map) ? ($map[$key] ?? null) : null;

        return is_int($value) ? $value : 0;
    }

    /** @param array<string, mixed> $values */
    private function intValue(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}
