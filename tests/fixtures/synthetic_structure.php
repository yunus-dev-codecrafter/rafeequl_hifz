<?php

declare(strict_types=1);

/**
 * SYNTHETIC structural fixture for calculation tests — NOT Quran data.
 *
 * An arbitrary, internally consistent grid: 4 fictional surahs of 8 ayahs
 * over 16 pages, with one ayah (3:8) spanning pages 12→13, plus fictional
 * juz/hizb/rub divisions (two shared-boundary cases exercise ambiguity).
 * These values are fabricated on purpose and must NEVER be presented as
 * canonical Quran boundaries; the real dataset arrives via the verified
 * importer (data-architecture §7).
 */

return [
    'meta' => [
        'dataset_version' => 'synthetic-fixture-1',
        'description' => 'synthetic test structure — not Quran data',
    ],

    // surah_number => names + revelation (ayah counts and page spans are derived)
    'surahs' => [
        1 => ['name_arabic' => 'سورة تجريبية أولى', 'name_transliteration' => 'Test Alpha', 'name_english' => 'Test Alpha', 'revelation_type' => 'meccan'],
        2 => ['name_arabic' => 'سورة تجريبية ثانية', 'name_transliteration' => 'Test Beta', 'name_english' => 'Test Beta', 'revelation_type' => 'meccan'],
        3 => ['name_arabic' => 'سورة تجريبية ثالثة', 'name_transliteration' => 'Test Gamma', 'name_english' => 'Test Gamma', 'revelation_type' => 'medinan'],
        4 => ['name_arabic' => 'سورة تجريبية رابعة', 'name_transliteration' => 'Test Delta', 'name_english' => 'Test Delta', 'revelation_type' => 'meccan'],
    ],

    // page_number => ordered 'surah:ayah' segments; 3:8 repeats on page 13
    'page_segments' => [
        1 => ['1:1', '1:2'],
        2 => ['1:3', '1:4'],
        3 => ['1:5', '1:6'],
        4 => ['1:7', '1:8'],
        5 => ['2:1', '2:2'],
        6 => ['2:3', '2:4'],
        7 => ['2:5', '2:6'],
        8 => ['2:7', '2:8'],
        9 => ['3:1', '3:2'],
        10 => ['3:3', '3:4'],
        11 => ['3:5', '3:6'],
        12 => ['3:7', '3:8'],
        13 => ['3:8', '4:1', '4:2'],
        14 => ['4:3', '4:4'],
        15 => ['4:5', '4:6'],
        16 => ['4:7', '4:8'],
    ],

    // type_key, name_arabic, name_english, parent_type_key, per_parent, count_expected
    'division_types' => [
        ['juz', 'الجزء', 'Juz', null, null, 2],
        ['hizb', 'الحزب', 'Hizb', 'juz', 2, 4],
        ['rub', 'الربع', 'Rub', 'hizb', 2, 8],
    ],

    // type, number, parent_type, parent_number, start 's:a', end 's:a', start_page, end_page
    // Page spans use the ayah's FIRST page (data-architecture §3.4 MIN rule).
    'divisions' => [
        ['juz', 1, null, null, '1:1', '2:8', 1, 8],
        ['juz', 2, null, null, '2:8', '4:8', 8, 16],   // shares ayah 2:8 → page 8 sits in both juz
        ['hizb', 1, 'juz', 1, '1:1', '1:8', 1, 4],
        ['hizb', 2, 'juz', 1, '2:1', '2:8', 5, 8],
        ['hizb', 3, 'juz', 2, '3:1', '3:8', 9, 12],
        ['hizb', 4, 'juz', 2, '4:1', '4:8', 13, 16],
        ['rub', 1, 'hizb', 1, '1:1', '1:4', 1, 2],
        ['rub', 2, 'hizb', 1, '1:5', '1:8', 3, 4],
        ['rub', 3, 'hizb', 2, '2:1', '2:4', 5, 6],
        ['rub', 4, 'hizb', 2, '2:5', '2:8', 7, 8],
        ['rub', 5, 'hizb', 3, '3:1', '3:5', 9, 11],
        ['rub', 6, 'hizb', 3, '3:5', '3:8', 11, 12],   // shares ayah 3:5 → page 11 sits in both rubs
        ['rub', 7, 'hizb', 4, '4:1', '4:4', 13, 14],
        ['rub', 8, 'hizb', 4, '4:5', '4:8', 15, 16],
    ],
];
