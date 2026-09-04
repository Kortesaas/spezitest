<?php

declare(strict_types=1);

namespace Spezitest\LegacyImport;

use JsonException;

final readonly class LegacyImportPlan
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
        private string $path,
        private string $sha256,
    ) {
    }

    public static function load(string $path): self
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new LegacyImportException('Could not read the legacy import plan.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LegacyImportException('The legacy import plan is not valid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new LegacyImportException('The legacy import plan must contain a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        $plan = new self($decoded, $path, hash('sha256', $contents));
        $plan->validateEnvelope();

        return $plan;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function runId(): string
    {
        return $this->requiredString($this->data, 'run_id');
    }

    public function applyReady(): bool
    {
        $value = $this->data['apply_ready'] ?? null;

        if (!is_bool($value)) {
            throw new LegacyImportException('Import plan apply_ready must be boolean.');
        }

        return $value;
    }

    /** @return list<string> */
    public function unresolvedReviewIds(): array
    {
        $values = $this->requiredList($this->data, 'unresolved_review_ids');
        $result = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new LegacyImportException('Unresolved review identifiers must be strings.');
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function drinks(): array
    {
        $values = $this->requiredList($this->data, 'drinks');
        $drinks = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new LegacyImportException('Every planned drink must be an object.');
            }
            /** @var array<string, mixed> $value */
            $drinks[] = $value;
        }

        return $drinks;
    }

    /** @return array<string, mixed> */
    public function counts(): array
    {
        return $this->requiredMap($this->data, 'counts');
    }

    /** @return array<string, mixed> */
    public function source(string $key): array
    {
        return $this->requiredMap($this->requiredMap($this->data, 'sources'), $key);
    }

    /** @param array<string, mixed> $values */
    public function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new LegacyImportException('Expected non-empty string at ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    public function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new LegacyImportException('Expected string or null at ' . $key . '.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function requiredMap(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            throw new LegacyImportException('Expected object at ' . $key . '.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return list<mixed>
     */
    public function requiredList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (!is_array($value) || !array_is_list($value)) {
            throw new LegacyImportException('Expected list at ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    public function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (!is_int($value)) {
            throw new LegacyImportException('Expected integer at ' . $key . '.');
        }

        return $value;
    }

    private function validateEnvelope(): void
    {
        if (($this->data['schema_version'] ?? null) !== 1) {
            throw new LegacyImportException('Unsupported legacy import plan schema version.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $this->runId()) !== 1) {
            throw new LegacyImportException('Legacy import run ID must be a SHA-256 value.');
        }

        $this->counts();
        $this->drinks();
        $this->unresolvedReviewIds();
        $this->source('primaerliste');
        $this->source('beschaffungsliste');
    }
}
