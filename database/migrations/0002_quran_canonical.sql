-- ============================================================
-- Rafeequl Hifz — migration 0002: canonical Quran structure
-- Apply order: after 0001. Matches docs/quran-data/data-architecture.md §3.8.
-- Data policy: INSERTS NO QURAN DATA. Rows are loaded only by the
-- verified importer (tools/quran-data/import.php) after verification.
-- ============================================================

CREATE TABLE quran_surahs (
  surah_number        TINYINT UNSIGNED NOT NULL,
  name_arabic         VARCHAR(64)  NOT NULL,
  name_transliteration VARCHAR(64) NOT NULL,
  name_english        VARCHAR(64)  NOT NULL,
  revelation_type     ENUM('meccan','medinan') NOT NULL,
  ayah_count          SMALLINT UNSIGNED NOT NULL,
  start_page          SMALLINT UNSIGNED NOT NULL,
  end_page            SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (surah_number),
  KEY idx_quran_surahs_pages (start_page, end_page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_ayahs (
  surah_number TINYINT UNSIGNED NOT NULL,
  ayah_number  SMALLINT UNSIGNED NOT NULL,
  ayah_index   INT UNSIGNED NOT NULL,
  PRIMARY KEY (surah_number, ayah_number),
  UNIQUE KEY uq_quran_ayahs_index (ayah_index),
  CONSTRAINT fk_quran_ayahs_surah FOREIGN KEY (surah_number)
    REFERENCES quran_surahs (surah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_pages (
  page_number SMALLINT UNSIGNED NOT NULL,
  start_surah TINYINT UNSIGNED  NOT NULL,
  start_ayah  SMALLINT UNSIGNED NOT NULL,
  end_surah   TINYINT UNSIGNED  NOT NULL,
  end_ayah    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (page_number),
  CONSTRAINT fk_quran_pages_start FOREIGN KEY (start_surah, start_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number),
  CONSTRAINT fk_quran_pages_end FOREIGN KEY (end_surah, end_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_page_ayahs (
  page_number        SMALLINT UNSIGNED NOT NULL,
  surah_number       TINYINT UNSIGNED  NOT NULL,
  ayah_number        SMALLINT UNSIGNED NOT NULL,
  segment_order      SMALLINT UNSIGNED NOT NULL,
  segment_index      INT UNSIGNED NOT NULL,
  continues_previous TINYINT(1) NOT NULL DEFAULT 0,
  continues_next     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (page_number, surah_number, ayah_number),
  KEY idx_quran_page_ayahs_reverse (surah_number, ayah_number, page_number),
  UNIQUE KEY uq_quran_page_ayahs_index (segment_index),
  CONSTRAINT fk_quran_page_ayahs_page FOREIGN KEY (page_number)
    REFERENCES quran_pages (page_number),
  CONSTRAINT fk_quran_page_ayahs_ayah FOREIGN KEY (surah_number, ayah_number)
    REFERENCES quran_ayahs (surah_number, ayah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_division_types (
  type_key        VARCHAR(24) NOT NULL,
  name_arabic     VARCHAR(64) NOT NULL,
  name_english    VARCHAR(64) NOT NULL,
  parent_type_key VARCHAR(24) NULL,
  per_parent      SMALLINT UNSIGNED NULL,
  count_expected  SMALLINT UNSIGNED NULL,
  PRIMARY KEY (type_key),
  CONSTRAINT fk_quran_division_types_parent FOREIGN KEY (parent_type_key)
    REFERENCES quran_division_types (type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_divisions (
  division_type   VARCHAR(24) NOT NULL,
  division_number SMALLINT UNSIGNED NOT NULL,
  parent_type     VARCHAR(24) NULL,
  parent_number   SMALLINT UNSIGNED NULL,
  start_surah TINYINT UNSIGNED  NOT NULL,
  start_ayah  SMALLINT UNSIGNED NOT NULL,
  end_surah   TINYINT UNSIGNED  NOT NULL,
  end_ayah    SMALLINT UNSIGNED NOT NULL,
  start_page  SMALLINT UNSIGNED NOT NULL,
  end_page    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (division_type, division_number),
  KEY idx_quran_divisions_overlap (division_type, start_page, end_page),
  KEY idx_quran_divisions_range (start_page, end_page),
  CONSTRAINT fk_quran_divisions_type FOREIGN KEY (division_type)
    REFERENCES quran_division_types (type_key),
  CONSTRAINT fk_quran_divisions_start FOREIGN KEY (start_surah, start_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number),
  CONSTRAINT fk_quran_divisions_end FOREIGN KEY (end_surah, end_ayah)
    REFERENCES quran_ayahs (surah_number, ayah_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quran_dataset_meta (
  meta_key   VARCHAR(64) NOT NULL,
  meta_value TEXT NULL,
  PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
