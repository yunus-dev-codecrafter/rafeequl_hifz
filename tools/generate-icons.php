<?php

declare(strict_types=1);

/**
 * Generates the PWA icon set from the master icon (Prompt 19).
 *
 * Pure PHP (no GD/ImageMagick): decodes the source PNG with zlib, box-
 * downsamples it and re-encodes the derived sizes. Idempotent — run:
 *
 *   php tools/generate-icons.php
 *
 * Inputs/outputs:
 *   tools/app-icon.png     master, source only — kept OUTSIDE the docroot
 *                          (Prompt 26: a 1.27 MB source file has no business
 *                          under public/), never served, never modified
 *   public/assets/icons/   generated outputs:
 *     icon-192.png          manifest, any purpose
 *     icon-512.png          manifest, any purpose
 *     icon-maskable-512.png manifest, maskable (80% safe zone on bg color)
 *     apple-touch-icon-180.png iOS home-screen icon (opaque, full bleed)
 */

const SRC = __DIR__ . '/app-icon.png';
const OUT_DIR = __DIR__ . '/../public/assets/icons';

/** @return array{width: int, height: int, pixels: string} RGB888 rows, no filter bytes */
function decodePng(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException('source is not a PNG: ' . $path);
    }

    $pos = 8;
    $idat = '';
    $width = $height = $bitDepth = $colorType = $interlace = 0;

    $length = strlen($raw);
    while ($pos + 8 <= $length) {
        $len = unpack('N', substr($raw, $pos, 4))[1];
        $type = substr($raw, $pos + 4, 4);
        $data = substr($raw, $pos + 8, $len);
        $pos += 12 + $len;

        if ($type === 'IHDR') {
            $unpacked = unpack('Nwidth/Nheight/Cbitdepth/Ccolortype/Ccompression/Cfilter/Cinterlace', $data);
            $width = $unpacked['width'];
            $height = $unpacked['height'];
            $bitDepth = $unpacked['bitdepth'];
            $colorType = $unpacked['colortype'];
            $interlace = $unpacked['interlace'];
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
    }

    if ($bitDepth !== 8 || $colorType !== 2 || $interlace !== 0) {
        throw new RuntimeException("unsupported PNG (need 8-bit RGB, non-interlaced; got depth=$bitDepth type=$colorType interlace=$interlace)");
    }

    $inflated = zlib_decode($idat);
    if ($inflated === false || strlen($inflated) !== $height * (1 + $width * 3)) {
        throw new RuntimeException('PNG data could not be inflated');
    }

    $stride = $width * 3;
    $pixels = str_repeat("\0", $height * $stride);
    $previous = str_repeat("\0", $stride);

    for ($y = 0; $y < $height; $y++) {
        $offset = $y * (1 + $stride);
        $filter = ord($inflated[$offset]);
        $line = substr($inflated, $offset + 1, $stride);

        $out = match ($filter) {
            0 => $line,
            1 => unfilterSub($line, $stride, 3),
            2 => unfilterUp($line, $previous, $stride),
            3 => unfilterAverage($line, $previous, $stride),
            4 => unfilterPaeth($line, $previous, $stride),
            default => throw new RuntimeException('unsupported PNG filter: ' . $filter),
        };

        $pixels = substr_replace($pixels, $out, $y * $stride, $stride);
        $previous = $out;
    }

    return ['width' => $width, 'height' => $height, 'pixels' => $pixels];
}

function unfilterSub(string $line, int $stride, int $bpp): string
{
    $out = $line;
    for ($i = $bpp; $i < $stride; $i++) {
        $out[$i] = chr((ord($out[$i]) + ord($out[$i - $bpp])) & 0xFF);
    }
    return $out;
}

function unfilterUp(string $line, string $previous, int $stride): string
{
    $out = $line;
    for ($i = 0; $i < $stride; $i++) {
        $out[$i] = chr((ord($out[$i]) + ord($previous[$i])) & 0xFF);
    }
    return $out;
}

function unfilterAverage(string $line, string $previous, int $stride): string
{
    $out = $line;
    for ($i = 0; $i < $stride; $i++) {
        $left = $i >= 3 ? ord($out[$i - 3]) : 0;
        $up = ord($previous[$i]);
        $out[$i] = chr((ord($out[$i]) + intdiv($left + $up, 2)) & 0xFF);
    }
    return $out;
}

function unfilterPaeth(string $line, string $previous, int $stride): string
{
    $out = $line;
    for ($i = 0; $i < $stride; $i++) {
        $a = $i >= 3 ? ord($out[$i - 3]) : 0;
        $b = ord($previous[$i]);
        $c = $i >= 3 ? ord($previous[$i - 3]) : 0;
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        $pred = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
        $out[$i] = chr((ord($out[$i]) + $pred) & 0xFF);
    }
    return $out;
}

/** Box-filter downsample (area average) — quality resize without GD. */
function resize(array $img, int $targetW, int $targetH): string
{
    $srcW = $img['width'];
    $srcH = $img['height'];
    $src = $img['pixels'];
    $out = str_repeat("\0", $targetW * $targetH * 3);

    for ($ty = 0; $ty < $targetH; $ty++) {
        $y0 = intdiv($ty * $srcH, $targetH);
        $y1 = max($y0 + 1, intdiv(($ty + 1) * $srcH, $targetH));
        for ($tx = 0; $tx < $targetW; $tx++) {
            $x0 = intdiv($tx * $srcW, $targetW);
            $x1 = max($x0 + 1, intdiv(($tx + 1) * $srcW, $targetW));

            $r = $g = $b = 0;
            $count = 0;
            for ($y = $y0; $y < $y1; $y++) {
                $row = $y * $srcW * 3;
                for ($x = $x0; $x < $x1; $x++) {
                    $i = $row + $x * 3;
                    $r += ord($src[$i]);
                    $g += ord($src[$i + 1]);
                    $b += ord($src[$i + 2]);
                    $count++;
                }
            }

            $o = ($ty * $targetW + $tx) * 3;
            $out[$o] = chr(intdiv($r, $count));
            $out[$o + 1] = chr(intdiv($g, $count));
            $out[$o + 2] = chr(intdiv($b, $count));
        }
    }

    return $out;
}

/** Average of the four corner pixels — used as the maskable backdrop. */
function cornerColor(array $img): array
{
    $w = $img['width'];
    $h = $img['height'];
    $src = $img['pixels'];
    $points = [0, ($w - 1) * 3, ($h - 1) * $w * 3, (($h - 1) * $w + $w - 1) * 3];
    $r = $g = $b = 0;
    foreach ($points as $i) {
        $r += ord($src[$i]);
        $g += ord($src[$i + 1]);
        $b += ord($src[$i + 2]);
    }
    return [intdiv($r, 4), intdiv($g, 4), intdiv($b, 4)];
}

/** Centers $fg (WxH*3) on a solid background canvas. */
function compose(int $canvasW, int $canvasH, array $bg, string $fg, int $fgW, int $fgH): string
{
    $out = str_repeat("\0", $canvasW * $canvasH * 3);
    $bgHex = chr($bg[0]) . chr($bg[1]) . chr($bg[2]);
    for ($i = 0; $i < $canvasW * $canvasH; $i++) {
        $out[$i * 3] = $bgHex[0];
        $out[$i * 3 + 1] = $bgHex[1];
        $out[$i * 3 + 2] = $bgHex[2];
    }

    $ox = intdiv($canvasW - $fgW, 2);
    $oy = intdiv($canvasH - $fgH, 2);
    for ($y = 0; $y < $fgH; $y++) {
        $dstRow = ($oy + $y) * $canvasW + $ox;
        $srcRow = $y * $fgW;
        for ($x = 0; $x < $fgW; $x++) {
            $s = ($srcRow + $x) * 3;
            $d = ($dstRow + $x) * 3;
            $out[$d] = $fg[$s];
            $out[$d + 1] = $fg[$s + 1];
            $out[$d + 2] = $fg[$s + 2];
        }
    }

    return $out;
}

/** Encodes raw RGB888 into a PNG file (filter 0, zlib-deflated). */
function encodePng(int $width, int $height, string $rgb): string
{
    $stride = $width * 3;
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00" . substr($rgb, $y * $stride, $stride);
    }

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', $ihdr)
        . $chunk('IDAT', zlib_encode($raw, ZLIB_ENCODING_DEFLATE, 9))
        . $chunk('IEND', '');
}

function writePng(string $path, string $rgb, int $w, int $h): void
{
    if (file_put_contents($path, encodePng($w, $h, $rgb)) === false) {
        throw new RuntimeException('cannot write ' . $path);
    }
    printf("wrote  %-26s %4dx%-4d %6.1f KB\n", basename($path), $w, $h, filesize($path) / 1024);
}

$source = decodePng(SRC);
printf(
    "source %s %dx%d (%.1f MB)\n",
    basename(SRC),
    $source['width'],
    $source['height'],
    filesize(SRC) / 1048576
);

// Sanity: a real icon must have tonal range; all-black means a decode bug.
$sMin = 255;
$sMax = 0;
for ($i = 0, $n = $source['width'] * $source['height']; $i < $n; $i += 97) {
    $v = ord($source['pixels'][$i * 3]);
    $sMin = min($sMin, $v);
    $sMax = max($sMax, $v);
}
if ($sMax - $sMin < 16) {
    throw new RuntimeException("decoded source looks uniform (min=$sMin max=$sMax) — aborting");
}
printf("source range: min=%d max=%d\n", $sMin, $sMax);

// Standard icons (any purpose): straight downsample.
writePng(OUT_DIR . '/icon-192.png', resize($source, 192, 192), 192, 192);
writePng(OUT_DIR . '/icon-512.png', resize($source, 512, 512), 512, 512);

// Maskable: content inside the 80% safe zone on the icon's own backdrop.
$bg = cornerColor($source);
$maskFg = resize($source, 410, 410);
writePng(OUT_DIR . '/icon-maskable-512.png', compose(512, 512, $bg, $maskFg, 410, 410), 512, 512);

// iOS home-screen icon: opaque full bleed.
writePng(OUT_DIR . '/apple-touch-icon-180.png', resize($source, 180, 180), 180, 180);

echo "done\n";
