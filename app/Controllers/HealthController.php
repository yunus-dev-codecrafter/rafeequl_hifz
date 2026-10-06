<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Response;

/**
 * Operational health check (infrastructure, not a business feature).
 * Used to verify deployment: PHP boots, routes resolve, database reachable.
 */
final class HealthController extends Controller
{
    public function status(array $params = []): Response
    {
        try {
            Database::scalar('SELECT 1');
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'unavailable';
        }

        if ($database !== 'ok') {
            return $this->failure(503, 'Database unavailable');
        }

        return $this->success([
            'service' => 'rafeequl-hifz',
            'status' => 'ok',
            'database' => $database,
            'environment' => APP_ENV,
            'time' => gmdate('c'),
        ]);
    }
}
