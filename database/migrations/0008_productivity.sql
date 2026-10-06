-- ============================================================
-- Rafeequl Hifz — migration 0008: productivity (daily tasks)
-- Apply order: after 0007.
-- Seeds application vocabulary (task types) — NOT Quran data.
-- ============================================================

CREATE TABLE task_types (
  id                      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug                    VARCHAR(64) NOT NULL,
  name_en                 VARCHAR(64) NOT NULL,
  name_ar                 VARCHAR(64) NOT NULL,
  category                ENUM('quran','general') NOT NULL,
  default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  sort_order              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active               TINYINT(1) NOT NULL DEFAULT 1,
  created_at              DATETIME NOT NULL,
  updated_at              DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_types_slug (slug),
  KEY idx_task_types_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO task_types (slug, name_en, name_ar, category, default_duration_minutes, sort_order, is_active, created_at, updated_at) VALUES
  ('murajaah',       'Murajaah (revision)',  'مراجعة',   'quran',   30, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('rabt',           'Rabt (rolling link)',  'ربط',       'quran',   20, 2, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('new_memorization', 'New memorization',   'حفظ جديد',  'quran',   45, 3, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('flip_card_review', 'Flip card review',   'مراجعة البطاقات', 'quran', 15, 4, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('personal',       'Personal task',        'مهمة شخصية', 'general', 30, 5, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());

CREATE TABLE daily_tasks (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  BIGINT UNSIGNED NOT NULL,
  task_type_id             SMALLINT UNSIGNED NOT NULL,
  title                    VARCHAR(150) NULL,
  scheduled_date           DATE NOT NULL,
  duration_minutes         SMALLINT UNSIGNED NOT NULL,
  status                   ENUM('pending','active','completed','skipped') NOT NULL DEFAULT 'pending',
  completed_at             DATETIME NULL,
  actual_duration_seconds  INT UNSIGNED NULL,
  notes                    VARCHAR(255) NULL,
  created_at               DATETIME NOT NULL,
  updated_at               DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_daily_tasks_user_date (user_id, scheduled_date),
  KEY idx_daily_tasks_user_status (user_id, scheduled_date, status),
  CONSTRAINT fk_daily_tasks_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_daily_tasks_type FOREIGN KEY (task_type_id)
    REFERENCES task_types (id) ON DELETE RESTRICT,
  CONSTRAINT chk_daily_tasks_duration CHECK (duration_minutes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One completion record per task; independent of the task's current status row
-- so the history stays understandable if the task row is later edited.
CREATE TABLE task_completions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id         BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  completed_at    DATETIME NOT NULL,
  duration_seconds INT UNSIGNED NULL,
  note            VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_task_completions_task (task_id),
  KEY idx_task_completions_user_date (user_id, completed_at),
  CONSTRAINT fk_task_completions_task FOREIGN KEY (task_id)
    REFERENCES daily_tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_task_completions_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
