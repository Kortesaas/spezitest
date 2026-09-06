<?php

declare(strict_types=1);

namespace Spezitest\Website\Catalog;

/**
 * The parsed, sanitised state of the public Spezi browser (search, lifecycle
 * filter, sort, page, page size). Unknown values fall back to safe defaults so
 * the page always renders.
 */
final readonly class CatalogQuery
{
    /** The page size a first visit gets, and the step "Mehr laden" adds. */
    public const PER_PAGE = 24;

    /**
     * The largest page "Mehr laden" can grow to. Without a ceiling a single
     * request could be asked to render the whole catalog at once.
     */
    public const MAX_PER_PAGE = self::PER_PAGE * 8;

    public const SORTS = ['best', 'name', 'recent', 'worst'];

    public const STATUSES = ['identified', 'acquired', 'tested'];

    /**
     * @param list<string> $statuses
     * @param int $perPage always a whole multiple of {@see PER_PAGE}, between
     *        one step and {@see MAX_PER_PAGE}
     */
    public function __construct(
        public string $search,
        public array $statuses,
        public bool $withImageOnly,
        public string $sort,
        public int $page,
        public int $perPage = self::PER_PAGE,
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
            self::normalizePerPage($params['per'] ?? null),
        );
    }

    /**
     * "Mehr laden" only ever grows the page by whole steps, so the value is
     * snapped to the nearest step and clamped instead of being trusted.
     */
    private static function normalizePerPage(mixed $raw): int
    {
        if (!is_string($raw) || !ctype_digit($raw)) {
            return self::PER_PAGE;
        }

        $steps = (int) round((int) $raw / self::PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE / self::PER_PAGE, $steps)) * self::PER_PAGE;
    }

    /**
     * The same list with one more page-sized step visible. The window is
     * re-anchored on the first item currently shown, so everything already on
     * screen stays on screen and only new Spezis appear underneath.
     */
    public function withMoreItems(): self
    {
        $perPage = min(self::MAX_PER_PAGE, $this->perPage + self::PER_PAGE);
        $firstIndex = ($this->page - 1) * $this->perPage;

        return new self(
            $this->search,
            $this->statuses,
            $this->withImageOnly,
            $this->sort,
            intdiv($firstIndex, $perPage) + 1,
            $perPage,
        );
    }

    public function canLoadMore(): bool
    {
        return $this->perPage < self::MAX_PER_PAGE;
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

        if ($this->perPage !== self::PER_PAGE) {
            $pairs[] = 'per=' . $this->perPage;
        }

        return implode('&', $pairs);
    }

    /** The relative URL for this state, ready to put in an `href`. */
    public function url(?int $page = null): string
    {
        $query = $this->toQueryString($page);

        return $query === '' ? '/spezis' : '/spezis?' . $query;
    }

    /**
     * Changing a filter returns to the first page at the default size: the old
     * window would otherwise point into a different result set.
     */
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
