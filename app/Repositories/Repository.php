<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDOStatement;

/**
 * Base repository: the ONLY place database access lives (conventions §9).
 *
 * Subclasses build parameterized SQL and map rows; they never make
 * HTTP decisions or apply business rules — those belong to Controllers
 * and Services respectively.
 */
abstract class Repository
{
    protected function run(string $sql, array $params = []): PDOStatement
    {
        return Database::run($sql, $params);
    }

    /** @return array<string, mixed>|null */
    protected function fetch(string $sql, array $params = []): ?array
    {
        return Database::fetch($sql, $params);
    }

    /** @return array<int, array<string, mixed>> */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return Database::fetchAll($sql, $params);
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        return Database::scalar($sql, $params);
    }

    protected function insert(string $sql, array $params = []): int
    {
        return Database::insert($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        return Database::transaction($callback);
    }
}
