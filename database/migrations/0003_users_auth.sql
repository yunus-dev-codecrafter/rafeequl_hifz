-- ============================================================
-- Rafeequl Hifz — migration 0003: users, authentication, settings
-- Apply order: after 0002.
-- Data policy: no seeded users, no credentials, no secrets.
-- ============================================================

CREATE TABLE users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(190) NOT NULL,
  display_name      VARCHAR(100) NOT NULL DEFAULT '',
  status            ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  last_login_at     DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authentication-related data, kept separate from the profile row.
CREATE TABLE user_auth (
  user_id             BIGINT UNSIGNED NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  password_changed_at DATETIME NULL,
  failed_login_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_auth_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent login sessions (token hash only — the raw token is never stored).
CREATE TABLE user_sessions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL,
  ip_address   VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_token (token_hash),
  KEY idx_user_sessions_user_expires (user_id, expires_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dashboard/UX preferences (screen awake, sound, day/night, locale, default revision target).
CREATE TABLE user_settings (
  user_id                 BIGINT UNSIGNED NOT NULL,
  theme                   ENUM('day','night','auto') NOT NULL DEFAULT 'day',
  sound_enabled           TINYINT(1) NOT NULL DEFAULT 1,
  screen_awake_enabled    TINYINT(1) NOT NULL DEFAULT 0,
  locale                  ENUM('en','ar') NOT NULL DEFAULT 'en',
  timezone                VARCHAR(64) NOT NULL DEFAULT 'UTC',
  daily_revision_unit     ENUM('page','hizb','rub','juz') NOT NULL DEFAULT 'page',
  daily_revision_amount   DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  created_at              DATETIME NOT NULL,
  updated_at              DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
