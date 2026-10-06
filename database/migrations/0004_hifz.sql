-- ============================================================
-- Rafeequl Hifz — migration 0004: Hifz (memorization state & history)
-- Apply order: after 0003.
-- Quran coordinates are plain integers validated server-side at write
-- time (no FK into quran_* — see docs/quran-data/data-architecture.md §9).
-- History is append-only so records stay understandable when the
-- memorization boundary changes.
-- ============================================================

-- Current memorization state (exactly one row per user).
CREATE TABLE memorization_states (
  user_id                BIGINT UNSIGNED NOT NULL,
  memorized_start_page   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  current_boundary_page  SMALLINT UNSIGNED NOT NULL,
  status                 ENUM('active','paused') NOT NULL DEFAULT 'active',
  last_boundary_changed_at DATETIME NOT NULL,
  last_page_memorized_at DATETIME NULL,
  created_at             DATETIME NOT NULL,
  updated_at             DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_memorization_states_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_memorization_states_range
    CHECK (current_boundary_page >= memorized_start_page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only record of every page recorded as memorized.
CREATE TABLE memorization_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  page_number  SMALLINT UNSIGNED NOT NULL,
  memorized_at DATETIME NOT NULL,
  source       ENUM('manual','import') NOT NULL DEFAULT 'manual',
  note         VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memorization_history_user_date (user_id, memorized_at),
  KEY idx_memorization_history_user_page (user_id, page_number),
  CONSTRAINT fk_memorization_history_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_memorization_history_page CHECK (page_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only record of every boundary change (previous + new value kept).
CREATE TABLE memorization_boundary_history (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                BIGINT UNSIGNED NOT NULL,
  previous_boundary_page SMALLINT UNSIGNED NULL,
  new_boundary_page      SMALLINT UNSIGNED NOT NULL,
  changed_at             DATETIME NOT NULL,
  reason                 ENUM('manual','recalc','restart') NOT NULL DEFAULT 'manual',
  note                   VARCHAR(255) NULL,
  created_at             DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_memorization_boundary_history_user_date (user_id, changed_at),
  CONSTRAINT fk_memorization_boundary_history_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_memorization_boundary_history_page CHECK (new_boundary_page > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
