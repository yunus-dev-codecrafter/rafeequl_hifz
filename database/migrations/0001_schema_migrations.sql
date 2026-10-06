-- ============================================================
-- Rafeequl Hifz — migration 0001: migration registry
-- Apply order: 0001 → 0002 → 0003 → 0004 → 0005 → 0006 → 0007 → 0008 → 0009 → 0010
-- See docs/database/schema.md
-- Data policy: contains no Quran data and no user data.
-- ============================================================

CREATE TABLE schema_migrations (
  version     VARCHAR(64)  NOT NULL,
  description VARCHAR(190) NOT NULL,
  checksum    CHAR(64)     NOT NULL,
  applied_at  DATETIME     NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
