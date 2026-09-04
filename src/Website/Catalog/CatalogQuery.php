<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * The parsed, sanitised state of the public Spezi browser (search, lifecycle
 * filter, sort, page). Unknown values fall back to safe defaults so the page
 * always renders.
 */
final readonly class CatalogQuery
{
    public const PER_PAGE = 24;

    public const SORTS = ['best', 'name', 'recent', 'worst'];

    public const STATUSES = ['identified', 'acquired', 'tested'];

    /**
     * @param list<string> $statuses
     */
    public function __construct(
        public string $search,
        public array $statuses,
        public bool $withImageOnly,
        public string $sort,
        public int $page,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public static function fromQueryParams(array $params): self
    {
        $search = $params['q'] ?? '';
        $search = is_string($search) ? trim(mb_substr($search, 0, 120)) : '';

        $statuses = [];
        $rawStatus = $params['status'] ?? null;

        foreach (is_array($rawStatus) ? $rawStatus : [$rawStatus] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::STATUSES, true) && !in_array($candidate, $statuses, true)) {
                $statuses[] = $candidate;
            }
        }

        $sort = $params['sort'] ?? 'best';
        $sort = is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'best';

        $page = $params['page'] ?? '1';
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;

        return new self(
            $search,
            $statuses,
            ($params['with_image'] ?? null) === '1',
            $sort,
            $page,
        );
    }

    public function isFiltered(): bool
    {
        return $this->search !== '' || $this->statuses !== [] || $this->withImageOnly;
    }

    /**
     * Build a `key=value` query string for this state, optionally on a
     * different page. Values are URL-encoded; an empty result is returned as
     * the empty string (no leading `?`).
     */
    public function toQueryString(?int $page = null): string
    {
        $pairs = [];

        if ($this->search !== '') {
            $pairs[] = 'q=' . rawurlencode($this->search);
        }

        foreach ($this->statuses as $status) {
            $pairs[] = 'status%5B%5D=' . rawurlencode($status);
        }

        if ($this->withImageOnly) {
            $pairs[] = 'with_image=1';
        }

        if ($this->sort !== 'best') {
            $pairs[] = 'sort=' . rawurlencode($this->sort);
        }

        $effectivePage = $page ?? $this->page;

        if ($effectivePage > 1) {
            $pairs[] = 'page=' . $effectivePage;
        }

        return implode('&', $pairs);
    }

    public function withoutStatus(string $status): self
    {
        return new self(
            $this->search,
            array_values(array_filter($this->statuses, static fn (string $s): bool => $s !== $status)),
            $this->withImageOnly,
            $this->sort,
            1,
        );
    }

    public function withStatus(string $status): self
    {
        $statuses = $this->statuses;

        if (!in_array($status, $statuses, true)) {
            $statuses[] = $status;
        }

        return new self($this->search, $statuses, $this->withImageOnly, $this->sort, 1);
    }

    public function withImageFilter(bool $withImageOnly): self
    {
        return new self($this->search, $this->statuses, $withImageOnly, $this->sort, 1);
    }
}
