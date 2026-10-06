<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Read-only export queries for the signed-in account (Prompt 18).
 * Every query is scoped by user_id; credentials, sessions and password
 * reset material are never queried — they are not exportable data.
 */
final class AccountRepository extends Repository
{
    /** @return array<string, mixed> grouped personal data */
    public function export(int $userId): array
    {
        return [
            'profile' => $this->fetch(
                'SELECT id, email, display_name, status, email_verified_at, last_login_at,
                        created_at, updated_at
                   FROM users WHERE id = ?',
                [$userId]
            ),
            'settings' => $this->fetch(
                'SELECT theme, sound_enabled, screen_awake_enabled, locale, timezone,
                        daily_revision_unit, daily_revision_amount, created_at, updated_at
                   FROM user_settings WHERE user_id = ?',
                [$userId]
            ),
            'memorization_state' => $this->fetch(
                'SELECT * FROM memorization_states WHERE user_id = ?',
                [$userId]
            ),
            'memorization_history' => $this->fetchAll(
                'SELECT * FROM memorization_history WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'memorization_boundary_history' => $this->fetchAll(
                'SELECT * FROM memorization_boundary_history WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'revision_plans' => $this->fetchAll(
                'SELECT * FROM revision_plans WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'revision_cycles' => $this->fetchAll(
                'SELECT * FROM revision_cycles WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'revision_segments' => $this->fetchAll(
                'SELECT * FROM revision_segments WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'revision_sessions' => $this->fetchAll(
                'SELECT * FROM revision_sessions WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'rabt_sessions' => $this->fetchAll(
                'SELECT * FROM rabt_sessions WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'rabt_page_progress' => $this->fetchAll(
                'SELECT * FROM rabt_page_progress WHERE user_id = ? ORDER BY page_number',
                [$userId]
            ),
            'flip_cards' => $this->fetchAll(
                'SELECT * FROM flip_cards WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'flip_card_reviews' => $this->fetchAll(
                'SELECT * FROM flip_card_reviews WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'daily_tasks' => $this->fetchAll(
                'SELECT * FROM daily_tasks WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            'task_completions' => $this->fetchAll(
                'SELECT * FROM task_completions WHERE user_id = ? ORDER BY id',
                [$userId]
            ),
            // Referenced-vocabulary subsets (Prompt 23): only the seeded rows
            // this user's tasks/cards point at — enough for a future import to
            // resolve foreign keys by slug without exporting global tables.
            'task_types' => $this->fetchAll(
                'SELECT id, slug, name_en, name_ar, category, default_duration_minutes,
                        sort_order, is_active
                   FROM task_types
                  WHERE id IN (SELECT DISTINCT task_type_id FROM daily_tasks WHERE user_id = ?)
                  ORDER BY id',
                [$userId]
            ),
            'flip_card_categories' => $this->fetchAll(
                'SELECT id, slug, name_en, name_ar, description_en, description_ar, sort_order
                   FROM flip_card_categories
                  WHERE id IN (SELECT DISTINCT category_id FROM flip_cards WHERE user_id = ?)
                  ORDER BY id',
                [$userId]
            ),
        ];
    }
}
