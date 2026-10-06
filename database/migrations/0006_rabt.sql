-- ============================================================
-- Rafeequl Hifz — migration 0006: رَبْط (rolling activity & page tracking)
-- Apply order: after 0005.
-- The rolling window itself is derived server-side from the current
-- memorization boundary (max 30 pages); sessions store a snapshot of
-- the window that was active at the time.
-- ============================================================

CREATE TABLE rabt_sessions (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  BIGINT UNSIGNED NOT NULL,
  window_start_page        SMALLINT UNSIGNED NOT NULL,
  window_end_page          SMALLINT UNSIGNED NOT NULL,
  boundary_page_snapshot   SMALLINT UNSIGNED NOT NULL,
  pages_total              SMALLINT UNSIGNED NOT NULL,
  pages_reviewed           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status                   ENUM('completed','partial','interrupted') NOT NULL DEFAULT 'partial',
  started_at               DATETIME NOT NULL,
  ended_at                 DATETIME NULL,
  duration_seconds         INT UNSIGNED NULL,
  notes                    VARCHAR(255) NULL,
  created_at               DATETIME NOT NULL,
  updated_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rabt_sessions_user_date (user_id, started_at),
  CONSTRAINT fk_rabt_sessions_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_rabt_sessions_window CHECK (window_end_page >= window_start_page),
  CONSTRAINT chk_rabt_sessions_window_size CHECK (window_end_page - window_start_page + 1 <= 30),
  CONSTRAINT chk_rabt_sessions_progress CHECK (pages_reviewed <= pages_total)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-page tracking within the rolling range (window membership is derived,
-- never stored, so it cannot go stale when the boundary moves).
CREATE TABLE rabt_page_progress (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NOT NULL,
  page_number       SMALLINT UNSIGNED NOT NULL,
  times_reviewed    INT UNSIGNED NOT NULL DEFAULT 0,
  first_reviewed_at DATETIME NOT NULL,
  last_reviewed_at  DATETIME NOT NULL,
  last_session_id   BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rabt_page_progress_user_page (user_id, page_number),
  KEY idx_rabt_page_progress_user_last (user_id, last_reviewed_at),
  CONSTRAINT fk_rabt_page_progress_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_rabt_page_progress_session FOREIGN KEY (last_session_id)
    REFERENCES rabt_sessions (id) ON DELETE SET NULL,
  CONSTRAINT chk_rabt_page_progress_page CHECK (page_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
