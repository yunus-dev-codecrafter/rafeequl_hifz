-- ============================================================
-- Rafeequl Hifz — migration 0005: revision (المراجعة)
-- Apply order: after 0004.
-- Every generation level snapshots the memorization boundary that was
-- current when it was created, so history remains readable after the
-- user's boundary moves. Segments never extend past the snapshot range.
-- ============================================================

-- A plan over the user's memorized range with a daily target unit/amount.
CREATE TABLE revision_plans (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  BIGINT UNSIGNED NOT NULL,
  name                     VARCHAR(120) NULL,
  target_unit              ENUM('page','hizb','rub','juz') NOT NULL DEFAULT 'page',
  daily_amount             DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  range_start_page         SMALLINT UNSIGNED NOT NULL,
  range_end_page           SMALLINT UNSIGNED NOT NULL,
  boundary_page_snapshot   SMALLINT UNSIGNED NOT NULL,
  status                   ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
  current_cycle_number     INT UNSIGNED NOT NULL DEFAULT 0,
  started_at               DATETIME NULL,
  completed_at             DATETIME NULL,
  created_at               DATETIME NOT NULL,
  updated_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_revision_plans_user_status (user_id, status),
  CONSTRAINT fk_revision_plans_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_revision_plans_range CHECK (range_end_page >= range_start_page),
  CONSTRAINT chk_revision_plans_amount CHECK (daily_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One pass through the plan's range, generated from the CURRENT boundary.
CREATE TABLE revision_cycles (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id                  BIGINT UNSIGNED NOT NULL,
  user_id                  BIGINT UNSIGNED NOT NULL,
  cycle_number             INT UNSIGNED NOT NULL,
  range_start_page         SMALLINT UNSIGNED NOT NULL,
  range_end_page           SMALLINT UNSIGNED NOT NULL,
  boundary_page_snapshot   SMALLINT UNSIGNED NOT NULL,
  segment_count            SMALLINT UNSIGNED NOT NULL,
  status                   ENUM('pending','active','completed','superseded') NOT NULL DEFAULT 'pending',
  started_at               DATETIME NULL,
  completed_at             DATETIME NULL,
  created_at               DATETIME NOT NULL,
  updated_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_revision_cycles_plan_number (plan_id, cycle_number),
  KEY idx_revision_cycles_user_status (user_id, status),
  CONSTRAINT fk_revision_cycles_plan FOREIGN KEY (plan_id)
    REFERENCES revision_plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_revision_cycles_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_revision_cycles_range CHECK (range_end_page >= range_start_page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily segments of a cycle; the final segment may be smaller than the target.
CREATE TABLE revision_segments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id       BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  segment_number SMALLINT UNSIGNED NOT NULL,
  start_page     SMALLINT UNSIGNED NOT NULL,
  end_page       SMALLINT UNSIGNED NOT NULL,
  page_count     SMALLINT UNSIGNED NOT NULL,
  scheduled_date DATE NULL,
  status         ENUM('pending','active','completed','skipped') NOT NULL DEFAULT 'pending',
  completed_at   DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_revision_segments_cycle_number (cycle_id, segment_number),
  KEY idx_revision_segments_user_status (user_id, status),
  KEY idx_revision_segments_user_date (user_id, scheduled_date),
  CONSTRAINT fk_revision_segments_cycle FOREIGN KEY (cycle_id)
    REFERENCES revision_cycles (id) ON DELETE CASCADE,
  CONSTRAINT fk_revision_segments_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_revision_segments_range CHECK (end_page >= start_page),
  CONSTRAINT chk_revision_segments_count CHECK (page_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions keep exact progress (last page reached, pages completed) so an
-- interrupted session is preserved verbatim; resuming creates a new row.
CREATE TABLE revision_sessions (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  segment_id           BIGINT UNSIGNED NOT NULL,
  user_id              BIGINT UNSIGNED NOT NULL,
  resumes_session_id   BIGINT UNSIGNED NULL,
  status               ENUM('completed','partial','interrupted') NOT NULL DEFAULT 'partial',
  total_pages          SMALLINT UNSIGNED NOT NULL,
  pages_completed      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_page_reached    SMALLINT UNSIGNED NULL,
  started_at           DATETIME NOT NULL,
  ended_at             DATETIME NULL,
  duration_seconds     INT UNSIGNED NULL,
  interruption_reason  VARCHAR(190) NULL,
  notes                VARCHAR(255) NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_revision_sessions_user_status (user_id, status),
  KEY idx_revision_sessions_segment (segment_id),
  CONSTRAINT fk_revision_sessions_segment FOREIGN KEY (segment_id)
    REFERENCES revision_segments (id) ON DELETE CASCADE,
  CONSTRAINT fk_revision_sessions_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_revision_sessions_resume FOREIGN KEY (resumes_session_id)
    REFERENCES revision_sessions (id) ON DELETE SET NULL,
  CONSTRAINT chk_revision_sessions_progress CHECK (pages_completed <= total_pages)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
