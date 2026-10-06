-- ============================================================
-- Rafeequl Hifz — migration 0007: flip cards (error management)
-- Apply order: after 0006.
-- Seeds application vocabulary (error categories) — NOT Quran data.
-- Cards and reviews are retained after a card leaves the active queue;
-- only the status changes.
-- ============================================================

CREATE TABLE flip_card_categories (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug            VARCHAR(64) NOT NULL,
  name_en         VARCHAR(64) NOT NULL,
  name_ar         VARCHAR(64) NOT NULL,
  description_en  VARCHAR(255) NULL,
  description_ar  VARCHAR(255) NULL,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_flip_card_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO flip_card_categories (slug, name_en, name_ar, description_en, description_ar, sort_order, created_at, updated_at) VALUES
  ('forgotten_adjacent_ayah', 'Forgotten adjacent verse', 'نسيان آية مجاورة', 'Forgot a verse that follows or precedes the intended one', 'نسيان الآية التي تليها أو تسبقها المقصودة', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('verse_mistake',           'Verse mistake',            'خطأ في الآية',       'Mistake in the wording of the verse',                                   'خطأ في ألفاظ الآية', 2, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('similar_ayah_confusion',  'Similar verse confusion',  'تباس الآيات المشابهة', 'Confusion between similar verses',                                       'التباس الآيات المتشابهة', 3, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('hesitation',              'Hesitation',               'تردد',                'Hesitation or delay during recitation',                                 'تردد أو تلعثم أثناء التلاوة', 4, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('recurring_mistake',       'Recurring mistake',        'خطأ متكرر',           'The same mistake keeps repeating',                                      'تكرار الخطأ نفسه', 5, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('weak_location',           'Weak location',            'موضع ضعيف',           'A location in the Mushaf that feels weak',                              'موضع في المصحف تشعر فيه بالضعف', 6, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('other',                   'Other',                    'أخرى',                 'Any other memorization problem',                                        'أي مشكلة أخرى في الحفظ', 7, UTC_TIMESTAMP(), UTC_TIMESTAMP());

CREATE TABLE flip_cards (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  category_id      SMALLINT UNSIGNED NOT NULL,
  surah_number     SMALLINT UNSIGNED NOT NULL,
  ayah_number      SMALLINT UNSIGNED NOT NULL,
  page_number      SMALLINT UNSIGNED NOT NULL,
  error_note       VARCHAR(500) NOT NULL,
  context_note     VARCHAR(500) NULL,
  severity         ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  status           ENUM('active','mastered','archived') NOT NULL DEFAULT 'active',
  review_count     INT UNSIGNED NOT NULL DEFAULT 0,
  last_reviewed_at DATETIME NULL,
  next_review_at   DATETIME NULL,
  mastered_at      DATETIME NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_flip_cards_user_status (user_id, status),
  KEY idx_flip_cards_user_due (user_id, next_review_at),
  KEY idx_flip_cards_location (surah_number, ayah_number),
  CONSTRAINT fk_flip_cards_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_flip_cards_category FOREIGN KEY (category_id)
    REFERENCES flip_card_categories (id) ON DELETE RESTRICT,
  CONSTRAINT chk_flip_cards_surah CHECK (surah_number > 0),
  CONSTRAINT chk_flip_cards_ayah CHECK (ayah_number > 0),
  CONSTRAINT chk_flip_cards_page CHECK (page_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review history survives status changes (mastered/archived cards keep theirs).
CREATE TABLE flip_card_reviews (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  card_id         BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  reviewed_at     DATETIME NOT NULL,
  result          ENUM('recalled','partial','forgotten') NOT NULL,
  duration_seconds INT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_flip_card_reviews_card_date (card_id, reviewed_at),
  KEY idx_flip_card_reviews_user_date (user_id, reviewed_at),
  CONSTRAINT fk_flip_card_reviews_card FOREIGN KEY (card_id)
    REFERENCES flip_cards (id) ON DELETE CASCADE,
  CONSTRAINT fk_flip_card_reviews_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
