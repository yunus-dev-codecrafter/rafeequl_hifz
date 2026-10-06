<?php

declare(strict_types=1);

/**
 * Pipeline stage 1 - ACQUIRE.
 *
 * Downloads the canonical structural sources into
 * data/quran/madinah/source/ untouched, writes .sha256 sidecars,
 * a machine-readable acquisition manifest and the human SOURCE.md
 * (prompt 24A §2/§4). Nothing is written unless every source downloads
 * and passes structural sanity checks (fail closed).
 *
 * Usage: php tools/quran-data/acquire.php [--force]
 *   --force  re-download even when a verified copy already exists
 */

require __DIR__ . '/lib.php';

function quranValidateSourceBody(string $key, string $body): string
{
    if ($key === 'tanzil_metadata') {
        if (preg_match('#<quran[^>]*\btype="metadata"#', $body) !== 1) {
            quranFail('downloaded Tanzil file is not a metadata quran document');
        }
        if (preg_match('#<quran[^>]*\bversion="([^"]+)"#', $body, $m) !== 1 || $m[1] === '') {
            quranFail('downloaded Tanzil file has no version attribute');
        }
        if (strpos($body, '<sura index="1"') === false
            || strpos($body, '<juz index="1"') === false
            || strpos($body, '<quarter index="1"') === false
            || strpos($body, '<page index="1"') === false
        ) {
            quranFail('downloaded Tanzil file is missing required sections (sura/juz/quarter/page)');
        }
        return $m[1];
    }

    $json = quranDecodeJson($body, basename((string) (QURAN_SOURCES[$key]['file'] ?? $key)));
    $data = $json['data'] ?? null;
    if (!is_array($data)) {
        quranFail("downloaded {$key} file has no data object (HTML error page?)");
    }
    if ($key === 'alquran_quran') {
        $surahs = $data['surahs'] ?? null;
        if (!is_array($surahs) || count($surahs) !== QURAN_INVARIANTS['surahs'] || !isset($surahs[0]['ayahs'])) {
            quranFail('downloaded alquran quran file must contain 114 surahs with ayahs');
        }
    } elseif ($key === 'alquran_meta') {
        if (($data['surahs']['count'] ?? 0) !== QURAN_INVARIANTS['surahs']
            || !is_array($data['juzs']['references'] ?? null)
            || !is_array($data['hizbQuarters']['references'] ?? null)
            || !is_array($data['pages']['references'] ?? null)
        ) {
            quranFail('downloaded alquran meta file is missing surah/juz/rub/page sections');
        }
    } else {
        quranFail("unknown source key {$key}");
    }
    return 'API v1';
}

function quranBuildSourceDoc(array $entries, string $acquiredAt): string
{
    $lines = [];
    $lines[] = '# SOURCE - canonical Quran structural dataset (Madinah Mushaf)';
    $lines[] = '';
    $lines[] = 'Acquired by `tools/quran-data/acquire.php`. Files in this directory are';
    $lines[] = '**preserved originals** - never edit them; the pipeline verifies SHA-256';
    $lines[] = 'sidecars before every stage (data-architecture §7).';
    $lines[] = '';
    $lines[] = 'Date obtained (UTC): **' . $acquiredAt . '**';
    $lines[] = '';
    $lines[] = '## Primary source';
    $lines[] = '';
    foreach ($entries as $entry) {
        if ($entry['role'] !== 'primary') {
            continue;
        }
        $lines[] = '| field | value |';
        $lines[] = '| --- | --- |';
        $lines[] = '| name | ' . $entry['label'] . ' |';
        $lines[] = '| official URL | ' . $entry['url'] . ' |';
        $lines[] = '| dataset / version | ' . $entry['dataset'] . ' / ' . $entry['version'] . ' |';
        $lines[] = '| license / terms | ' . $entry['license'] . ' (' . $entry['license_url'] . ') |';
        $lines[] = '| copyright | ' . $entry['copyright'] . ' |';
        $lines[] = '| file | `' . $entry['file'] . '` (' . $entry['bytes'] . ' bytes) |';
        $lines[] = '| SHA-256 | `' . $entry['sha256'] . '` |';
        $lines[] = '| contains | ' . $entry['contains'] . ' |';
        $lines[] = '';
        $lines[] = 'Attribution (required by the license): Quran structural metadata by';
        $lines[] = '**Tanzil.net**, (C) 2008-2009 Tanzil.info, licensed under';
        $lines[] = '[CC BY 3.0](https://creativecommons.org/licenses/by/3.0/) with the';
        $lines[] = '[Tanzil terms of use](https://tanzil.net/docs/text_license) - this';
        $lines[] = 'project links to <https://tanzil.net> wherever the dataset is presented.';
        $lines[] = '';
    }
    $lines[] = '## Independent cross-check sources (data-architecture §8.2)';
    $lines[] = '';
    $lines[] = 'Two independent providers are diffed before import; any disagreement';
    $lines[] = 'halts the pipeline (resolution = printed Madinah Mushaf):';
    $lines[] = '';
    $lines[] = '| field | value |';
    $lines[] = '| --- | --- |';
    foreach ($entries as $entry) {
        if ($entry['role'] === 'primary') {
            continue;
        }
        $lines[] = '| ' . $entry['label'] . ' | ' . $entry['url'] . ' |';
        $lines[] = '| terms | ' . $entry['license'] . ' (' . $entry['license_url'] . ') |';
        $lines[] = '| file | `' . $entry['file'] . '` (' . $entry['bytes'] . ' bytes) |';
        $lines[] = '| SHA-256 | `' . $entry['sha256'] . '` |';
        $lines[] = '| contains | ' . $entry['contains'] . ' |';
        $lines[] = '';
    }
    $lines[] = 'Why multiple sources: the primary file alone cannot independently prove';
    $lines[] = 'page straddles (it stores page *starts* only); the cross-check provider';
    $lines[] = 'stores per-ayah start pages and division memberships, so boundaries are';
    $lines[] = 'verified against genuinely separate data sets.';
    $lines[] = '';
    $lines[] = '## Reproduce';
    $lines[] = '';
    $lines[] = '```text';
    $lines[] = 'php tools/quran-data/acquire.php       # download + sidecars + this file';
    $lines[] = 'php tools/quran-data/normalize.php     # processed/*.json (deterministic)';
    $lines[] = 'php tools/quran-data/verify.php        # verification/report-*.json';
    $lines[] = 'php tools/quran-data/import.php --dry-run';
    $lines[] = 'php tools/quran-data/import.php --execute';
    $lines[] = 'php tools/quran-data/audit.php         # invariants against the database';
    $lines[] = '```';
    $lines[] = '';
    $lines[] = 'No API keys or private credentials are used by any stage. Ayah *text*';
    $lines[] = 'is fetched by the cross-check file but never stored by this pipeline.';
    $lines[] = '';
    return implode("\n", $lines);
}

quranRunCli(function (): void {
    $args = quranCliArgs();
    $force = in_array('--force', $args, true);
    $unknown = array_diff($args, ['--force']);
    if ($unknown !== []) {
        quranFail('unknown argument(s): ' . implode(' ', $unknown) . ' (usage: acquire.php [--force])');
    }
    quranEnsureDirs();

    $acquiredAt = gmdate('c');
    $manifestPath = quranSourceDir() . '/acquisition.json';
    $entries = [];
    $pendingWrites = [];

    foreach (QURAN_SOURCES as $key => $source) {
        $path = quranSourceDir() . '/' . $source['file'];
        $entry = [
            'key' => $key,
            'label' => $source['label'],
            'role' => $source['role'],
            'url' => $source['url'],
            'dataset' => $source['file'],
            'file' => $source['file'],
            'license' => $source['license'],
            'license_url' => $source['license_url'],
            'copyright' => $source['copyright'],
            'contains' => $source['contains'],
        ];

        if (!$force && is_file($path) && is_file($path . '.sha256')) {
            $entry['sha256'] = quranVerifySidecar($path);
            $entry['bytes'] = (int) filesize($path);
            $entry['version'] = $key === 'tanzil_metadata'
                ? (preg_match('#<quran[^>]*\bversion="([^"]+)"#', (string) file_get_contents($path), $m) === 1 ? $m[1] : 'unknown')
                : 'API v1';
            $entry['mode'] = 'verified existing';
            $entries[] = $entry;
            echo "ok      {$source['file']} (existing, checksum verified)\n";
            continue;
        }

        echo "fetch   {$source['url']}\n";
        $body = quranHttpGet($source['url']);
        $version = quranValidateSourceBody($key, $body);
        $entry['sha256'] = hash('sha256', $body);
        $entry['bytes'] = strlen($body);
        $entry['version'] = $version;
        $entry['mode'] = 'downloaded';
        $entries[] = $entry;
        $pendingWrites[$key] = ['path' => $path, 'body' => $body];
    }

    foreach ($pendingWrites as $key => $write) {
        quranWriteFile($write['path'], $write['body']);
        quranWriteSidecar($write['path']);
        echo 'saved   ' . basename($write['path']) . ' (' . strlen($write['body']) . " bytes)\n";
    }

    if ($pendingWrites === [] && is_file($manifestPath)) {
        $previous = quranReadJson($manifestPath);
        if (isset($previous['acquired_at']) && is_string($previous['acquired_at']) && $previous['acquired_at'] !== '') {
            $acquiredAt = $previous['acquired_at'];
        }
    }

    $manifest = [
        'acquired_at' => $acquiredAt,
        'tool' => 'tools/quran-data/acquire.php v' . QURAN_TOOL_VERSION,
        'sources' => array_map(
            static fn (array $e): array => [
                'key' => $e['key'],
                'role' => $e['role'],
                'url' => $e['url'],
                'file' => $e['file'],
                'version' => $e['version'],
                'sha256' => $e['sha256'],
                'bytes' => $e['bytes'],
                'license' => $e['license'],
                'license_url' => $e['license_url'],
                'mode' => $e['mode'],
            ],
            $entries
        ),
    ];
    quranWriteFile(quranSourceDir() . '/acquisition.json', quranEncodeJson($manifest));
    quranWriteFile(quranSourceDir() . '/SOURCE.md', quranBuildSourceDoc($entries, $acquiredAt));

    echo "manifest source/acquisition.json + source/SOURCE.md written\n";
    foreach ($entries as $entry) {
        echo sprintf("  %-32s %10d bytes  sha256:%s  [%s]\n", $entry['file'], $entry['bytes'], substr($entry['sha256'], 0, 16) . '...', $entry['mode']);
    }
    echo "next: php tools/quran-data/normalize.php\n";
});
