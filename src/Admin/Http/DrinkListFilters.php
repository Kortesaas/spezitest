<?php

declare(strict_types=1);

namespace Spezitest\Admin\Http;

/**
 * The validated state of the Spezi overview's filter bar: free-text search,
 * lifecycle status, one data-quality shortcut and a sort key.
 *
 * Every value is already validated by {@see \Spezitest\Admin\Validation\DrinkInputValidator};
 * this object only carries them between controller, repository and renderer and
 * builds the links the filter chips need.
 */
final readonly class DrinkListFilters
{
    public function __construct(
        public string $search = '',
        public ?string $status = null,
        public string $flag = '',
        public string $sort = 'name',
        public string $path = '/admin/drinks',
    ) {
    }

    public function isFiltered(): bool
    {
        return $this->search !== '' || $this->status !== null || $this->flag !== '';
    }

    public function isSorted(): bool
    {
        return $this->sort !== 'name';
    }

    public function withStatus(?string $status): self
    {
        return new self($this->search, $status, $this->flag, $this->sort, $this->path);
    }

    public function withFlag(string $flag): self
    {
        return new self($this->search, $this->status, $flag, $this->sort, $this->path);
    }

    public function withSort(string $sort): self
    {
        return new self($this->search, $this->status, $this->flag, $sort, $this->path);
    }

    /** The same list without the given filter, for a "remove this chip" link. */
    public function toggledFlag(string $flag): self
    {
        return $this->withFlag($this->flag === $flag ? '' : $flag);
    }

    public function toggledStatus(string $status): self
    {
        return $this->withStatus($this->status === $status ? null : $status);
    }

    /** A relative URL carrying only the parameters that are actually set. */
    public function url(): string
    {
        $query = array_filter(
            [
                'q' => $this->search,
                'lifecycle_status' => $this->status ?? '',
                'filter' => $this->flag,
                'sort' => $this->sort === 'name' ? '' : $this->sort,
            ],
            static fn (string $value): bool => $value !== '',
        );

        return $query === [] ? $this->path : $this->path . '?' . http_build_query($query);
    }

    /**
     * The parameters a GET form must repeat as hidden fields so submitting the
     * search box or the sort select does not silently drop the active filters.
     *
     * @return array<string, string>
     */
    public function hiddenFields(string $except): array
    {
        $fields = [
            'q' => $this->search,
            'lifecycle_status' => $this->status ?? '',
            'filter' => $this->flag,
            'sort' => $this->sort,
        ];

        unset($fields[$except]);

        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }
}
