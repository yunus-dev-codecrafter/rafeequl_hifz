<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\DivisionType;
use App\Repositories\QuranStructureRepository;

/**
 * Quran structure calculations (Prompt 08): pages, page ranges, exact
 * locations, ordinals, divisions — all read from the canonical quran_* data.
 *
 * Fail-closed (conventions §11): an empty/unavailable dataset raises an
 * explicit error instead of any guessed value. No boundary literal lives
 * in this class; every number comes from the repository.
 */
final class QuranStructureService implements DivisionLocatorInterface
{
    private QuranStructureRepository $structure;

    /** @var array<string, int>|null */
    private ?array $datasetCounts = null;

    /** @var array{min_page: int, max_page: int, page_count: int}|null */
    private ?array $bounds = null;

    public function __construct(?QuranStructureRepository $structure = null)
    {
        $this->structure = $structure ?? new QuranStructureRepository();
    }

    /** Verifies the canonical dataset is actually loaded. Cached per instance. */
    public function assertDatasetLoaded(): void
    {
        if ($this->datasetCounts !== null) {
            return;
        }

        $counts = [
            'surahs' => $this->structure->countSurahs(),
            'ayahs' => $this->structure->countAyahs(),
            'pages' => $this->structure->countPages(),
            'juz' => $this->structure->countDivisions(DivisionType::Juz),
            'hizb' => $this->structure->countDivisions(DivisionType::Hizb),
            'rub' => $this->structure->countDivisions(DivisionType::Rub),
        ];

        foreach ($counts as $name => $count) {
            if ($count === 0) {
                throw new AppException(
                    'Quran dataset is not loaded: ' . $name . ' rows missing. Import the verified dataset first.'
                );
            }
        }

        $this->datasetCounts = $counts;
    }

    /** Provenance + counts (no boundary values invented anywhere). */
    public function datasetInfo(): array
    {
        $this->assertDatasetLoaded();
        $meta = $this->structure->datasetMeta();

        return [
            'dataset_version' => $meta['dataset_version'] ?? null,
            'surah_count' => $this->datasetCounts['surahs'],
            'ayah_count' => $this->datasetCounts['ayahs'],
            'page_count' => $this->datasetCounts['pages'],
            'division_counts' => [
                'juz' => $this->datasetCounts['juz'],
                'hizb' => $this->datasetCounts['hizb'],
                'rub' => $this->datasetCounts['rub'],
            ],
        ];
    }

    /** @return array{min_page: int, max_page: int, page_count: int} */
    public function pageBounds(): array
    {
        $this->assertDatasetLoaded();

        if ($this->bounds === null) {
            $min = $this->structure->minPage();
            $max = $this->structure->maxPage();
            if ($min === null || $max === null) {
                throw new AppException('Quran dataset is not loaded: no pages found.');
            }
            $this->bounds = [
                'min_page' => $min,
                'max_page' => $max,
                'page_count' => $this->structure->countPages(),
            ];
        }

        return $this->bounds;
    }

    /** Fails closed on page numbers the dataset cannot support ($field names the offender). */
    public function assertValidPageNumber(int $page, string $field = 'page'): void
    {
        $bounds = $this->pageBounds();
        if ($page < $bounds['min_page'] || $page > $bounds['max_page']) {
            throw ValidationException::withErrors([
                [
                    'field' => $field,
                    'message' => sprintf(
                        'Page must be between %d and %d',
                        $bounds['min_page'],
                        $bounds['max_page']
                    ),
                ],
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function pageDetails(int $page): array
    {
        $this->assertValidPageNumber($page);
        $row = $this->structure->findPage($page);
        if ($row === null) {
            throw new AppException('Dataset is inconsistent: page ' . $page . ' has no row.');
        }
        return $row;
    }

    /** Ayāh segments on a page, in reading order. @return array<int, array<string, mixed>> */
    public function pageAyahs(int $page): array
    {
        $this->assertValidPageNumber($page);
        $segments = $this->structure->segmentsOnPage($page);
        if ($segments === []) {
            throw new AppException('Dataset is inconsistent: page ' . $page . ' has no segments.');
        }
        return $segments;
    }

    /** Pages actually present in the range (density comes from the dataset, not arithmetic). */
    public function pageCountBetween(int $from, int $to): int
    {
        $this->assertPageRange($from, $to);
        return $this->structure->countPagesBetween($from, $to);
    }

    /** Page count + surahs touched by the range (spans clamped to it). */
    public function pageRange(int $from, int $to): array
    {
        $this->assertPageRange($from, $to);

        $surahs = [];
        foreach ($this->structure->surahsOverlappingPages($from, $to) as $row) {
            $surahs[] = [
                'surah_number' => (int) $row['surah_number'],
                'name_arabic' => $row['name_arabic'],
                'name_transliteration' => $row['name_transliteration'],
                'name_english' => $row['name_english'],
                'first_page' => max((int) $row['start_page'], $from),
                'last_page' => min((int) $row['end_page'], $to),
            ];
        }

        return [
            'start_page' => $from,
            'end_page' => $to,
            'page_count' => $this->structure->countPagesBetween($from, $to),
            'surahs' => $surahs,
        ];
    }

    /** Exact page of a surah:ayah (where the ayah starts). */
    public function locationToPage(int $surah, int $ayah): int
    {
        $this->assertLocationExists($surah, $ayah);
        $page = $this->structure->startPageOfAyah($surah, $ayah);
        if ($page === null) {
            throw new AppException(
                'Dataset is inconsistent: location ' . $surah . ':' . $ayah . ' sits on no page.'
            );
        }
        return $page;
    }

    /** Location with its global ordinal, start page and surah names. */
    public function locationInfo(int $surah, int $ayah): array
    {
        $ayahRow = $this->assertLocationExists($surah, $ayah);
        $surahRow = $this->structure->findSurah($surah);
        if ($surahRow === null) {
            throw new AppException('Dataset is inconsistent: surah ' . $surah . ' has no row.');
        }

        return [
            'surah_number' => (int) $ayahRow['surah_number'],
            'ayah_number' => (int) $ayahRow['ayah_number'],
            'ayah_index' => (int) $ayahRow['ayah_index'],
            'page_number' => $this->locationToPage($surah, $ayah),
            'name_arabic' => $surahRow['name_arabic'],
            'name_transliteration' => $surahRow['name_transliteration'],
            'name_english' => $surahRow['name_english'],
        ];
    }

    /** Inverse of the global ordinal arithmetic. */
    public function ordinalToLocation(int $ordinal): array
    {
        $this->assertDatasetLoaded();
        $ayahRow = $this->structure->findAyahByOrdinal($ordinal);
        if ($ayahRow === null) {
            throw new NotFoundException('Ayah ordinal not found');
        }

        $surah = (int) $ayahRow['surah_number'];
        $ayah = (int) $ayahRow['ayah_number'];

        return [
            'surah_number' => $surah,
            'ayah_number' => $ayah,
            'ayah_index' => (int) $ayahRow['ayah_index'],
            'page_number' => $this->locationToPage($surah, $ayah),
        ];
    }

    /** @return array<string, mixed> */
    public function surahDetails(int $surah): array
    {
        $this->assertDatasetLoaded();
        $row = $this->structure->findSurah($surah);
        if ($row === null) {
            throw new NotFoundException('Surah not found');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public function divisionDetails(DivisionType $type, int $number): array
    {
        $this->assertDatasetLoaded();
        $row = $this->structure->findDivision($type, $number);
        if ($row === null) {
            throw new NotFoundException('Division not found');
        }
        return $row;
    }

    /** All divisions of the type touching the page range. @return array<int, array<string, mixed>> */
    public function divisionsInRange(DivisionType $type, int $from, int $to): array
    {
        $this->assertPageRange($from, $to);
        return $this->structure->divisionsOverlappingPages($type, $from, $to);
    }

    /** Division the page belongs to (latest one to start wins on shared pages). */
    public function divisionAtPage(DivisionType $type, int $page): array
    {
        $this->assertValidPageNumber($page);
        $number = $this->divisionNumberAtPage($type, $page);
        if ($number === null) {
            throw new AppException(
                'Dataset is inconsistent: no ' . $type->value . ' division covers page ' . $page . '.'
            );
        }
        return $this->divisionDetails($type, $number);
    }

    public function divisionNumberAtPage(DivisionType $type, int $page): ?int
    {
        $this->assertDatasetLoaded();
        $rows = $this->structure->divisionsContainingPage($type, $page);
        if ($rows === []) {
            return null;
        }
        // Rows are ordered latest-start first (shared boundary pages resolve to
        // the division that has actually begun).
        return (int) $rows[0]['division_number'];
    }

    public function divisionEndPage(DivisionType $type, int $number): ?int
    {
        $this->assertDatasetLoaded();
        $row = $this->structure->findDivision($type, $number);
        return $row === null ? null : (int) $row['end_page'];
    }

    private function assertPageRange(int $from, int $to): void
    {
        $bounds = $this->pageBounds();
        if ($from < $bounds['min_page']) {
            throw ValidationException::withErrors([
                ['field' => 'from_page', 'message' => 'Page must be between ' . $bounds['min_page'] . ' and ' . $bounds['max_page']],
            ]);
        }
        if ($to > $bounds['max_page']) {
            throw ValidationException::withErrors([
                ['field' => 'to_page', 'message' => 'Page must be between ' . $bounds['min_page'] . ' and ' . $bounds['max_page']],
            ]);
        }
        if ($from > $to) {
            throw ValidationException::withErrors([
                ['field' => 'range', 'message' => 'Start page must not be after end page'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function assertLocationExists(int $surah, int $ayah): array
    {
        $this->assertDatasetLoaded();
        $row = $this->structure->findAyah($surah, $ayah);
        if ($row === null) {
            throw new NotFoundException('Quran location not found');
        }
        return $row;
    }
}
