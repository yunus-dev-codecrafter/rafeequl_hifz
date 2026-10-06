<?php

declare(strict_types=1);

/**
 * Shared library for the canonical Quran data pipeline
 * (tools/quran-data/*.php). Authority: docs/quran-data/data-architecture.md
 * §5 (integrity), §7 (import), §8 (verification).
 *
 * Rules honoured by every function here:
 *  - Nothing Quran-structural is hard-coded; every value is derived from
 *    the acquired source files (invariants in QURAN_INVARIANTS are
 *    verification-only checks, never logic inputs).
 *  - Fail closed: violations throw QuranDataError; writers build content
 *    in memory and write atomically only after validation.
 *  - Checks are pure over the dataset shape so verify.php (files) and
 *    audit.php / CanonicalDatasetTest (database rows) run identical tests.
 */

if (!defined('BASE_PATH')) {
    require dirname(__DIR__, 2) . '/app/bootstrap.php';
}

final class QuranDataError extends RuntimeException
{
}

/** @param never $message */
function quranFail(string $message): never
{
    throw new QuranDataError($message);
}

/**
 * Source registry (prompt 24A §2): primary structural source plus an
 * independent cross-check source. Keys are stable identifiers used in
 * file names, manifests and reports.
 */
const QURAN_SOURCES = [
    'tanzil_metadata' => [
        'label' => 'Tanzil Quran Metadata (quran-data.xml)',
        'role' => 'primary',
        'url' => 'https://tanzil.net/res/text/metadata/quran-data.xml',
        'file' => 'quran-data.xml',
        'license' => 'CC BY 3.0 + Tanzil terms of use',
        'license_url' => 'https://tanzil.net/docs/text_license',
        'copyright' => '(C) 2008-2009 Tanzil.info',
        'contains' => 'Sura list (numbers, ayah counts, 0-based global starts, Arabic/transliteration/English names, revelation type, ruku counts), juz starts (30), rub/quarter starts (240), Madinah page starts (604), manzil/ruku/sajda metadata',
    ],
    'alquran_quran' => [
        'label' => 'alquran.cloud full Quran (quran-uthmani edition)',
        'role' => 'cross-check',
        'url' => 'https://api.alquran.cloud/v1/quran/quran-uthmani',
        'file' => 'alquran-quran-uthmani.json',
        'license' => 'alquran.cloud terms and conditions (fair use with attribution)',
        'license_url' => 'https://alquran.cloud/terms-and-conditions',
        'copyright' => 'API by Islamic Network (alquran.cloud)',
        'contains' => 'Per-ayah structural fields: global number, numberInSurah, juz, manzil, page, ruku, hizbQuarter (ayah text is parsed for position only and never stored)',
    ],
    'alquran_meta' => [
        'label' => 'alquran.cloud meta',
        'role' => 'cross-check',
        'url' => 'https://api.alquran.cloud/v1/meta',
        'file' => 'alquran-meta.json',
        'license' => 'alquran.cloud terms and conditions (fair use with attribution)',
        'license_url' => 'https://alquran.cloud/terms-and-conditions',
        'copyright' => 'API by Islamic Network (alquran.cloud)',
        'contains' => 'Surah list (114), juz start references (30), rub start references (240), page start references (604), ayah total (6236), ruku and sajda lists',
    ],
];

/**
 * Verification-only expected totals (prompt 24A §6, data-architecture
 * §5.12). Confirmed against both sources at verify time; never used to
 * build or repair data.
 */
const QURAN_INVARIANTS = [
    'surahs' => 114,
    'ayahs' => 6236,
    'pages' => 604,
    'juz' => 30,
    'hizb' => 60,
    'rub' => 240,
];

const QURAN_TOOL_VERSION = '1';

/** UI vocabulary for division type labels (not source structural data). */
const QURAN_DIVISION_LABELS = [
    'juz' => ['ar' => 'الجزء', 'en' => 'Juz'],
    'hizb' => ['ar' => 'الحزب', 'en' => 'Hizb'],
    'rub' => ['ar' => 'الربع', 'en' => 'Rub'],
];

function quranSourceDir(): string
{
    return BASE_PATH . '/data/quran/madinah/source';
}

function quranProcessedDir(): string
{
    return BASE_PATH . '/data/quran/madinah/processed';
}

function quranVerificationDir(): string
{
    return BASE_PATH . '/data/quran/madinah/verification';
}

/** @return array<string, string> processed section => absolute path */
function quranProcessedFiles(): array
{
    $files = [];
    foreach (['surahs', 'ayahs', 'pages', 'page_ayahs', 'divisions', 'division_types', 'meta'] as $section) {
        $files[$section] = quranProcessedDir() . '/' . $section . '.json';
    }
    return $files;
}

function quranEnsureDirs(): void
{
    foreach ([quranSourceDir(), quranProcessedDir(), quranVerificationDir()] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            quranFail("cannot create directory {$dir}");
        }
    }
}

// ---------------------------------------------------------------------------
// IO helpers
// ---------------------------------------------------------------------------

function quranWriteFile(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        quranFail("cannot create directory {$dir}");
    }
    $tmp = $dir . '/' . basename($path) . '.tmp' . getmypid();
    if (file_put_contents($tmp, $content) === false) {
        quranFail("cannot write {$tmp}");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        quranFail("cannot move {$tmp} into place at {$path}");
    }
}

function quranSha256File(string $path): string
{
    if (!is_file($path)) {
        quranFail("missing file {$path}");
    }
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        quranFail("cannot hash {$path}");
    }
    return $hash;
}

function quranEncodeJson(mixed $data): string
{
    try {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        quranFail('JSON encoding failed: ' . $e->getMessage());
    }
    return $json . "\n";
}

/** @return array<mixed> */
function quranDecodeJson(string $content, string $label): array
{
    try {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        quranFail("invalid JSON in {$label}: " . $e->getMessage());
    }
    if (!is_array($data)) {
        quranFail("JSON root of {$label} is not an object/array");
    }
    return $data;
}

/** @return array<mixed> */
function quranReadJson(string $path): array
{
    if (!is_file($path)) {
        quranFail("missing file {$path} (run the previous pipeline stage first)");
    }
    return quranDecodeJson((string) file_get_contents($path), basename($path));
}

function quranHttpGet(string $url, int $timeout = 90): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'method' => 'GET',
            'follow_location' => 1,
            'ignore_errors' => true,
            'header' => "User-Agent: RafeequlHifz-quran-data/1\r\nAccept: */*\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $headers = $http_response_header ?? [];
    if (isset($headers[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headers[0], $m) === 1) {
        $status = (int) $m[1];
    }
    if ($body === false || $status !== 200) {
        quranFail("HTTP {$status} fetching {$url} (internet access required; nothing was written)");
    }
    return $body;
}

// ---------------------------------------------------------------------------
// .sha256 sidecars (standard "<hex>  <file>" format)
// ---------------------------------------------------------------------------

function quranWriteSidecar(string $path): string
{
    $sha = quranSha256File($path);
    quranWriteFile($path . '.sha256', $sha . '  ' . basename($path) . "\n");
    return $sha;
}

function quranVerifySidecar(string $path): string
{
    $sidecar = $path . '.sha256';
    if (!is_file($sidecar)) {
        quranFail('missing sidecar ' . basename($sidecar) . ' (run acquire.php)');
    }
    $expected = strtolower(trim(preg_split('/\s+/', (string) file_get_contents($sidecar))[0] ?? ''));
    $actual = quranSha256File($path);
    if ($expected === '' || !hash_equals($expected, $actual)) {
        quranFail(sprintf(
            'checksum mismatch for %s: sidecar says %s, file is %s (source/ is immutable; re-run acquire.php)',
            basename($path),
            $expected === '' ? '(empty)' : $expected,
            $actual
        ));
    }
    return $actual;
}

// ---------------------------------------------------------------------------
// Source parsers (no derivation here - raw extraction only)
// ---------------------------------------------------------------------------

/**
 * @return array{
 *   version: string, license: string, copyright: string,
 *   suras: array<int, array<string, mixed>>,
 *   juzs: array<int, array{int, int}>,
 *   quarters: array<int, array{int, int}>,
 *   pages: array<int, array{int, int}>
 * }
 */
function quranParseTanzil(string $path): array
{
    if (!is_file($path)) {
        quranFail('missing ' . basename($path) . ' (run acquire.php)');
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        $detail = $errors !== [] ? trim($errors[0]->message) : 'unknown XML error';
        quranFail('Tanzil XML parse failed: ' . $detail);
    }
    if ($xml->getName() !== 'quran') {
        quranFail('Tanzil XML root element must be <quran>, found <' . $xml->getName() . '>');
    }
    $root = $xml->attributes();
    $type = (string) $root->type;
    $version = (string) $root->version;
    $license = (string) $root->license;
    if ($type !== 'metadata' || $version === '' || $license === '') {
        quranFail("Tanzil XML root must carry type=\"metadata\" plus version and license attributes (saw type={$type} version={$version} license={$license})");
    }

    $suras = [];
    foreach (($xml->xpath('//sura') ?: []) as $node) {
        $a = $node->attributes();
        $row = [
            'index' => (int) $a->index,
            'ayas' => (int) $a->ayas,
            'start' => (int) $a->start,
            'name' => (string) $a->name,
            'tname' => (string) $a->tname,
            'ename' => (string) $a->ename,
            'type' => (string) $a->type,
            'order' => (int) $a->order,
            'rukus' => (int) $a->rukus,
        ];
        if (isset($suras[$row['index']])) {
            quranFail('Tanzil XML contains duplicate sura index ' . $row['index']);
        }
        $suras[$row['index']] = $row;
    }

    $refs = [];
    foreach ([['juz', '//juz', 'juzs'], ['quarter', '//quarter', 'quarters'], ['page', '//page', 'pages']] as [$element, $xpath, $bucket]) {
        $list = [];
        foreach (($xml->xpath($xpath) ?: []) as $node) {
            $a = $node->attributes();
            $index = (int) $a->index;
            if (isset($list[$index])) {
                quranFail("Tanzil XML contains duplicate {$element} index {$index}");
            }
            $list[$index] = [(int) $a->sura, (int) $a->aya];
        }
        $refs[$bucket] = $list;
    }

    if ($suras === [] || $refs['juzs'] === [] || $refs['quarters'] === [] || $refs['pages'] === []) {
        quranFail('Tanzil XML is missing required sections (sura/juz/quarter/page)');
    }

    return [
        'version' => $version,
        'license' => $license,
        'copyright' => (string) $root->copyright,
        'suras' => $suras,
        'juzs' => $refs['juzs'],
        'quarters' => $refs['quarters'],
        'pages' => $refs['pages'],
    ];
}

/**
 * @return array{
 *   suras: array<int, array<string, mixed>>,
 *   ayahs: array<int, array<string, int>>
 * }
 */
function quranParseAlQuran(string $path): array
{
    if (!is_file($path)) {
        quranFail('missing ' . basename($path) . ' (run acquire.php)');
    }
    $json = quranReadJson($path);
    $data = $json['data'] ?? null;
    $surahs = is_array($data) ? ($data['surahs'] ?? null) : null;
    if (!is_array($surahs) || $surahs === [] || !array_is_list($surahs)) {
        quranFail('alquran quran file must contain data.surahs as a list');
    }

    $suras = [];
    $ayahs = [];
    foreach ($surahs as $position => $surah) {
        if (!is_array($surah) || !isset($surah['number'], $surah['ayahs']) || !is_array($surah['ayahs'])) {
            quranFail('alquran surah entry ' . $position . ' is malformed');
        }
        $number = (int) $surah['number'];
        if ($number !== $position + 1) {
            quranFail("alquran surah list must be ordered 1..n (position {$position} has number {$number})");
        }
        $suras[$number] = [
            'number' => $number,
            'name' => (string) ($surah['name'] ?? ''),
            'english_name' => (string) ($surah['englishName'] ?? ''),
            'english_name_translation' => (string) ($surah['englishNameTranslation'] ?? ''),
            'revelation_type' => (string) ($surah['revelationType'] ?? ''),
            'ayah_count' => count($surah['ayahs']),
        ];
        foreach ($surah['ayahs'] as $ayah) {
            if (!is_array($ayah) || !isset($ayah['number'])) {
                quranFail("alquran ayah inside surah {$number} is malformed");
            }
            $global = (int) $ayah['number'];
            if (isset($ayahs[$global])) {
                quranFail("alquran duplicate global ayah number {$global}");
            }
            $ayahs[$global] = [
                'surah' => $number,
                'ayah' => (int) ($ayah['numberInSurah'] ?? 0),
                'global' => $global,
                'page' => (int) ($ayah['page'] ?? 0),
                'juz' => (int) ($ayah['juz'] ?? 0),
                'rub' => (int) ($ayah['hizbQuarter'] ?? 0),
                'manzil' => (int) ($ayah['manzil'] ?? 0),
            ];
        }
    }

    return ['suras' => $suras, 'ayahs' => $ayahs];
}

/**
 * @return array{
 *   suras: array<int, array<string, mixed>>,
 *   juz_starts: array<int, array{int, int}>,
 *   quarter_starts: array<int, array{int, int}>,
 *   page_starts: array<int, array{int, int}>,
 *   counts: array<string, int>
 * }
 */
function quranParseAlQuranMeta(string $path): array
{
    if (!is_file($path)) {
        quranFail('missing ' . basename($path) . ' (run acquire.php)');
    }
    $json = quranReadJson($path);
    $data = $json['data'] ?? null;
    if (!is_array($data)) {
        quranFail('alquran meta file must contain a data object');
    }

    $surahRefs = $data['surahs']['references'] ?? null;
    if (!is_array($surahRefs) || $surahRefs === []) {
        quranFail('alquran meta data.surahs.references missing');
    }
    $suras = [];
    foreach ($surahRefs as $position => $surah) {
        if (!is_array($surah) || (int) ($surah['number'] ?? 0) !== $position + 1) {
            quranFail('alquran meta sura list must be ordered 1..n');
        }
        $suras[$position + 1] = [
            'number' => $position + 1,
            'name' => (string) ($surah['name'] ?? ''),
            'english_name' => (string) ($surah['englishName'] ?? ''),
            'english_name_translation' => (string) ($surah['englishNameTranslation'] ?? ''),
            'revelation_type' => (string) ($surah['revelationType'] ?? ''),
            'ayah_count' => (int) ($surah['numberOfAyahs'] ?? 0),
        ];
    }

    $buckets = [];
    foreach (['juzs' => 'juz_starts', 'hizbQuarters' => 'quarter_starts', 'pages' => 'page_starts'] as $source => $target) {
        $references = $data[$source]['references'] ?? null;
        if (!is_array($references) || $references === []) {
            quranFail("alquran meta data.{$source}.references missing");
        }
        $list = [];
        foreach ($references as $position => $ref) {
            if (!is_array($ref)) {
                quranFail("alquran meta {$source} reference {$position} is malformed");
            }
            $list[$position + 1] = [(int) ($ref['surah'] ?? 0), (int) ($ref['ayah'] ?? 0)];
        }
        $buckets[$target] = $list;
    }

    $ayahTotal = (int) ($data['ayahs']['count'] ?? 0);
    if ($ayahTotal === 0) {
        quranFail('alquran meta data.ayahs.count missing');
    }

    return [
        'suras' => $suras,
        'juz_starts' => $buckets['juz_starts'],
        'quarter_starts' => $buckets['quarter_starts'],
        'page_starts' => $buckets['page_starts'],
        'counts' => [
            'surahs' => count($suras),
            'juz' => count($buckets['juz_starts']),
            'rub' => count($buckets['quarter_starts']),
            'pages' => count($buckets['page_starts']),
            'ayahs' => $ayahTotal,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Derivation: source files -> processed dataset rows (the only place
// structural rows are ever created)
// ---------------------------------------------------------------------------

/**
 * @param array<string, string> $sourceChecksums file basename => sha256
 * @return array{
 *   surahs: list<array<string, mixed>>,
 *   ayahs: list<array<string, int>>,
 *   pages: list<array<string, int>>,
 *   page_ayahs: list<array<string, int>>,
 *   divisions: list<array<string, int|null>>,
 *   division_types: list<array<string, mixed>>,
 *   meta: array<string, string>
 * }
 */
function quranDeriveDataset(string $tanzilPath, string $aqPath, string $aqMetaPath, array $sourceChecksums): array
{
    $inv = QURAN_INVARIANTS;
    $tanzil = quranParseTanzil($tanzilPath);
    $aq = quranParseAlQuran($aqPath);
    $aqMeta = quranParseAlQuranMeta($aqMetaPath);

    // --- Tanzil source shape -------------------------------------------------
    $suras = $tanzil['suras'];
    ksort($suras);
    if (array_keys($suras) !== range(1, $inv['surahs'])) {
        quranFail('Tanzil sura list must be exactly 1..' . $inv['surahs']);
    }
    $start0 = [];
    $totalAyahs = 0;
    foreach ($suras as $s => $row) {
        if ($row['ayas'] <= 0 || $row['start'] < 0) {
            quranFail("Tanzil sura {$s} has invalid ayas/start attributes");
        }
        if (!in_array(strtolower($row['type']), ['meccan', 'medinan'], true)) {
            quranFail("Tanzil sura {$s} has unexpected revelation type '{$row['type']}'");
        }
        if (mb_strlen($row['name']) > 64 || mb_strlen($row['tname']) > 64 || mb_strlen($row['ename']) > 64) {
            quranFail("Tanzil sura {$s} name fields exceed VARCHAR(64)");
        }
        $start0[$s] = $row['start'];
        $totalAyahs += $row['ayas'];
    }
    foreach ($suras as $s => $row) {
        if ($s === 1) {
            if ($row['start'] !== 0) {
                quranFail('Tanzil sura 1 must start at 0');
            }
            continue;
        }
        $expected = $start0[$s - 1] + $suras[$s - 1]['ayas'];
        if ($row['start'] !== $expected) {
            quranFail("Tanzil sura {$s} start {$row['start']} does not follow sura " . ($s - 1) . " (expected {$expected})");
        }
    }
    if ($totalAyahs !== $inv['ayahs']) {
        quranFail("Tanzil ayah total {$totalAyahs} does not match the expected invariant {$inv['ayahs']}");
    }
    if (count($tanzil['juzs']) !== $inv['juz'] || count($tanzil['quarters']) !== $inv['rub'] || count($tanzil['pages']) !== $inv['pages']) {
        quranFail(sprintf(
            'Tanzil section sizes wrong: juz=%d quarter=%d page=%d (expected %d/%d/%d)',
            count($tanzil['juzs']),
            count($tanzil['quarters']),
            count($tanzil['pages']),
            $inv['juz'],
            $inv['rub'],
            $inv['pages']
        ));
    }
    foreach ([['juz', $tanzil['juzs'], $inv['juz']], ['quarter', $tanzil['quarters'], $inv['rub']], ['page', $tanzil['pages'], $inv['pages']]] as [$label, $list, $expectedCount]) {
        if (array_keys($list) !== range(1, $expectedCount)) {
            quranFail("Tanzil {$label} indices must be exactly 1..{$expectedCount}");
        }
        foreach ($list as $index => $ref) {
            quranRefOrdinal($start0, $suras, $ref[0], $ref[1], "Tanzil {$label} {$index}");
        }
    }

    // --- ref <-> global ordinal (1-based) -----------------------------------
    $ordinalOf = static function (int $s, int $a) use ($start0, $suras): int {
        return quranRefOrdinal($start0, $suras, $s, $a, 'ref');
    };
    $refOf = static function (int $ord) use ($start0, $suras): array {
        return quranOrdinalRef($start0, $suras, $ord);
    };

    // --- ayah rows (dense by construction) ----------------------------------
    $ayahRows = [];
    $expectedOrdinal = 0;
    foreach ($suras as $s => $row) {
        for ($a = 1; $a <= $row['ayas']; $a++) {
            $expectedOrdinal++;
            $ordinal = $start0[$s] + $a;
            if ($ordinal !== $expectedOrdinal) {
                quranFail("ayah ordinal gap at {$s}:{$a} (got {$ordinal}, expected {$expectedOrdinal})");
            }
            $ayahRows[] = ['surah_number' => $s, 'ayah_number' => $a, 'ayah_index' => $ordinal];
        }
    }
    if (count($ayahRows) !== $inv['ayahs']) {
        quranFail('derived ayah row count mismatch');
    }

    // --- alquran cross-source: numbering + per-ayah pages --------------------
    if (count($aq['ayahs']) !== $inv['ayahs']) {
        quranFail('alquran ayah row count is ' . count($aq['ayahs']) . ", expected {$inv['ayahs']}");
    }
    if ($aqMeta['counts']['ayahs'] !== $inv['ayahs']) {
        quranFail('alquran meta ayah total is ' . $aqMeta['counts']['ayahs'] . ", expected {$inv['ayahs']}");
    }
    if (count($aq['suras']) !== $inv['surahs'] || $aqMeta['counts']['surahs'] !== $inv['surahs']) {
        quranFail('alquran surah count mismatch');
    }
    if ($aqMeta['counts']['juz'] !== $inv['juz'] || $aqMeta['counts']['rub'] !== $inv['rub'] || $aqMeta['counts']['pages'] !== $inv['pages']) {
        quranFail('alquran meta section counts do not match expected totals');
    }

    $pageOf = [];
    foreach ($ayahRows as $row) {
        $ord = $row['ayah_index'];
        $aqAyah = $aq['ayahs'][$ord] ?? null;
        if ($aqAyah === null) {
            quranFail("alquran is missing global ayah {$ord}");
        }
        if ($aqAyah['global'] !== $ord
            || $aqAyah['surah'] !== $row['surah_number']
            || $aqAyah['ayah'] !== $row['ayah_number']
        ) {
            quranFail("alquran numbering disagrees at ordinal {$ord} ({$aqAyah['surah']}:{$aqAyah['ayah']} vs {$row['surah_number']}:{$row['ayah_number']})");
        }
        if ($aqAyah['page'] < 1 || $aqAyah['page'] > $inv['pages']) {
            quranFail("alquran page out of range for ordinal {$ord}: {$aqAyah['page']}");
        }
        if ($aqAyah['juz'] < 1 || $aqAyah['juz'] > $inv['juz']) {
            quranFail("alquran juz out of range for ordinal {$ord}: {$aqAyah['juz']}");
        }
        if ($aqAyah['rub'] < 1 || $aqAyah['rub'] > $inv['rub']) {
            quranFail("alquran rub out of range for ordinal {$ord}: {$aqAyah['rub']}");
        }
        $pageOf[$ord] = $aqAyah['page'];
    }
    if ($pageOf[1] !== 1) {
        quranFail('first ayah must start on page 1 (alquran says ' . $pageOf[1] . ')');
    }
    for ($ord = 1; $ord < $inv['ayahs']; $ord++) {
        $delta = $pageOf[$ord + 1] - $pageOf[$ord];
        if ($delta < 0 || $delta > 1) {
            quranFail("alquran start-page sequence not monotone (+0/+1) at ordinal {$ord}");
        }
    }

    $pageStartOrd = [];
    foreach ($tanzil['pages'] as $p => $ref) {
        $pageStartOrd[$p] = $ordinalOf($ref[0], $ref[1]);
    }
    for ($p = 1; $p <= $inv['pages']; $p++) {
        if ($pageStartOrd[$p] < 1 || $pageStartOrd[$p] > $inv['ayahs']) {
            quranFail("Tanzil page {$p} start ordinal out of range");
        }
        if ($p > 1 && $pageStartOrd[$p] <= $pageStartOrd[$p - 1]) {
            quranFail("Tanzil page starts must strictly increase (page {$p})");
        }
        $allowed = $p === 1 ? [1] : [$p - 1, $p];
        if (!in_array($pageOf[$pageStartOrd[$p]], $allowed, true)) {
            quranFail(sprintf(
                'page %d starts at ordinal %d but alquran says it begins on page %d (allowed %s)',
                $p,
                $pageStartOrd[$p],
                $pageOf[$pageStartOrd[$p]],
                implode('/', $allowed)
            ));
        }
    }

    // --- page end ordinals (straddle resolution) + P-only cross-derivation ---
    $endOrd = [];
    for ($p = 1; $p <= $inv['pages']; $p++) {
        if ($p === $inv['pages']) {
            $endOrd[$p] = $inv['ayahs'];
            continue;
        }
        $nextStart = $pageStartOrd[$p + 1];
        $endOrd[$p] = $pageOf[$nextStart] === $p ? $nextStart : $nextStart - 1;
        if ($endOrd[$p] < $pageStartOrd[$p]) {
            quranFail("page {$p} would be empty (start {$pageStartOrd[$p]} > end {$endOrd[$p]})");
        }
    }

    // --- page adjacency -----------------------------------------------------
    for ($p = 2; $p <= $inv['pages']; $p++) {
        if (!in_array($pageStartOrd[$p], [$endOrd[$p - 1], $endOrd[$p - 1] + 1], true)) {
            quranFail("page adjacency broken before page {$p} (start {$pageStartOrd[$p]} after end {$endOrd[$p - 1]})");
        }
    }

    // --- segments ------------------------------------------------------------
    $segments = [];
    $segmentIndex = 0;
    $prevEnd = 0;
    for ($p = 1; $p <= $inv['pages']; $p++) {
        $order = 0;
        for ($ord = $pageStartOrd[$p]; $ord <= $endOrd[$p]; $ord++) {
            $order++;
            $segmentIndex++;
            [$s, $a] = $refOf($ord);
            $continuesPrevious = ($p > 1 && $ord === $pageStartOrd[$p] && $ord === $prevEnd) ? 1 : 0;
            $continuesNext = ($p < $inv['pages'] && $ord === $endOrd[$p] && $endOrd[$p] === $pageStartOrd[$p + 1]) ? 1 : 0;
            $segments[] = [
                'page_number' => $p,
                'surah_number' => $s,
                'ayah_number' => $a,
                'segment_order' => $order,
                'segment_index' => $segmentIndex,
                'continues_previous' => $continuesPrevious,
                'continues_next' => $continuesNext,
            ];
        }
        $prevEnd = $endOrd[$p];
    }

    // Redundant view: continues flags must agree with alquran start pages.
    foreach ($segments as $segment) {
        $ord = $start0[$segment['surah_number']] + $segment['ayah_number'];
        if ($segment['continues_previous'] === 1 && $pageOf[$ord] >= $segment['page_number']) {
            quranFail("continues_previous flag disagrees with alquran at {$segment['surah_number']}:{$segment['ayah_number']} page {$segment['page_number']}");
        }
        if ($segment['continues_previous'] === 0 && $segment['segment_order'] === 1 && $segment['page_number'] > 1 && $pageOf[$ord] === $segment['page_number'] - 1) {
            quranFail("missing continues_previous at {$segment['surah_number']}:{$segment['ayah_number']} page {$segment['page_number']}");
        }
    }

    // Cross-representation sweep: the first page each ayah appears on
    // (segments) must equal the alquran per-ayah start page for ALL ayahs,
    // and every ayah must end where the next one begins (+0/+1 pages).
    $minPageOf = [];
    $maxPageOf = [];
    foreach ($segments as $segment) {
        $ord = $start0[$segment['surah_number']] + $segment['ayah_number'];
        $p = $segment['page_number'];
        $minPageOf[$ord] = min($minPageOf[$ord] ?? $p, $p);
        $maxPageOf[$ord] = max($maxPageOf[$ord] ?? $p, $p);
    }
    for ($ord = 1; $ord <= $inv['ayahs']; $ord++) {
        if (!isset($minPageOf[$ord])) {
            quranFail("ordinal {$ord} has no segments after derivation");
        }
        if ($minPageOf[$ord] !== $pageOf[$ord]) {
            quranFail("page mapping disagreement at ordinal {$ord}: segments say page {$minPageOf[$ord]}, alquran says {$pageOf[$ord]}");
        }
        if ($ord < $inv['ayahs'] && !in_array($pageOf[$ord + 1], [$maxPageOf[$ord], $maxPageOf[$ord] + 1], true)) {
            quranFail("page break continuity violated after ordinal {$ord} (next starts {$pageOf[$ord + 1]}, this ends {$maxPageOf[$ord]})");
        }
    }

    // --- page rows (first/last segment) -------------------------------------
    $pageRows = [];
    $firstByPage = [];
    $lastByPage = [];
    foreach ($segments as $segment) {
        $p = $segment['page_number'];
        $firstByPage[$p] ??= $segment;
        $lastByPage[$p] = $segment;
    }
    for ($p = 1; $p <= $inv['pages']; $p++) {
        $first = $firstByPage[$p] ?? null;
        $last = $lastByPage[$p] ?? null;
        if ($first === null || $last === null) {
            quranFail("page {$p} has no segments");
        }
        $pageRows[] = [
            'page_number' => $p,
            'start_surah' => $first['surah_number'],
            'start_ayah' => $first['ayah_number'],
            'end_surah' => $last['surah_number'],
            'end_ayah' => $last['ayah_number'],
        ];
    }

    // --- surah rows ----------------------------------------------------------
    $surahRows = [];
    foreach ($suras as $s => $row) {
        $firstOrd = $start0[$s] + 1;
        $lastOrd = $start0[$s] + $row['ayas'];
        $surahRows[] = [
            'surah_number' => $s,
            'name_arabic' => $row['name'],
            'name_transliteration' => $row['tname'],
            'name_english' => $row['ename'],
            'revelation_type' => strtolower($row['type']),
            'ayah_count' => $row['ayas'],
            'start_page' => $minPageOf[$firstOrd],
            'end_page' => $maxPageOf[$lastOrd],
        ];
    }

    // --- divisions -----------------------------------------------------------
    $divisionStarts = [
        'juz' => [],
        'hizb' => [],
        'rub' => [],
    ];
    foreach ($tanzil['juzs'] as $n => $ref) {
        $divisionStarts['juz'][$n] = $ordinalOf($ref[0], $ref[1]);
    }
    foreach ($tanzil['quarters'] as $n => $ref) {
        $divisionStarts['rub'][$n] = $ordinalOf($ref[0], $ref[1]);
    }
    if (count($tanzil['quarters']) % 4 !== 0) {
        quranFail('rub count is not a multiple of 4; hizb derivation impossible');
    }
    for ($h = 1; $h <= $inv['hizb']; $h++) {
        $quarterIndex = 4 * ($h - 1) + 1;
        $divisionStarts['hizb'][$h] = $divisionStarts['rub'][$quarterIndex];
    }
    if (count($divisionStarts['hizb']) !== $inv['hizb']) {
        quranFail('derived hizb count mismatch');
    }

    $divisionRows = [];
    foreach ($divisionStarts as $type => $starts) {
        if (array_keys($starts) !== range(1, count($starts))) {
            quranFail("{$type} numbering must be 1.." . count($starts));
        }
        $previous = 0;
        foreach ($starts as $n => $startOrd) {
            if ($startOrd <= $previous) {
                quranFail("{$type} {$n} starts at {$startOrd} but previous division ends at {$previous} (strict increase required)");
            }
            $endN = isset($starts[$n + 1]) ? $starts[$n + 1] - 1 : $inv['ayahs'];
            $startRef = $refOf($startOrd);
            $endRef = $refOf($endN);
            $divisionRows[] = [
                'division_type' => $type,
                'division_number' => $n,
                'parent_type' => null,
                'parent_number' => null,
                'start_surah' => $startRef[0],
                'start_ayah' => $startRef[1],
                'end_surah' => $endRef[0],
                'end_ayah' => $endRef[1],
                'start_page' => $pageOf[$startOrd],
                'end_page' => $pageOf[$endN],
            ];
            $previous = $endN;
        }
        if ($starts[1] !== 1) {
            quranFail("{$type} must start at ordinal 1 (got {$starts[1]})");
        }
        $lastEnd = $previous;
        if ($lastEnd !== $inv['ayahs']) {
            quranFail("{$type} final division ends at {$lastEnd}, expected {$inv['ayahs']}");
        }
    }

    // Parents: containment first, arithmetic must agree (§5.8).
    $parentAssignments = ['hizb' => [], 'rub' => []];
    $byType = [];
    foreach ($divisionRows as $row) {
        $byType[$row['division_type']][$row['division_number']] = $row;
    }
    $findParent = static function (array $children, array $parents) use ($ordinalOf): array {
        $assigned = [];
        foreach ($children as $n => $child) {
            $parentNumber = null;
            $childStart = $ordinalOf((int) $child['start_surah'], (int) $child['start_ayah']);
            $childEnd = $ordinalOf((int) $child['end_surah'], (int) $child['end_ayah']);
            foreach ($parents as $pn => $parent) {
                $parentStart = $ordinalOf((int) $parent['start_surah'], (int) $parent['start_ayah']);
                $parentEnd = $ordinalOf((int) $parent['end_surah'], (int) $parent['end_ayah']);
                if ($parentStart <= $childStart && $childEnd <= $parentEnd) {
                    $parentNumber = $pn;
                    break;
                }
            }
            if ($parentNumber === null) {
                quranFail("no parent found for division {$n}");
            }
            $assigned[$n] = $parentNumber;
        }
        return $assigned;
    };
    $parentAssignments['hizb'] = $findParent($byType['hizb'], $byType['juz']);
    $parentAssignments['rub'] = $findParent($byType['rub'], $byType['hizb']);
    foreach ($divisionRows as $i => $row) {
        $type = $row['division_type'];
        if ($type === 'juz') {
            continue;
        }
        $parentType = $type === 'hizb' ? 'juz' : 'hizb';
        $parentNumber = $parentAssignments[$type][$row['division_number']];
        $arithmetic = $type === 'hizb'
            ? (int) ceil($row['division_number'] / 2)
            : (int) ceil($row['division_number'] / 4);
        if ($parentNumber !== $arithmetic) {
            quranFail("{$type} {$row['division_number']} parent is {$parentNumber} but numbering arithmetic implies {$arithmetic}");
        }
        $divisionRows[$i]['parent_type'] = $parentType;
        $divisionRows[$i]['parent_number'] = $parentNumber;
    }

    $divisionTypeRows = [];
    foreach (['juz', 'hizb', 'rub'] as $type) {
        $parentType = $type === 'juz' ? null : ($type === 'hizb' ? 'juz' : 'hizb');
        $perParent = null;
        if ($parentType !== null) {
            $tally = [];
            foreach ($parentAssignments[$type] as $parentNumber) {
                $tally[$parentNumber] = ($tally[$parentNumber] ?? 0) + 1;
            }
            $unique = array_values(array_unique($tally));
            if (count($unique) !== 1) {
                quranFail("non-uniform children per {$parentType}: " . implode(',', array_unique($unique)));
            }
            $perParent = $unique[0];
        }
        $divisionTypeRows[] = [
            'type_key' => $type,
            'name_arabic' => QURAN_DIVISION_LABELS[$type]['ar'],
            'name_english' => QURAN_DIVISION_LABELS[$type]['en'],
            'parent_type_key' => $parentType,
            'per_parent' => $perParent,
            'count_expected' => count($byType[$type]),
        ];
    }

    // --- meta (flat, string-valued, zero timestamps for determinism) --------
    $meta = [
        'dataset_name' => 'Madinah Mushaf canonical structure (Rafeequl Hifz)',
        'dataset_version' => 'tanzil-quran-metadata-' . $tanzil['version'],
        'dataset_source_name' => QURAN_SOURCES['tanzil_metadata']['label'],
        'dataset_source_url' => QURAN_SOURCES['tanzil_metadata']['url'],
        'dataset_source_license' => QURAN_SOURCES['tanzil_metadata']['license'],
        'dataset_source_license_url' => QURAN_SOURCES['tanzil_metadata']['license_url'],
        'dataset_source_sha256' => $sourceChecksums['quran-data.xml'] ?? '',
        'dataset_crosscheck_name' => QURAN_SOURCES['alquran_quran']['label'] . ' + ' . QURAN_SOURCES['alquran_meta']['label'],
        'dataset_crosscheck_urls' => QURAN_SOURCES['alquran_quran']['url'] . '; ' . QURAN_SOURCES['alquran_meta']['url'],
        'dataset_crosscheck_sha256' => quranEncodeJson([
            'alquran-quran-uthmani.json' => $sourceChecksums['alquran-quran-uthmani.json'] ?? '',
            'alquran-meta.json' => $sourceChecksums['alquran-meta.json'] ?? '',
        ]),
        'count_surahs' => (string) count($surahRows),
        'count_ayahs' => (string) count($ayahRows),
        'count_pages' => (string) count($pageRows),
        'count_segments' => (string) count($segments),
        'count_juz' => (string) count($byType['juz']),
        'count_hizb' => (string) count($byType['hizb']),
        'count_rub' => (string) count($byType['rub']),
        'count_division_types' => (string) count($divisionTypeRows),
        'normalizer' => 'tools/quran-data/normalize.php v' . QURAN_TOOL_VERSION,
        'structure_notes' => 'page starts and ayah-page segments derived from Tanzil page starts resolved against alquran per-ayah start pages; hizb starts = every 4th rub start (Tanzil publishes rub starts only); division ends are exclusive (end(n)=start(n+1)-1)',
    ];

    return [
        'surahs' => $surahRows,
        'ayahs' => $ayahRows,
        'pages' => $pageRows,
        'page_ayahs' => $segments,
        'divisions' => $divisionRows,
        'division_types' => $divisionTypeRows,
        'meta' => $meta,
    ];
}

function quranRefOrdinal(array $start0, array $suras, int $s, int $a, string $label): int
{
    if (!isset($suras[$s])) {
        quranFail("{$label}: unknown sura {$s}");
    }
    if ($a < 1 || $a > $suras[$s]['ayas']) {
        quranFail("{$label}: ayah {$a} out of range for sura {$s}");
    }
    return $start0[$s] + $a;
}

/** @return array{int, int} [surah, ayah] */
function quranOrdinalRef(array $start0, array $suras, int $ord): array
{
    $low = 1;
    $high = count($start0);
    while ($low <= $high) {
        $mid = intdiv($low + $high, 2);
        $first = $start0[$mid] + 1;
        $last = $start0[$mid] + $suras[$mid]['ayas'];
        if ($ord < $first) {
            $high = $mid - 1;
        } elseif ($ord > $last) {
            $low = $mid + 1;
        } else {
            return [$mid, $ord - $start0[$mid]];
        }
    }
    quranFail("ordinal {$ord} does not resolve to any surah/ayah");
}

// ---------------------------------------------------------------------------
// Invariant checks (§5 + prompt 24A §6) - pure over the dataset shape
// ---------------------------------------------------------------------------

/** @return array{name: string, passed: bool, detail: string} */
function quranCheck(string $name, bool $passed, string $detail): array
{
    return ['name' => $name, 'passed' => $passed, 'detail' => $detail];
}

/** @return array<string, mixed> */
function quranNewIssueSink(): array
{
    return ['count' => 0, 'samples' => []];
}

/** @param array<string, mixed> $sink */
function quranIssue(array &$sink, string $message): void
{
    $sink['count'] = (int) $sink['count'] + 1;
    if (count($sink['samples']) < 5) {
        $sink['samples'][] = $message;
    }
}

/** @param array<string, mixed> $sink */
function quranIssueDetail(array $sink): string
{
    if ((int) $sink['count'] === 0) {
        return 'ok';
    }
    $suffix = (int) $sink['count'] > count($sink['samples'])
        ? ' (+' . ((int) $sink['count'] - count($sink['samples'])) . ' more)'
        : '';
    return (int) $sink['count'] . ' failure(s): ' . implode(' | ', $sink['samples']) . $suffix;
}

/** @return list<array{name: string, passed: bool, detail: string}> */
function quranStructuralChecks(array $d): array
{
    $inv = QURAN_INVARIANTS;
    $checks = [];

    $surahs = $d['surahs'];
    $ayahs = $d['ayahs'];
    $pages = $d['pages'];
    $segments = $d['page_ayahs'];
    $divisions = $d['divisions'];
    $types = $d['division_types'];
    $meta = $d['meta'];

    // ---- build indexes ----------------------------------------------------
    $ayahByOrdinal = [];
    $ordOf = [];
    $orderIssue = quranNewIssueSink();
    $position = 0;
    foreach ($ayahs as $row) {
        $position++;
        $ord = (int) $row['ayah_index'];
        if ($ord !== $position) {
            quranIssue($orderIssue, "position {$position} has ayah_index {$ord}");
            break;
        }
        $ayahByOrdinal[$ord] = ['s' => (int) $row['surah_number'], 'a' => (int) $row['ayah_number']];
        $ordOf[(int) $row['surah_number'] . ':' . (int) $row['ayah_number']] = $ord;
    }
    if (count($ordOf) !== count($ayahByOrdinal)) {
        quranIssue($orderIssue, 'duplicate (surah, ayah) location keys');
    }
    $checks[] = quranCheck(
        '§5.2 ayah_index dense 1..N in surah order',
        (int) $orderIssue['count'] === 0 && count($ayahByOrdinal) === $inv['ayahs'],
        (int) $orderIssue['count'] === 0
            ? count($ayahByOrdinal) . ' ayah rows, dense 1..' . count($ayahByOrdinal)
            : quranIssueDetail($orderIssue)
    );

    $surahRows = [];
    $surahNumbering = quranNewIssueSink();
    foreach ($surahs as $row) {
        $surahRows[(int) $row['surah_number']] = $row;
    }
    if (array_keys($surahRows) !== range(1, $inv['surahs'])) {
        quranIssue($surahNumbering, 'surah keys are not exactly 1..' . $inv['surahs']);
    }
    $perSurah = [];
    foreach ($ayahByOrdinal as $ord => $ref) {
        $s = $ref['s'];
        if (!isset($perSurah[$s])) {
            $perSurah[$s] = ['count' => 0, 'first' => $ord, 'last' => $ord];
        }
        $perSurah[$s]['count']++;
        $perSurah[$s]['last'] = $ord;
    }
    foreach ($surahRows as $s => $row) {
        $info = $perSurah[$s] ?? null;
        if ($info === null) {
            quranIssue($surahNumbering, "surah {$s} has no ayahs");
            continue;
        }
        if ($info['count'] !== (int) $row['ayah_count']) {
            quranIssue($surahNumbering, "surah {$s}: ayah_count={$row['ayah_count']} but {$info['count']} ayah rows");
        }
        if ($info['last'] - $info['first'] + 1 !== $info['count']) {
            quranIssue($surahNumbering, "surah {$s} ordinal range {$info['first']}..{$info['last']} is not contiguous");
        }
        for ($a = 1; $a <= (int) $row['ayah_count']; $a++) {
            $ord = $ordOf[$s . ':' . $a] ?? null;
            if ($ord === null || $ord < $info['first'] || $ord > $info['last']) {
                quranIssue($surahNumbering, "surah {$s} missing ayah {$a} inside its ordinal range");
                break;
            }
        }
    }
    $checks[] = quranCheck(
        '§5.1 surah numbers contiguous 1..' . $inv['surahs'] . ' + per-surah ayah counts',
        (int) $surahNumbering['count'] === 0,
        quranIssueDetail($surahNumbering)
    );

    $totalSink = quranNewIssueSink();
    $sumAyahs = 0;
    foreach ($surahRows as $row) {
        $sumAyahs += (int) $row['ayah_count'];
    }
    $metaTotal = (int) ($meta['count_ayahs'] ?? 0);
    if ($sumAyahs !== $inv['ayahs']) {
        quranIssue($totalSink, "SUM(ayah_count)={$sumAyahs}, invariant={$inv['ayahs']}");
    }
    if ($metaTotal !== $sumAyahs) {
        quranIssue($totalSink, "meta count_ayahs={$metaTotal} != SUM(ayah_count)={$sumAyahs}");
    }
    if (count($ayahByOrdinal) !== $sumAyahs) {
        quranIssue($totalSink, 'ayah rows=' . count($ayahByOrdinal) . " != SUM(ayah_count)={$sumAyahs}");
    }
    $checks[] = quranCheck(
        '§5.1/§5.12 total ayahs = source-stated total (invariant ' . $inv['ayahs'] . ')',
        (int) $totalSink['count'] === 0,
        quranIssueDetail($totalSink)
    );

    // ---- pages ------------------------------------------------------------
    $pageRows = [];
    $pageSink = quranNewIssueSink();
    foreach ($pages as $row) {
        $pageRows[(int) $row['page_number']] = $row;
    }
    if (array_keys($pageRows) !== range(1, $inv['pages'])) {
        quranIssue($pageSink, 'page numbers are not exactly 1..' . $inv['pages']);
    }
    foreach ($pageRows as $p => $row) {
        foreach ([['start_surah', 'start_ayah'], ['end_surah', 'end_ayah']] as [$sk, $ak]) {
            $s = (int) $row[$sk];
            $a = (int) $row[$ak];
            if (!isset($surahRows[$s]) || $a < 1 || $a > (int) $surahRows[$s]['ayah_count']) {
                quranIssue($pageSink, "page {$p} {$sk}/{$ak} resolves to unknown {$s}:{$a}");
            }
        }
    }
    $checks[] = quranCheck(
        '§5.3 pages contiguous 1..' . $inv['pages'] . ' + start/end ayahs resolve',
        (int) $pageSink['count'] === 0,
        quranIssueDetail($pageSink)
    );

    $segmentsByPage = [];
    foreach ($segments as $segment) {
        $segmentsByPage[(int) $segment['page_number']][] = $segment;
    }

    $segSink = quranNewIssueSink();
    $expectedIndex = 0;
    foreach ($segments as $segment) {
        $expectedIndex++;
        if ((int) $segment['segment_index'] !== $expectedIndex) {
            quranIssue($segSink, "segment_index at position {$expectedIndex} is {$segment['segment_index']}");
            break;
        }
    }
    if (count($segments) === 0) {
        quranIssue($segSink, 'no segments');
    }
    foreach ($segmentsByPage as $p => $pageSegments) {
        $order = 0;
        foreach ($pageSegments as $segment) {
            $order++;
            if ((int) $segment['segment_order'] !== $order) {
                quranIssue($segSink, "page {$p} segment_order {$segment['segment_order']} at position {$order}");
                break;
            }
            $s = (int) $segment['surah_number'];
            $a = (int) $segment['ayah_number'];
            if (!isset($surahRows[$s]) || $a < 1 || $a > (int) $surahRows[$s]['ayah_count']) {
                quranIssue($segSink, "page {$p} segment points at unknown {$s}:{$a}");
            }
        }
        $first = $pageSegments[0];
        $last = $pageSegments[count($pageSegments) - 1];
        $pageRow = $pageRows[$p] ?? null;
        if ($pageRow === null) {
            quranIssue($segSink, "segments reference missing page {$p}");
            continue;
        }
        if ((int) $pageRow['start_surah'] !== (int) $first['surah_number']
            || (int) $pageRow['start_ayah'] !== (int) $first['ayah_number']
            || (int) $pageRow['end_surah'] !== (int) $last['surah_number']
            || (int) $pageRow['end_ayah'] !== (int) $last['ayah_number']
        ) {
            quranIssue($segSink, "page {$p} quran_pages bounds disagree with segment min/max");
        }
    }
    $checks[] = quranCheck(
        '§5.5 segments: dense segment_index, order 1..k per page',
        (int) $segSink['count'] === 0,
        quranIssueDetail($segSink)
    );
    $checks[] = quranCheck(
        '§5.6 quran_pages start/end exactly match segment min/max',
        (int) $segSink['count'] === 0,
        (int) $segSink['count'] === 0 ? count($segments) . ' segments agree with ' . count($pageRows) . ' pages' : quranIssueDetail($segSink)
    );

    $adjSink = quranNewIssueSink();
    $prevEndOrd = null;
    for ($p = 1; $p <= $inv['pages']; $p++) {
        $pageSegments = $segmentsByPage[$p] ?? [];
        if ($pageSegments === []) {
            quranIssue($adjSink, "page {$p} has no segments");
            continue;
        }
        $first = $pageSegments[0];
        $last = $pageSegments[count($pageSegments) - 1];
        $firstOrd = $ordOf[$first['surah_number'] . ':' . $first['ayah_number']] ?? null;
        $lastOrd = $ordOf[$last['surah_number'] . ':' . $last['ayah_number']] ?? null;
        if ($firstOrd === null || $lastOrd === null) {
            quranIssue($adjSink, "page {$p} endpoints do not resolve to ordinals");
            continue;
        }
        if ($prevEndOrd !== null && !in_array($firstOrd, [$prevEndOrd, $prevEndOrd + 1], true)) {
            quranIssue($adjSink, "page {$p} starts at ordinal {$firstOrd}, previous page ended at {$prevEndOrd}");
        }
        if ($firstOrd > $lastOrd) {
            quranIssue($adjSink, "page {$p} first ordinal {$firstOrd} > last {$lastOrd}");
        }
        $prevEndOrd = $lastOrd;
    }
    $checks[] = quranCheck(
        '§5.4 page adjacency: next page start follows previous end (+0/+1)',
        (int) $adjSink['count'] === 0,
        quranIssueDetail($adjSink)
    );

    $flagSink = quranNewIssueSink();
    $prevEndOrd = null;
    $prevLastSegment = null;
    foreach ($segmentsByPage as $p => $pageSegments) {
        $first = $pageSegments[0];
        $last = $pageSegments[count($pageSegments) - 1];
        $firstOrd = $ordOf[$first['surah_number'] . ':' . $first['ayah_number']];
        if ($prevEndOrd !== null) {
            $expectedFlag = $firstOrd === $prevEndOrd ? 1 : 0;
            if ((int) $first['continues_previous'] !== $expectedFlag) {
                quranIssue($flagSink, "page {$p} first segment continues_previous={$first['continues_previous']}, expected {$expectedFlag}");
            }
            if ($expectedFlag === 1 && $prevLastSegment !== null && (int) $prevLastSegment['continues_next'] !== 1) {
                quranIssue($flagSink, 'page ' . ($p - 1) . ' last segment missing continues_next=1');
            }
        } elseif ((int) $first['continues_previous'] !== 0) {
            quranIssue($flagSink, 'page 1 must not set continues_previous');
        }
        $lastOrd = $ordOf[$last['surah_number'] . ':' . $last['ayah_number']];
        $isStraddle = $lastOrd < $inv['ayahs'] && isset($segmentsByPage[$p + 1]);
        if ($isStraddle) {
            $nextFirst = $segmentsByPage[$p + 1][0];
            $nextOrd = $ordOf[$nextFirst['surah_number'] . ':' . $nextFirst['ayah_number']];
            $expectedNext = $nextOrd === $lastOrd ? 1 : 0;
            if ((int) $last['continues_next'] !== $expectedNext) {
                quranIssue($flagSink, "page {$p} last segment continues_next={$last['continues_next']}, expected {$expectedNext}");
            }
        } elseif ((int) $last['continues_next'] !== 0 && $p < $inv['pages']) {
            quranIssue($flagSink, "page {$p} last segment sets continues_next without a continuation");
        }
        $prevEndOrd = $lastOrd;
        $prevLastSegment = $last;
    }
    $checks[] = quranCheck(
        '§5.4/§5.5 continuation flags agree with segment boundaries',
        (int) $flagSink['count'] === 0,
        quranIssueDetail($flagSink)
    );

    // ---- divisions ---------------------------------------------------------
    $divRows = [];
    $typeRows = [];
    foreach ($types as $row) {
        $typeRows[(string) $row['type_key']] = $row;
    }
    foreach ($divisions as $row) {
        $divRows[(string) $row['division_type']][(int) $row['division_number']] = $row;
    }
    $divOrd = [];
    foreach ($divRows as $type => $rows) {
        foreach ($rows as $n => $row) {
            $divOrd[$type][$n] = [
                'start' => $ordOf[$row['start_surah'] . ':' . $row['start_ayah']] ?? null,
                'end' => $ordOf[$row['end_surah'] . ':' . $row['end_ayah']] ?? null,
            ];
        }
    }

    $divSink = quranNewIssueSink();
    foreach ($divRows as $type => $rows) {
        if (!isset($typeRows[$type])) {
            quranIssue($divSink, "type {$type} missing from quran_division_types");
            continue;
        }
        ksort($rows);
        if (array_keys($rows) !== range(1, count($rows))) {
            quranIssue($divSink, "type {$type} numbering is not 1.." . count($rows));
            continue;
        }
        $previousEnd = null;
        foreach ($rows as $n => $row) {
            $startOrd = $divOrd[$type][$n]['start'];
            $endOrd = $divOrd[$type][$n]['end'];
            if ($startOrd === null || $endOrd === null) {
                quranIssue($divSink, "{$type} {$n} boundary ayah does not resolve");
                continue;
            }
            if ($startOrd > $endOrd) {
                quranIssue($divSink, "{$type} {$n} start {$startOrd} > end {$endOrd}");
            }
            if ($previousEnd === null) {
                if ($startOrd !== 1) {
                    quranIssue($divSink, "{$type} 1 must start at ordinal 1 (got {$startOrd})");
                }
            } elseif ($startOrd !== $previousEnd + 1) {
                quranIssue($divSink, "{$type} {$n} starts at {$startOrd}, previous end+1=" . ($previousEnd + 1) . ' (exclusive partition violated)');
            }
            $previousEnd = $endOrd;
        }
        $lastRow = $rows[count($rows)];
        $lastEnd = $ordOf[$lastRow['end_surah'] . ':' . $lastRow['end_ayah']] ?? 0;
        if ($lastEnd !== $inv['ayahs']) {
            quranIssue($divSink, "{$type} ends at {$lastEnd}, expected {$inv['ayahs']}");
        }
        $expectedCount = $inv[$type] ?? null;
        if ($expectedCount !== null && count($rows) !== $expectedCount) {
            quranIssue($divSink, "{$type} has " . count($rows) . " rows, invariant expects {$expectedCount}");
        }
        if (isset($typeRows[$type]) && (int) $typeRows[$type]['count_expected'] !== count($rows)) {
            quranIssue($divSink, "{$type} count_expected={$typeRows[$type]['count_expected']} != rows=" . count($rows));
        }
    }
    $checks[] = quranCheck(
        '§5.7/§5.12 divisions: exclusive contiguous partition per type + counts = source',
        (int) $divSink['count'] === 0,
        quranIssueDetail($divSink)
    );

    $nestSink = quranNewIssueSink();
    foreach (['hizb' => 'juz', 'rub' => 'hizb'] as $type => $parentType) {
        $tally = [];
        foreach ($divRows[$type] ?? [] as $n => $row) {
            $parentNumber = $row['parent_number'] === null ? null : (int) $row['parent_number'];
            $expected = $type === 'hizb' ? (int) ceil($n / 2) : (int) ceil($n / 4);
            if ($row['parent_type'] !== $parentType) {
                quranIssue($nestSink, "{$type} {$n} parent_type is " . var_export($row['parent_type'], true) . ", expected '{$parentType}'");
            }
            if ($parentNumber !== $expected) {
                quranIssue($nestSink, "{$type} {$n} parent_number=" . var_export($parentNumber, true) . ", arithmetic expects {$expected}");
            }
            $parent = $divRows[$parentType][$parentNumber ?? 0] ?? null;
            if ($parent === null) {
                quranIssue($nestSink, "{$type} {$n} references missing parent {$parentType} " . var_export($parentNumber, true));
                continue;
            }
            $childStart = $divOrd[$type][$n]['start'];
            $childEnd = $divOrd[$type][$n]['end'];
            $parentStart = $divOrd[$parentType][$parentNumber]['start'] ?? null;
            $parentEnd = $divOrd[$parentType][$parentNumber]['end'] ?? null;
            if ($childStart === null || $childEnd === null || $parentStart === null || $parentEnd === null) {
                quranIssue($nestSink, "{$type} {$n} has unresolvable ordinals for nesting check");
                continue;
            }
            if ($childStart < $parentStart || $childEnd > $parentEnd) {
                quranIssue($nestSink, "{$type} {$n} escapes {$parentType} {$parentNumber} in ordinals");
            }
            if ((int) $row['start_page'] < (int) $parent['start_page'] || (int) $row['end_page'] > (int) $parent['end_page']) {
                quranIssue($nestSink, "{$type} {$n} escapes {$parentType} {$parentNumber} in pages");
            }
            $tally[$parentNumber ?? 0] = ($tally[$parentNumber ?? 0] ?? 0) + 1;
        }
        $perParent = $typeRows[$type]['per_parent'] ?? null;
        foreach ($tally as $parentNumber => $count) {
            if ($perParent === null || $count !== (int) $perParent) {
                quranIssue($nestSink, "{$parentType} {$parentNumber} has {$count} {$type} children, per_parent=" . var_export($perParent, true));
            }
        }
        $parentCountExpected = $typeRows[$parentType]['count_expected'] ?? null;
        if ($perParent !== null && $parentCountExpected !== null && (int) $parentCountExpected * (int) $perParent !== count($divRows[$type] ?? [])) {
            quranIssue($nestSink, "{$type}: parents x per_parent != child rows");
        }
    }
    $checks[] = quranCheck(
        '§5.8 nesting rub⊂hizb⊂juz + parent = numbering arithmetic + per_parent',
        (int) $nestSink['count'] === 0,
        quranIssueDetail($nestSink)
    );

    // ---- §5.9 denormalised page spans vs segments --------------------------
    $minPageOf = [];
    $maxPageOf = [];
    foreach ($segments as $segment) {
        $ord = $ordOf[$segment['surah_number'] . ':' . $segment['ayah_number']];
        $p = (int) $segment['page_number'];
        $minPageOf[$ord] = min($minPageOf[$ord] ?? $p, $p);
        $maxPageOf[$ord] = max($maxPageOf[$ord] ?? $p, $p);
    }
    $pageSpanSink = quranNewIssueSink();
    foreach ($divRows as $type => $rows) {
        foreach ($rows as $n => $row) {
            $startOrd = $divOrd[$type][$n]['start'];
            $endOrd = $divOrd[$type][$n]['end'];
            if ($startOrd === null || $endOrd === null) {
                continue; // already reported by the partition check
            }
            if (($minPageOf[$startOrd] ?? null) !== (int) $row['start_page']) {
                quranIssue($pageSpanSink, "{$type} {$n} start_page={$row['start_page']} but start ayah first page is " . var_export($minPageOf[$startOrd] ?? null, true));
            }
            if (($minPageOf[$endOrd] ?? null) !== (int) $row['end_page']) {
                quranIssue($pageSpanSink, "{$type} {$n} end_page={$row['end_page']} but end ayah first page is " . var_export($minPageOf[$endOrd] ?? null, true));
            }
            if ((int) $row['start_page'] > (int) $row['end_page']) {
                quranIssue($pageSpanSink, "{$type} {$n} start_page > end_page");
            }
            for ($ord = $startOrd; $ord <= $endOrd; $ord++) {
                $min = $minPageOf[$ord] ?? null;
                $max = $maxPageOf[$ord] ?? null;
                if ($min === null || $max === null) {
                    quranIssue($pageSpanSink, "{$type} {$n} range contains ordinal {$ord} with no segments");
                    break;
                }
                if ($min < (int) $row['start_page'] || $max > ($maxPageOf[$endOrd] ?? 0)) {
                    quranIssue($pageSpanSink, "{$type} {$n} ayah ordinal {$ord} outside extended page span");
                    break;
                }
            }
        }
    }
    $checks[] = quranCheck(
        '§5.9 division page spans = first pages of start/end ayahs; ranges covered by segments',
        (int) $pageSpanSink['count'] === 0,
        quranIssueDetail($pageSpanSink)
    );

    // ---- §3.1 surah spans vs segments --------------------------------------
    $surahSpanSink = quranNewIssueSink();
    $surahFirstOrd = [];
    $surahLastOrd = [];
    $currentSurah = null;
    foreach ($ayahByOrdinal as $ord => $ref) {
        if ($ref['s'] !== $currentSurah) {
            $currentSurah = $ref['s'];
            $surahFirstOrd[$currentSurah] = $ord;
        }
        $surahLastOrd[$currentSurah] = $ord;
    }
    foreach ($surahRows as $s => $row) {
        $firstOrd = $surahFirstOrd[$s] ?? null;
        $lastOrd = $surahLastOrd[$s] ?? null;
        if ($firstOrd === null || $lastOrd === null) {
            quranIssue($surahSpanSink, "surah {$s} has no ayahs");
            continue;
        }
        if ($lastOrd - $firstOrd + 1 !== (int) $row['ayah_count']) {
            quranIssue($surahSpanSink, "surah {$s} ordinal span covers " . ($lastOrd - $firstOrd + 1) . " ayahs, ayah_count={$row['ayah_count']}");
        }
        if (($minPageOf[$firstOrd] ?? null) !== (int) $row['start_page']) {
            quranIssue($surahSpanSink, "surah {$s} start_page={$row['start_page']} but first ayah page is " . var_export($minPageOf[$firstOrd] ?? null, true));
        }
        if (($maxPageOf[$lastOrd] ?? null) !== (int) $row['end_page']) {
            quranIssue($surahSpanSink, "surah {$s} end_page={$row['end_page']} but last ayah max page is " . var_export($maxPageOf[$lastOrd] ?? null, true));
        }
    }
    $checks[] = quranCheck(
        '§6 surah begin/end boundaries: spans + start/end pages vs segments',
        (int) $surahSpanSink['count'] === 0,
        quranIssueDetail($surahSpanSink)
    );

    // ---- §5.10 completeness of required fields ----------------------------
    $nullSink = quranNewIssueSink();
    foreach ($surahs as $row) {
        foreach (['surah_number', 'name_arabic', 'name_transliteration', 'name_english', 'revelation_type', 'ayah_count', 'start_page', 'end_page'] as $field) {
            if (($row[$field] ?? null) === null || $row[$field] === '') {
                quranIssue($nullSink, "quran_surahs missing {$field}");
                break;
            }
        }
        if (!in_array($row['revelation_type'], ['meccan', 'medinan'], true)) {
            quranIssue($nullSink, "surah {$row['surah_number']} revelation_type=" . var_export($row['revelation_type'], true));
        }
    }
    foreach ($divisions as $row) {
        foreach (['division_type', 'division_number', 'start_surah', 'start_ayah', 'end_surah', 'end_ayah', 'start_page', 'end_page'] as $field) {
            if (($row[$field] ?? null) === null) {
                quranIssue($nullSink, "quran_divisions missing {$field}");
                break;
            }
        }
        if (in_array($row['division_type'], ['hizb', 'rub'], true) && ($row['parent_type'] === null || $row['parent_number'] === null)) {
            quranIssue($nullSink, "division {$row['division_type']} {$row['division_number']} missing parent");
        }
    }
    foreach ($segments as $row) {
        foreach (['page_number', 'surah_number', 'ayah_number', 'segment_order', 'segment_index', 'continues_previous', 'continues_next'] as $field) {
            if (($row[$field] ?? null) === null) {
                quranIssue($nullSink, "quran_page_ayahs missing {$field}");
                break;
            }
        }
    }
    foreach (['dataset_version', 'dataset_source_url', 'dataset_source_sha256', 'count_ayahs', 'count_pages'] as $key) {
        if (($meta[$key] ?? '') === '') {
            quranIssue($nullSink, "meta missing {$key}");
        }
    }
    $checks[] = quranCheck(
        '§5.10 required fields present, no NULL boundaries, enum values valid',
        (int) $nullSink['count'] === 0,
        quranIssueDetail($nullSink)
    );

    // ---- §6 page transitions + surah/division transitions within pages -----
    $transitionSink = quranNewIssueSink();
    $stats = ['surah_shared' => 0, 'surah_page_break' => 0, 'juz_shared' => 0, 'juz_adjacent' => 0, 'hizb_shared' => 0, 'hizb_adjacent' => 0, 'rub_shared' => 0, 'rub_adjacent' => 0];
    foreach ($surahRows as $s => $row) {
        if ($s === 1) {
            continue;
        }
        $prevLast = $surahLastOrd[$s - 1] ?? null;
        $first = $surahFirstOrd[$s] ?? null;
        if ($prevLast === null || $first === null) {
            quranIssue($transitionSink, "surah {$s} transition endpoints missing");
            continue;
        }
        if ($first === $prevLast + 1 && $maxPageOf[$prevLast] === $minPageOf[$first]) {
            $stats['surah_shared']++;
        } elseif ($first === $prevLast + 1 && $minPageOf[$first] === $maxPageOf[$prevLast] + 1) {
            $stats['surah_page_break']++;
        } else {
            quranIssue($transitionSink, "surah {$s} starts at {$first} after {$prevLast} across pages " . var_export($maxPageOf[$prevLast] ?? null, true) . '->' . var_export($minPageOf[$first] ?? null, true));
        }
    }
    foreach (['juz', 'hizb', 'rub'] as $type) {
        $rows = $divRows[$type] ?? [];
        ksort($rows);
        $previous = null;
        foreach ($rows as $n => $row) {
            if ($previous !== null) {
                $shared = $previous['end_page'] === $row['start_page'];
                $adjacent = (int) $row['start_page'] === (int) $previous['end_page'] + 1;
                if ($shared) {
                    $stats[$type . '_shared']++;
                } elseif ($adjacent) {
                    $stats[$type . '_adjacent']++;
                } else {
                    quranIssue($transitionSink, "{$type} {$n} transition jumps from page {$previous['end_page']} to {$row['start_page']}");
                }
            }
            $previous = $row;
        }
    }
    $checks[] = quranCheck(
        '§6 page/surah/division transitions: contiguous, shared or page-break (counts: '
            . 'surah shared=' . $stats['surah_shared'] . ' break=' . $stats['surah_page_break']
            . '; juz shared=' . $stats['juz_shared'] . ' adj=' . $stats['juz_adjacent']
            . '; hizb shared=' . $stats['hizb_shared'] . ' adj=' . $stats['hizb_adjacent']
            . '; rub shared=' . $stats['rub_shared'] . ' adj=' . $stats['rub_adjacent'] . ')',
        (int) $transitionSink['count'] === 0,
        quranIssueDetail($transitionSink)
    );

    // ---- §5.12 meta counts vs rows ----------------------------------------
    $countSink = quranNewIssueSink();
    $expectedCounts = [
        'count_surahs' => count($surahs),
        'count_ayahs' => count($ayahs),
        'count_pages' => count($pages),
        'count_segments' => count($segments),
        'count_juz' => count($divRows['juz'] ?? []),
        'count_hizb' => count($divRows['hizb'] ?? []),
        'count_rub' => count($divRows['rub'] ?? []),
        'count_division_types' => count($types),
    ];
    foreach ($expectedCounts as $key => $actual) {
        if ((string) $actual !== (string) ($meta[$key] ?? null)) {
            quranIssue($countSink, "{$key}: rows={$actual}, meta=" . ($meta[$key] ?? '(missing)'));
        }
    }
    foreach ($types as $row) {
        $type = (string) $row['type_key'];
        if ((int) $row['count_expected'] !== count($divRows[$type] ?? [])) {
            quranIssue($countSink, "type {$type} count_expected={$row['count_expected']} rows=" . count($divRows[$type] ?? []));
        }
    }
    $checks[] = quranCheck(
        '§5.12 recorded totals match actual rows (meta + division types)',
        (int) $countSink['count'] === 0,
        quranIssueDetail($countSink)
    );

    return $checks;
}

/**
 * Cross-source checks (data-architecture §8.2) - files only; audit relies
 * on the structural layer plus provenance instead.
 *
 * @return list<array{name: string, passed: bool, detail: string}>
 */
function quranCrossSourceChecks(array $d, array $aq, array $aqMeta): array
{
    $inv = QURAN_INVARIANTS;
    $checks = [];

    $ordOf = [];
    foreach ($d['ayahs'] as $row) {
        $ordOf[(int) $row['surah_number'] . ':' . (int) $row['ayah_number']] = (int) $row['ayah_index'];
    }
    $minPageOf = [];
    $maxPageOf = [];
    foreach ($d['page_ayahs'] as $segment) {
        $ord = $ordOf[$segment['surah_number'] . ':' . $segment['ayah_number']];
        $p = (int) $segment['page_number'];
        $minPageOf[$ord] = min($minPageOf[$ord] ?? $p, $p);
        $maxPageOf[$ord] = max($maxPageOf[$ord] ?? $p, $p);
    }

    // per-ayah page: alquran start page == segment MIN page (exact);
    // next ayah must begin on the same page or the following one (continuity)
    $sink = quranNewIssueSink();
    foreach ($aq['ayahs'] as $ord => $aqAyah) {
        if (($minPageOf[$ord] ?? null) !== $aqAyah['page']) {
            quranIssue($sink, "ordinal {$ord}: alquran start page {$aqAyah['page']} vs segments " . var_export($minPageOf[$ord] ?? null, true));
        }
        if ($ord < $inv['ayahs']) {
            $nextStart = $aq['ayahs'][$ord + 1]['page'] ?? null;
            $end = $maxPageOf[$ord] ?? null;
            if ($nextStart === null || $end === null || !in_array($nextStart, [$end, $end + 1], true)) {
                quranIssue($sink, "ordinal {$ord}: ends page " . var_export($end, true) . ' but next ayah starts page ' . var_export($nextStart, true));
            }
        } elseif (($maxPageOf[$ord] ?? null) !== $inv['pages']) {
            quranIssue($sink, "final ayah ends on page " . var_export($maxPageOf[$ord] ?? null, true) . ", expected {$inv['pages']}");
        }
    }
    $checks[] = quranCheck(
        'XS per-ayah page mapping agrees (alquran start pages vs segments, ' . count($aq['ayahs']) . ' ayahs)',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    // global numbering
    $sink = quranNewIssueSink();
    foreach ($d['ayahs'] as $row) {
        $ord = (int) $row['ayah_index'];
        $aqAyah = $aq['ayahs'][$ord] ?? null;
        if ($aqAyah === null
            || $aqAyah['surah'] !== (int) $row['surah_number']
            || $aqAyah['ayah'] !== (int) $row['ayah_number']
        ) {
            quranIssue($sink, "ordinal {$ord} numbering mismatch");
        }
    }
    $checks[] = quranCheck(
        'XS global + per-surah numbering aligned (alquran vs processed)',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    // surah metadata: counts + revelation type
    $sink = quranNewIssueSink();
    foreach ($d['surahs'] as $row) {
        $s = (int) $row['surah_number'];
        $metaSurah = $aqMeta['suras'][$s] ?? null;
        $aqSurah = $aq['suras'][$s] ?? null;
        if ($metaSurah === null || $aqSurah === null) {
            quranIssue($sink, "surah {$s} missing from alquran lists");
            continue;
        }
        if ($metaSurah['ayah_count'] !== (int) $row['ayah_count'] || $aqSurah['ayah_count'] !== (int) $row['ayah_count']) {
            quranIssue($sink, "surah {$s} ayah_count {$row['ayah_count']} vs meta {$metaSurah['ayah_count']} vs quran {$aqSurah['ayah_count']}");
        }
        if (strtolower($metaSurah['revelation_type']) !== $row['revelation_type'] || strtolower($aqSurah['revelation_type']) !== $row['revelation_type']) {
            quranIssue($sink, "surah {$s} revelation {$row['revelation_type']} vs meta {$metaSurah['revelation_type']} vs quran {$aqSurah['revelation_type']}");
        }
    }
    $checks[] = quranCheck(
        'XS surah list: ayah counts + revelation type (both sources, 114)',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    // division start references vs alquran meta
    foreach (['juz' => 'juz_starts', 'rub' => 'quarter_starts'] as $type => $bucket) {
        $sink = quranNewIssueSink();
        $ours = [];
        foreach ($d['divisions'] as $row) {
            if ($row['division_type'] === $type) {
                $ours[(int) $row['division_number']] = [(int) $row['start_surah'], (int) $row['start_ayah']];
            }
        }
        ksort($ours);
        if (array_keys($ours) !== array_keys($aqMeta[$bucket])) {
            quranIssue($sink, 'division numbering differs from alquran meta');
        }
        foreach ($aqMeta[$bucket] as $n => $ref) {
            if (($ours[$n] ?? null) !== $ref) {
                quranIssue($sink, "{$type} {$n} start " . json_encode($ours[$n] ?? null) . ' vs alquran ' . json_encode($ref));
            }
        }
        $checks[] = quranCheck(
            "XS {$type} start refs = alquran meta (" . count($aqMeta[$bucket]) . ')',
            (int) $sink['count'] === 0,
            quranIssueDetail($sink)
        );
    }

    // page start refs vs alquran meta pages
    $sink = quranNewIssueSink();
    $pageStarts = [];
    foreach ($d['pages'] as $row) {
        $pageStarts[(int) $row['page_number']] = [(int) $row['start_surah'], (int) $row['start_ayah']];
    }
    foreach ($aqMeta['page_starts'] as $p => $ref) {
        if (($pageStarts[$p] ?? null) !== $ref) {
            quranIssue($sink, "page {$p} starts " . json_encode($pageStarts[$p] ?? null) . ' vs alquran ' . json_encode($ref));
        }
    }
    $checks[] = quranCheck(
        'XS page start refs = alquran meta (' . count($aqMeta['page_starts']) . ')',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    // per-ayah division membership (alquran fields vs our ordinal ranges)
    foreach (['juz' => 'juz', 'rub' => 'rub'] as $type => $aqField) {
        $sink = quranNewIssueSink();
        $rangeOfOrdinal = [];
        foreach ($d['divisions'] as $row) {
            if ($row['division_type'] !== $type) {
                continue;
            }
            $start = $ordOf[$row['start_surah'] . ':' . $row['start_ayah']];
            $end = $ordOf[$row['end_surah'] . ':' . $row['end_ayah']];
            for ($ord = $start; $ord <= $end; $ord++) {
                $rangeOfOrdinal[$ord] = (int) $row['division_number'];
            }
        }
        foreach ($aq['ayahs'] as $ord => $aqAyah) {
            if (($rangeOfOrdinal[$ord] ?? null) !== $aqAyah[$aqField]) {
                quranIssue($sink, "ordinal {$ord}: ours " . var_export($rangeOfOrdinal[$ord] ?? null, true) . " vs alquran {$aqAyah[$aqField]}");
            }
        }
        $checks[] = quranCheck(
            "XS per-ayah {$type} membership (alquran field vs ordinal ranges, " . count($aq['ayahs']) . ')',
            (int) $sink['count'] === 0,
            quranIssueDetail($sink)
        );
    }

    // ayah totals (4-way)
    $sink = quranNewIssueSink();
    $sum = 0;
    foreach ($d['surahs'] as $row) {
        $sum += (int) $row['ayah_count'];
    }
    foreach ([
        'processed surah SUM' => $sum,
        'processed ayah rows' => count($d['ayahs']),
        'alquran ayah rows' => count($aq['ayahs']),
        'alquran meta ayahs.count' => $aqMeta['counts']['ayahs'],
        'invariant' => $inv['ayahs'],
    ] as $label => $value) {
        if ($value !== $inv['ayahs']) {
            quranIssue($sink, "{$label}={$value}, expected {$inv['ayahs']}");
        }
    }
    $checks[] = quranCheck(
        'XS ayah totals agree across both sources (' . $inv['ayahs'] . ')',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    return $checks;
}

/**
 * Deterministic human spot-check candidates (data-architecture §8.5).
 * Selection is derived from source checksums so a re-run reproduces the
 * same list; a maintainer records the result against the printed Mushaf.
 */
function quranSpotCheckLocations(array $d, string $seedSource): array
{
    $divByType = [];
    foreach ($d['divisions'] as $row) {
        $divByType[$row['division_type']][] = $row;
    }
    foreach ($divByType as $rows) {
        usort($rows, static fn (array $a, array $b): int => $a['division_number'] <=> $b['division_number']);
        $divByType[$rows[0]['division_type']] = $rows;
    }
    $surahs = $d['surahs'];
    $seed = substr(hash('sha256', $seedSource), 0, 16);
    $locations = [];
    $picks = [
        ['juz', 6],
        ['juz', 17],
        ['juz', 29],
        ['hizb', 34],
        ['rub', 101],
        ['rub', 200],
        ['surah', 18],
        ['surah', 67],
    ];
    foreach ($picks as $i => [$type, $index]) {
        $offset = (int) hexdec(substr($seed, ($i * 2) % 14, 2));
        if ($type === 'surah') {
            $s = (($index + $offset) % 114) + 1;
            $row = $surahs[$s - 1] ?? null;
            if ($row === null) {
                continue;
            }
            $locations[] = [
                'kind' => 'surah start',
                'identifier' => 'surah ' . $s,
                'start' => $row['surah_number'] . ':' . '1',
                'end' => $row['surah_number'] . ':' . $row['ayah_count'],
                'start_page' => $row['start_page'],
                'end_page' => $row['end_page'],
            ];
        } else {
            $rows = $divByType[$type] ?? [];
            $n = (($index + $offset) % count($rows)) + 1;
            $row = $rows[$n - 1];
            $locations[] = [
                'kind' => $type . ' ' . $row['division_number'],
                'identifier' => $type . ' ' . $row['division_number'],
                'start' => $row['start_surah'] . ':' . $row['start_ayah'],
                'end' => $row['end_surah'] . ':' . $row['end_ayah'],
                'start_page' => $row['start_page'],
                'end_page' => $row['end_page'],
            ];
        }
    }
    return [
        'seed' => $seed,
        'method' => 'deterministic offsets derived from source SHA-256 (stable across re-runs)',
        'locations' => $locations,
        'performed' => false,
        'performed_by' => null,
        'recorded_at' => null,
        'result' => 'PENDING: maintainer must confirm each location against the printed Madinah Mushaf (data-architecture §8.5); automation cannot replace this check',
    ];
}

function quranAllPassed(array $checks): bool
{
    foreach ($checks as $check) {
        if (!$check['passed']) {
            return false;
        }
    }
    return true;
}

function quranFormatChecks(array $checks): string
{
    $lines = [];
    foreach ($checks as $check) {
        $lines[] = sprintf('%s %s — %s', $check['passed'] ? 'PASS' : 'FAIL', $check['name'], $check['detail']);
    }
    return implode("\n", $lines);
}

function quranCountsSummary(array $d): array
{
    $perType = [];
    foreach ($d['divisions'] as $row) {
        $perType[$row['division_type']] = ($perType[$row['division_type']] ?? 0) + 1;
    }
    return [
        'surahs' => count($d['surahs']),
        'ayahs' => count($d['ayahs']),
        'pages' => count($d['pages']),
        'segments' => count($d['page_ayahs']),
        'divisions' => $perType,
        'division_types' => count($d['division_types']),
    ];
}

function quranFormatCounts(array $counts): string
{
    $parts = [];
    foreach (['surahs', 'ayahs', 'pages', 'segments', 'division_types'] as $key) {
        $parts[] = $key . '=' . ($counts[$key] ?? '?');
    }
    foreach (($counts['divisions'] ?? []) as $type => $n) {
        $parts[] = $type . '=' . $n;
    }
    return implode(' ', $parts);
}

// ---------------------------------------------------------------------------
// Processed file I/O
// ---------------------------------------------------------------------------

/**
 * @return array<string, string> file basename => sha256 (all 7 files)
 */
function quranWriteProcessed(array $d): array
{
    quranEnsureDirs();
    $sections = [
        'surahs' => $d['surahs'],
        'ayahs' => $d['ayahs'],
        'pages' => $d['pages'],
        'page_ayahs' => $d['page_ayahs'],
        'divisions' => $d['divisions'],
        'division_types' => $d['division_types'],
        'meta' => $d['meta'],
    ];
    $checksums = [];
    foreach ($sections as $section => $rows) {
        $path = quranProcessedFiles()[$section];
        quranWriteFile($path, quranEncodeJson($rows));
        $checksums[basename($path)] = quranSha256File($path);
    }
    return $checksums;
}

/**
 * Byte-level round-trip: re-derived content must equal what is on disk
 * (detects hand edits and non-determinism). Returns list of mismatches.
 *
 * @return list<string>
 */
function quranRoundTripDiff(array $rederived): array
{
    $mismatches = [];
    $sections = [
        'surahs' => $rederived['surahs'],
        'ayahs' => $rederived['ayahs'],
        'pages' => $rederived['pages'],
        'page_ayahs' => $rederived['page_ayahs'],
        'divisions' => $rederived['divisions'],
        'division_types' => $rederived['division_types'],
        'meta' => $rederived['meta'],
    ];
    foreach ($sections as $section => $rows) {
        $path = quranProcessedFiles()[$section];
        if (!is_file($path)) {
            $mismatches[] = basename($path) . ' missing';
            continue;
        }
        $expected = quranEncodeJson($rows);
        $actual = (string) file_get_contents($path);
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
            $mismatches[] = basename($path) . ' differs from re-derived content (hand-edited or stale; re-run normalize.php)';
        }
    }
    return $mismatches;
}

// ---------------------------------------------------------------------------
// Verification report I/O
// ---------------------------------------------------------------------------

/** @return array{path: string, report: array<string, mixed>}|null */
function quranLatestReport(): ?array
{
    $files = glob(quranVerificationDir() . '/report-*.json') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        return null;
    }
    $path = $files[count($files) - 1];
    return ['path' => $path, 'report' => quranReadJson($path)];
}

function quranWriteReport(array $report): string
{
    quranEnsureDirs();
    $timestamp = gmdate('Ymd\THis\Z');
    $path = quranVerificationDir() . '/report-' . $timestamp . '.json';
    $attempt = 0;
    while (is_file($path) && $attempt < 10) {
        $attempt++;
        usleep(1_100_000);
        $timestamp = gmdate('Ymd\THis\Z');
        $path = quranVerificationDir() . '/report-' . $timestamp . '.json';
    }
    quranWriteFile($path, quranEncodeJson($report));
    return $path;
}

/**
 * Latest report must be PASS and its processed checksums must match the
 * files currently on disk (import gate).
 *
 * @return array{path: string, report: array<string, mixed>}
 */
function quranRequirePassingReport(): array
{
    $latest = quranLatestReport();
    if ($latest === null) {
        quranFail('no verification report found (run verify.php first)');
    }
    $report = $latest['report'];
    if (($report['verdict'] ?? '') !== 'PASS') {
        quranFail('latest verification report ' . basename($latest['path']) . ' verdict is ' . var_export($report['verdict'] ?? null, true) . ', not PASS');
    }
    $recorded = [];
    foreach (($report['processed'] ?? []) as $entry) {
        if (is_array($entry) && isset($entry['file'], $entry['sha256'])) {
            $recorded[(string) $entry['file']] = (string) $entry['sha256'];
        }
    }
    foreach (quranProcessedFiles() as $path) {
        $name = basename($path);
        $actual = is_file($path) ? quranSha256File($path) : null;
        if ($actual === null || ($recorded[$name] ?? '') !== $actual) {
            quranFail("processed file {$name} does not match the verification report (re-run normalize + verify)");
        }
    }
    return $latest;
}

// ---------------------------------------------------------------------------
// Import column map (import.php and the canonical test fixture share it)
// ---------------------------------------------------------------------------

/** @var array<string, list<string>> canonical table => columns in insert order */
const QURAN_IMPORT_COLUMNS = [
    'quran_surahs' => ['surah_number', 'name_arabic', 'name_transliteration', 'name_english', 'revelation_type', 'ayah_count', 'start_page', 'end_page'],
    'quran_ayahs' => ['surah_number', 'ayah_number', 'ayah_index'],
    'quran_pages' => ['page_number', 'start_surah', 'start_ayah', 'end_surah', 'end_ayah'],
    'quran_page_ayahs' => ['page_number', 'surah_number', 'ayah_number', 'segment_order', 'segment_index', 'continues_previous', 'continues_next'],
    'quran_division_types' => ['type_key', 'name_arabic', 'name_english', 'parent_type_key', 'per_parent', 'count_expected'],
    'quran_divisions' => ['division_type', 'division_number', 'parent_type', 'parent_number', 'start_surah', 'start_ayah', 'end_surah', 'end_ayah', 'start_page', 'end_page'],
    'quran_dataset_meta' => ['meta_key', 'meta_value'],
];

/** dataset section => table (insert order respects self-FK on types). */
const QURAN_IMPORT_ORDER = [
    'quran_surahs' => 'surahs',
    'quran_ayahs' => 'ayahs',
    'quran_pages' => 'pages',
    'quran_page_ayahs' => 'page_ayahs',
    'quran_division_types' => 'division_types',
    'quran_divisions' => 'divisions',
    'quran_dataset_meta' => 'meta',
];

// ---------------------------------------------------------------------------
// Database dataset (audit + CanonicalDatasetTest run the same checks)
// ---------------------------------------------------------------------------

/** @return array<string, mixed> same shape as the processed dataset */
function quranLoadDatasetFromDatabase(): array
{
    $cast = static function (array $row, array $ints): array {
        foreach ($ints as $field) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    };

    $surahs = [];
    foreach (\App\Database::fetchAll('SELECT * FROM quran_surahs ORDER BY surah_number') as $row) {
        $surahs[] = $cast($row, ['surah_number', 'ayah_count', 'start_page', 'end_page']);
    }
    $ayahs = [];
    foreach (\App\Database::fetchAll('SELECT * FROM quran_ayahs ORDER BY ayah_index') as $row) {
        $ayahs[] = $cast($row, ['surah_number', 'ayah_number', 'ayah_index']);
    }
    $pages = [];
    foreach (\App\Database::fetchAll('SELECT * FROM quran_pages ORDER BY page_number') as $row) {
        $pages[] = $cast($row, ['page_number', 'start_surah', 'start_ayah', 'end_surah', 'end_ayah']);
    }
    $segments = [];
    foreach (\App\Database::fetchAll('SELECT * FROM quran_page_ayahs ORDER BY segment_index') as $row) {
        $segments[] = $cast($row, ['page_number', 'surah_number', 'ayah_number', 'segment_order', 'segment_index', 'continues_previous', 'continues_next']);
    }
    $types = [];
    foreach (\App\Database::fetchAll('SELECT * FROM quran_division_types ORDER BY type_key') as $row) {
        $types[] = [
            'type_key' => (string) $row['type_key'],
            'name_arabic' => (string) $row['name_arabic'],
            'name_english' => (string) $row['name_english'],
            'parent_type_key' => $row['parent_type_key'] === null ? null : (string) $row['parent_type_key'],
            'per_parent' => $row['per_parent'] === null ? null : (int) $row['per_parent'],
            'count_expected' => $row['count_expected'] === null ? null : (int) $row['count_expected'],
        ];
    }
    $divisions = [];
    foreach (\App\Database::fetchAll('SELECT * FROM quran_divisions ORDER BY division_type, division_number') as $row) {
        $divisions[] = $cast($row, ['division_number', 'parent_number', 'start_surah', 'start_ayah', 'end_surah', 'end_ayah', 'start_page', 'end_page']);
    }
    $meta = [];
    foreach (\App\Database::fetchAll('SELECT meta_key, meta_value FROM quran_dataset_meta ORDER BY meta_key') as $row) {
        $meta[(string) $row['meta_key']] = $row['meta_value'] === null ? '' : (string) $row['meta_value'];
    }

    return [
        'surahs' => $surahs,
        'ayahs' => $ayahs,
        'pages' => $pages,
        'page_ayahs' => $segments,
        'divisions' => $divisions,
        'division_types' => $types,
        'meta' => $meta,
    ];
}

/**
 * Provenance chain (§8.7): meta must link to a PASS report whose
 * processed checksums match both the DB meta and the files on disk.
 *
 * @return list<array{name: string, passed: bool, detail: string}>
 */
function quranProvenanceChecks(array $meta): array
{
    $checks = [];

    $sink = quranNewIssueSink();
    $required = [
        'dataset_version',
        'dataset_source_name',
        'dataset_source_url',
        'dataset_source_license',
        'dataset_source_sha256',
        'processed_checksums',
        'verification_report',
        'verification_verdict',
        'imported_by',
        'imported_at',
        'count_ayahs',
        'count_pages',
        'spot_check_status',
    ];
    foreach ($required as $key) {
        if (($meta[$key] ?? '') === '') {
            quranIssue($sink, "missing meta key {$key}");
        }
    }
    if (($meta['verification_verdict'] ?? '') !== 'PASS') {
        quranIssue($sink, 'verification_verdict is ' . var_export($meta['verification_verdict'] ?? null, true));
    }
    if (!str_starts_with($meta['dataset_version'] ?? '', 'tanzil-quran-metadata-')) {
        quranIssue($sink, 'unexpected dataset_version ' . var_export($meta['dataset_version'] ?? null, true));
    }
    $checks[] = quranCheck(
        '§8.7 provenance meta keys complete + PASS verdict + canonical dataset_version',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    $sink = quranNewIssueSink();
    $reportName = $meta['verification_report'] ?? '';
    $reportPath = $reportName !== '' ? quranVerificationDir() . '/' . $reportName : '';
    $report = null;
    if ($reportPath === '' || !is_file($reportPath)) {
        quranIssue($sink, "verification report file missing: {$reportName}");
    } else {
        $report = quranReadJson($reportPath);
        if (($report['verdict'] ?? '') !== 'PASS') {
            quranIssue($sink, 'linked report verdict is ' . var_export($report['verdict'] ?? null, true));
        }
    }
    $recorded = [];
    foreach (($report['processed'] ?? []) as $entry) {
        if (is_array($entry) && isset($entry['file'], $entry['sha256'])) {
            $recorded[(string) $entry['file']] = (string) $entry['sha256'];
        }
    }
    $fromDb = [];
    try {
        $decoded = json_decode((string) ($meta['processed_checksums'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $fromDb = $decoded;
        } else {
            quranIssue($sink, 'processed_checksums is not a JSON object');
        }
    } catch (JsonException) {
        quranIssue($sink, 'processed_checksums is not valid JSON');
    }
    foreach (quranProcessedFiles() as $path) {
        $name = basename($path);
        $onDisk = is_file($path) ? quranSha256File($path) : null;
        if ($onDisk === null) {
            quranIssue($sink, "{$name} missing under processed/");
            continue;
        }
        if (($fromDb[$name] ?? '') !== $onDisk) {
            quranIssue($sink, "{$name}: meta checksum differs from disk");
        }
        if ($recorded !== [] && ($recorded[$name] ?? '') !== $onDisk) {
            quranIssue($sink, "{$name}: verification report checksum differs from disk");
        }
    }
    $checks[] = quranCheck(
        '§8.7 meta/report/processed checksums agree (DB vs report vs files)',
        (int) $sink['count'] === 0,
        quranIssueDetail($sink)
    );

    return $checks;
}

/**
 * Canonical dataset marker read by tests (skip unless present).
 */
function quranCanonicalDatasetVersion(): ?string
{
    $value = \App\Database::scalar('SELECT meta_value FROM quran_dataset_meta WHERE meta_key = ?', ['dataset_version']);
    return $value === null || $value === '' ? null : (string) $value;
}

function quranIsCanonicalDataset(?string $version): bool
{
    return $version !== null && str_starts_with($version, 'tanzil-quran-metadata-');
}

// ---------------------------------------------------------------------------
// CLI runner
// ---------------------------------------------------------------------------

/** @return list<string> */
function quranCliArgs(): array
{
    $args = $_SERVER['argv'] ?? [];
    array_shift($args);
    return $args;
}

function quranRunCli(callable $fn): never
{
    try {
        $fn();
    } catch (QuranDataError $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        error_log('quran-data: ' . $e->getMessage());
        exit(1);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e::class . ': ' . $e->getMessage() . "\n");
        error_log('quran-data: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        exit(1);
    }
    exit(0);
}
