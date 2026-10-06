<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reads and writes user_settings (0003) — preferences only. This
 * repository never touches Hifz tables (memorization, revision, tasks):
 * preferences and Hifz data stay clearly separated (Prompt 18).
 */
final class SettingsRepository extends Repository
{
    /** Columns a settings update may write, with their SQL types. */
    private const WRITABLE = [
        'theme' => 'string',
        'sound_enabled' => 'int',
        'screen_awake_enabled' => 'int',
        'locale' => 'string',
        'daily_revision_unit' => 'string',
        'daily_revision_amount' => 'float',
    ];

    /** @return array<string, mixed>|null raw row (unmapped) */
    public function find(int $userId): ?array
    {
        return $this->fetch(
            'SELECT theme, sound_enabled, screen_awake_enabled, locale,
                    daily_revision_unit, daily_revision_amount
               FROM user_settings
              WHERE user_id = ?',
            [$userId]
        );
    }

    /** Self-healing read: inserts the schema defaults when the row is missing. */
    public function findOrInit(int $userId): array
    {
        $row = $this->find($userId);
        if ($row !== null) {
            return $row;
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->run(
            'INSERT IGNORE INTO user_settings (user_id, created_at, updated_at) VALUES (?, ?, ?)',
            [$userId, $now, $now]
        );

        $row = $this->find($userId);
        if ($row === null) {
            throw new \RuntimeException('user_settings row could not be initialized');
        }
        return $row;
    }

    /**
     * Updates only the given (already-whitelisted) fields.
     *
     * @param array<string, mixed> $fields column => value
     */
    public function update(int $userId, array $fields): void
    {
        $assignments = [];
        $params = [];

        foreach ($fields as $column => $value) {
            if (!array_key_exists($column, self::WRITABLE)) {
                throw new \LogicException('Unknown settings column: ' . $column);
            }
            $assignments[] = $column . ' = ?';
            $params[] = match (self::WRITABLE[$column]) {
                'int' => (int) $value,
                'float' => (float) $value,
                default => (string) $value,
            };
        }

        if ($assignments === []) {
            return;
        }

        $params[] = gmdate('Y-m-d H:i:s');
        $params[] = $userId;

        $this->run(
            'UPDATE user_settings SET ' . implode(', ', $assignments) . ', updated_at = ?
              WHERE user_id = ?',
            $params
        );
    }

    /** @return array{daily_revision_unit: string, daily_revision_amount: float}|null */
    public function findDefaultRevisionTarget(int $userId): ?array
    {
        $row = $this->fetch(
            'SELECT daily_revision_unit, daily_revision_amount
               FROM user_settings
              WHERE user_id = ?',
            [$userId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'daily_revision_unit' => (string) $row['daily_revision_unit'],
            'daily_revision_amount' => (float) $row['daily_revision_amount'],
        ];
    }
}
