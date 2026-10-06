<?php

declare(strict_types=1);

/**
 * WCAG contrast audit for the design tokens (Prompt 20).
 *
 * Parses public/css/tokens.css (light :root + dark media block), computes
 * contrast ratios for every foreground/background pair the UI actually
 * renders, and exits non-zero when a pair misses its threshold.
 *
 * Usage: php tools/check-contrast.php
 */

const MIN_TEXT = 4.5;      // WCAG 1.4.3, normal text
const MIN_NON_TEXT = 3.0;  // WCAG 1.4.11, focus ring / UI indicators

/** @var array<int, array{0:string,1:string,2:float,3:string}> */
const PAIRS = [
    ['--color-text', '--color-bg', MIN_TEXT, 'body text on page background'],
    ['--color-text', '--color-surface', MIN_TEXT, 'body text on cards/inputs'],
    ['--color-text', '--color-surface-2', MIN_TEXT, 'body text on subtle surfaces'],
    ['--color-text', '--color-muted-soft', MIN_TEXT, 'count badges'],
    ['--color-text-muted', '--color-surface', MIN_TEXT, 'muted text on cards'],
    ['--color-text-muted', '--color-bg', MIN_TEXT, 'muted text on background'],
    ['--color-primary', '--color-surface', MIN_TEXT, 'link text on cards'],
    ['--color-primary', '--color-bg', MIN_TEXT, 'link text on background'],
    ['--color-primary', '--color-primary-soft', MIN_TEXT, 'active toggle label'],
    ['--color-primary-strong', '--color-primary-soft', MIN_TEXT, 'inline alerts'],
    ['--color-on-primary', '--color-primary', MIN_TEXT, 'primary button label'],
    ['--color-on-primary', '--color-success', MIN_TEXT, 'success toast text'],
    ['--color-on-primary', '--color-danger', MIN_TEXT, 'error toast text'],
    ['--color-success', '--color-success-soft', MIN_TEXT, 'success alerts'],
    ['--color-danger', '--color-danger-soft', MIN_TEXT, 'danger alerts / field errors'],
    ['--color-warning', '--color-warning-soft', MIN_TEXT, 'warning alerts / offline banner'],
    ['--color-focus', '--color-surface', MIN_NON_TEXT, 'focus ring on cards'],
    ['--color-focus', '--color-bg', MIN_NON_TEXT, 'focus ring on background'],
];

function hexToRgb(string $value): ?array
{
    $value = trim($value);
    if (preg_match('/^#([0-9a-f]{3})$/i', $value, $m) === 1) {
        $hex = $m[1];
        return [
            hexdec($hex[0] . $hex[0]),
            hexdec($hex[1] . $hex[1]),
            hexdec($hex[2] . $hex[2]),
        ];
    }
    if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m) === 1) {
        $hex = $m[1];
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
    if (preg_match('/^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)/', $value, $m) === 1) {
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
    return null;
}

function relativeLuminance(array $rgb): float
{
    $channels = array_map(static function (int $c): float {
        $s = $c / 255;
        return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }, $rgb);

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(array $fg, array $bg): float
{
    $l1 = relativeLuminance($fg);
    $l2 = relativeLuminance($bg);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);

    return ($lighter + 0.05) / ($darker + 0.05);
}

function parseTokens(string $css, string $blockPattern): array
{
    if (preg_match($blockPattern, $css, $m) !== 1) {
        fwrite(STDERR, "check-contrast: token block not found\n");
        exit(2);
    }
    preg_match_all('/--(color-[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,6}|rgb[^;]+)/', $m[1], $pairs, PREG_SET_ORDER);
    $tokens = [];
    foreach ($pairs as $pair) {
        $tokens['--' . $pair[1]] = $pair[2];
    }
    return $tokens;
}

$cssPath = __DIR__ . '/../public/css/tokens.css';
if (!is_file($cssPath)) {
    fwrite(STDERR, "check-contrast: tokens.css not found at $cssPath\n");
    exit(2);
}
$css = file_get_contents($cssPath);
if ($css === false) {
    fwrite(STDERR, "check-contrast: cannot read tokens.css\n");
    exit(2);
}

// First :root block = light; the dark prefers-color-scheme block = dark.
$light = parseTokens($css, '/^:root\s*\{(.*?)\n\}/s');
$dark = parseTokens($css, '/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root\s*\{(.*?)\n\s*\}\s*\n\}/s');
$dark = array_merge($light, $dark); // dark inherits anything not restated

$failures = 0;
$checked = 0;

foreach (['light' => $light, 'dark' => $dark] as $theme => $tokens) {
    foreach (PAIRS as [$fgName, $bgName, $min, $label]) {
        if (!isset($tokens[$fgName], $tokens[$bgName])) {
            echo "FAIL  $theme  $fgName on $bgName  token missing\n";
            $failures++;
            continue;
        }
        $fg = hexToRgb($tokens[$fgName]);
        $bg = hexToRgb($tokens[$bgName]);
        if ($fg === null || $bg === null) {
            echo "FAIL  $theme  $fgName on $bgName  unparsable color\n";
            $failures++;
            continue;
        }
        $ratio = contrastRatio($fg, $bg);
        $checked++;
        $ok = $ratio >= $min;
        if (!$ok) {
            $failures++;
        }
        printf(
            "%-4s  %-5s  %-24s on %-24s  %5.2f (min %.1f)  %s\n",
            $ok ? 'ok' : 'FAIL',
            $theme,
            $fgName,
            $bgName,
            $ratio,
            $min,
            $label
        );
    }
}

echo '----' . PHP_EOL;
echo "contrast: $checked pairs, $failures failures" . PHP_EOL;
exit($failures > 0 ? 1 : 0);
