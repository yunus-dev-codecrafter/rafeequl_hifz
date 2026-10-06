<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Lightweight row representation (conventions §9).
 *
 * Models carry typed data shape only — no queries, no HTTP, no rules.
 * Concrete models hydrate from repository rows via fromRow().
 */
abstract class Model
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @param array<string, mixed> $attributes */
    final protected function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
}
