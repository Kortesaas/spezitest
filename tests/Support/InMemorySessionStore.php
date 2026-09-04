<?php

declare(strict_types=1);

namespace Spezitest\Tests\Support;

use Spezitest\Admin\Session\SessionStore;

final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    private array $values = [];

    private int $generation = 0;

    public function start(): void
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerate(): void
    {
        ++$this->generation;
    }

    public function destroy(): void
    {
        $this->values = [];
        ++$this->generation;
    }

    public function generation(): int
    {
        return $this->generation;
    }
}
