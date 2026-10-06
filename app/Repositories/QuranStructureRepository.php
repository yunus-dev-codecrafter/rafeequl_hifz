<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\DivisionType;

/**
 * Read-only queries over the canonical quran_* tables.
 *
 * Hard rules: this repository never INSERTs/UPDATEs/DELETEs canonical data
 * (immutable reference data — data-architecture §5.11) and never contains
 * boundary literals: every value comes from the imported dataset.
 */
final class QuranStructureRepository extends Repository
{
    public function countSurahs(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM quran_surahs');
    }

    public function countAyahs(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM quran_ayahs');
    }

    public function countPages(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM quran_pages');
    }

    public function minPage(): ?int
    {
        $value = $this->scalar('SELECT MIN(page_number) FROM quran_pages');
        return $value === null ? null : (int) $value;
    }

    public function maxPage(): ?int
    {
        $value = $this->scalar('SELECT MAX(page_number) FROM quran_pages');
        return $value === null ? null : (int) $value;
    }

    public function countPagesBetween(int $from, int $to): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM quran_pages WHERE page_number BETWEEN ? AND ?',
            [$from, $to]
        );
    }

    public function countDivisions(DivisionType $type): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM quran_divisions WHERE division_type = ?',
            [$type->value]
        );
    }

    /** @return array<string, mixed>|null */
    public function findPage(int $page): ?array
    {
        return $this->fetch('SELECT * FROM quran_pages WHERE page_number = ?', [$page]);
    }

    /** @return array<string, mixed>|null */
    public function findSurah(int $surah): ?array
    {
        return $this->fetch('SELECT * FROM quran_surahs WHERE surah_number = ?', [$surah]);
    }

    /** Surahs whose span touches the page range, in canonical order. @return array<int, array<string, mixed>> */
    public function surahsOverlappingPages(int $from, int $to): array
    {
        return $this->fetchAll(
            'SELECT * FROM quran_surahs
             WHERE start_page <= ? AND end_page >= ?
             ORDER BY surah_number',
            [$to, $from]
        );
    }

    /** @return array<string, mixed>|null */
    public function findAyah(int $surah, int $ayah): ?array
    {
        return $this->fetch(
            'SELECT * FROM quran_ayahs WHERE surah_number = ? AND ayah_number = ?',
            [$surah, $ayah]
        );
    }

    /** @return array<string, mixed>|null */
    public function findAyahByOrdinal(int $ordinal): ?array
    {
        return $this->fetch('SELECT * FROM quran_ayahs WHERE ayah_index = ?', [$ordinal]);
    }

    /** Page where the ayah starts (MIN page — an ayah may span pages). */
    public function startPageOfAyah(int $surah, int $ayah): ?int
    {
        $value = $this->scalar(
            'SELECT MIN(page_number) FROM quran_page_ayahs WHERE surah_number = ? AND ayah_number = ?',
            [$surah, $ayah]
        );
        return $value === null ? null : (int) $value;
    }

    /** What sits on a page, in reading order. @return array<int, array<string, mixed>> */
    public function segmentsOnPage(int $page): array
    {
        return $this->fetchAll(
            'SELECT s.surah_number, s.ayah_number, s.segment_order,
                    s.continues_previous, s.continues_next, a.ayah_index
             FROM quran_page_ayahs s
             INNER JOIN quran_ayahs a
               ON a.surah_number = s.surah_number AND a.ayah_number = s.ayah_number
             WHERE s.page_number = ?
             ORDER BY s.segment_order',
            [$page]
        );
    }

    /** Divisions overlapping the page range, in canonical order. @return array<int, array<string, mixed>> */
    public function divisionsOverlappingPages(DivisionType $type, int $from, int $to): array
    {
        return $this->fetchAll(
            'SELECT * FROM quran_divisions
             WHERE division_type = ? AND start_page <= ? AND end_page >= ?
             ORDER BY division_number',
            [$type->value, $to, $from]
        );
    }

    /** Divisions containing the page; latest-starting first. @return array<int, array<string, mixed>> */
    public function divisionsContainingPage(DivisionType $type, int $page): array
    {
        return $this->fetchAll(
            'SELECT * FROM quran_divisions
             WHERE division_type = ? AND start_page <= ? AND end_page >= ?
             ORDER BY start_page DESC, division_number ASC',
            [$type->value, $page, $page]
        );
    }

    /** @return array<string, mixed>|null */
    public function findDivision(DivisionType $type, int $number): ?array
    {
        return $this->fetch(
            'SELECT * FROM quran_divisions WHERE division_type = ? AND division_number = ?',
            [$type->value, $number]
        );
    }

    /** @return array<string, string> */
    public function datasetMeta(): array
    {
        $rows = $this->fetchAll('SELECT meta_key, meta_value FROM quran_dataset_meta');
        $meta = [];
        foreach ($rows as $row) {
            $meta[(string) $row['meta_key']] = (string) $row['meta_value'];
        }
        return $meta;
    }
}
