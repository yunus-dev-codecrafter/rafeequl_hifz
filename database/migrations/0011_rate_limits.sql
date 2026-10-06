-- ============================================================
-- Rafeequl Hifz - migration 0011: rate limits
-- Apply order: after 0010.
-- Fixed-window counters for unauthenticated high-risk endpoints
-- (register / forgot-password / reset-password). bucket_key holds
-- only a SHA-256 hash of (route | ip | email) - no raw IPs or
-- addresses are stored (audit: docs/security/security-audit.md, F-03).
-- ============================================================

CREATE TABLE rate_limits (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_key        CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  attempts          INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_limits_bucket (bucket_key),
  KEY idx_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
