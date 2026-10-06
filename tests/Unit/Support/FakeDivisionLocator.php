<?php

declare(strict_types=1);

use App\Models\DivisionType;
use App\Services\DivisionLocatorInterface;

/**
 * In-memory division ranges for unit tests (synthetic numbers, no dataset).
 * Mirrors the real service: on pages shared by two divisions the latest
 * start wins.
 */
final class FakeDivisionLocator implements DivisionLocatorInterface
{
    /** @param array<string, array<int, array{number: int, start: int, end: int}>> $ranges */
    public function __construct(private readonly array $ranges)
    {
    }

    public function divisionNumberAtPage(DivisionType $type, int $page): ?int
    {
        $best = null;
        foreach ($this->ranges[$type->value] ?? [] as $range) {
            if ($page < $range['start'] || $page > $range['end']) {
                continue;
            }
            if ($best === null || $range['start'] > $best['start']) {
                $best = $range;
            }
        }
        return $best['number'] ?? null;
    }

    public function divisionEndPage(DivisionType $type, int $number): ?int
    {
        foreach ($this->ranges[$type->value] ?? [] as $range) {
            if ($range['number'] === $number) {
                return $range['end'];
            }
        }
        return null;
    }
}
