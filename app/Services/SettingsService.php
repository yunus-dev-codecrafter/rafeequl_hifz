<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Models\ThemeMode;
use App\Repositories\SettingsRepository;

/**
 * User preferences (Prompt 18) — theme, sound, screen awake, language and
 * the default revision target.
 *
 * Boundary rule: this service only ever reads/writes user_settings. It
 * never touches memorization/revision/task tables, so preferences and
 * Hifz data stay clearly separated. The revision defaults influence
 * ONLY future plan creations that omit an explicit target
 * (RevisionService::resolveTarget) — existing plans, cycles and
 * segments are never rewritten by a preference change.
 */
final class SettingsService
{
    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    /** @return array<string, mixed> */
    public function show(int $userId): array
    {
        return $this->map($this->settings->findOrInit($userId));
    }

    /**
     * Partial update: only the fields present (non-null) in $data change.
     * Same-value writes are idempotent no-ops (200 with the representation).
     *
     * @param array<string, mixed> $data sanitized input
     * @return array<string, mixed> full settings after the update
     */
    public function update(int $userId, array $data): array
    {
        $fields = [];

        // "Provided" = present after validation: empty input normalized to
        // null by the validator means "keep current".
        $provided = static fn (mixed $value): bool => $value !== null && $value !== '';

        if ($provided($data['theme'] ?? null)) {
            $fields['theme'] = ThemeMode::from((string) $data['theme'])->toDb();
        }
        if ($provided($data['sound_enabled'] ?? null)) {
            $fields['sound_enabled'] = (int) $data['sound_enabled'];
        }
        if ($provided($data['screen_awake_enabled'] ?? null)) {
            $fields['screen_awake_enabled'] = (int) $data['screen_awake_enabled'];
        }
        if ($provided($data['locale'] ?? null)) {
            $fields['locale'] = (string) $data['locale'];
        }
        if ($provided($data['daily_revision_unit'] ?? null)) {
            $fields['daily_revision_unit'] = (string) $data['daily_revision_unit'];
        }
        if ($provided($data['daily_revision_amount'] ?? null)) {
            $fields['daily_revision_amount'] = round((float) $data['daily_revision_amount'], 3);
        }

        if ($fields !== []) {
            $this->assertRevisionTargetPair($userId, $fields);
            $this->settings->update($userId, $fields);
        }

        return $this->show($userId);
    }

    /**
     * The stored unit/amount pair must be one plan creation can actually
     * consume (mirrors RevisionTargetService::assertAmount): otherwise a
     * 200 settings write would make the next plan creation fail with a
     * 422 on a field the client never sent (Prompt 24).
     *
     * @param array<string, mixed> $fields the validated fields about to be written
     * @throws ValidationException the resulting pair would break plan creation
     */
    private function assertRevisionTargetPair(int $userId, array $fields): void
    {
        if (!isset($fields['daily_revision_unit']) && !isset($fields['daily_revision_amount'])) {
            return;
        }

        $row = $this->settings->findOrInit($userId);

        // Fall back to the DB value only when the field was not in this patch.
        // Guard against NULL DB values (rows created before the column existed)
        // by using the schema defaults ('page' / 1.0) as a safe fallback.
        $unit = isset($fields['daily_revision_unit'])
            ? (string) $fields['daily_revision_unit']
            : (isset($row['daily_revision_unit']) && $row['daily_revision_unit'] !== null && $row['daily_revision_unit'] !== ''
                ? (string) $row['daily_revision_unit']
                : 'page');
        $amount = isset($fields['daily_revision_amount'])
            ? (float) $fields['daily_revision_amount']
            : (isset($row['daily_revision_amount']) && $row['daily_revision_amount'] !== null
                ? (float) $row['daily_revision_amount']
                : 1.0);

        if ($unit !== 'page' && ($amount < 1 || floor($amount) !== $amount)) {
            throw ValidationException::withErrors([
                [
                    'field' => 'daily_revision_amount',
                    'message' => 'Division targets must be whole units (e.g. 1 or 2 hizb)',
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $row raw user_settings row */
    private function map(array $row): array
    {
        return [
            'theme' => ThemeMode::fromDb((string) $row['theme'])->value,
            'sound_enabled' => (bool) (int) $row['sound_enabled'],
            'screen_awake_enabled' => (bool) (int) $row['screen_awake_enabled'],
            'locale' => (string) $row['locale'],
            'daily_revision_unit' => (string) $row['daily_revision_unit'],
            'daily_revision_amount' => (float) $row['daily_revision_amount'],
        ];
    }
}
