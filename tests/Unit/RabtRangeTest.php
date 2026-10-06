<?php

declare(strict_types=1);

/**
 * Unit tests: rolling Rabt (ربط) bounds (Prompt 12) — no database.
 * Run: php tests/Unit/RabtRangeTest.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\AppException;
use App\Services\HifzCalculationService;

$hifz = new HifzCalculationService();

// --- window cap at 30 (illustrative numbers from the prompt) ---------------
$b = $hifz->rabtBounds(1, 270);
checkEquals('boundary 270: start', 241, $b['start_page']);
checkEquals('boundary 270: end', 270, $b['end_page']);
checkEquals('boundary 270: count capped at 30', 30, $b['page_count']);

$b = $hifz->rabtBounds(1, 271);
checkEquals('boundary 271: start slides', 242, $b['start_page']);
checkEquals('boundary 271: end', 271, $b['end_page']);
checkEquals('boundary 271: count', 30, $b['page_count']);

// --- fewer memorized pages than the cap → use them all ---------------------
$b = $hifz->rabtBounds(250, 270);
checkEquals('21 memorized: start stays at memorized start', 250, $b['start_page']);
checkEquals('21 memorized: end', 270, $b['end_page']);
checkEquals('21 memorized: count', 21, $b['page_count']);

$b = $hifz->rabtBounds(1, 10);
checkEquals('10 memorized: start', 1, $b['start_page']);
checkEquals('10 memorized: count', 10, $b['page_count']);

$b = $hifz->rabtBounds(5, 5);
checkEquals('single page: start', 5, $b['start_page']);
checkEquals('single page: end', 5, $b['end_page']);
checkEquals('single page: count', 1, $b['page_count']);

// --- exact fit --------------------------------------------------------------
$b = $hifz->rabtBounds(241, 270);
checkEquals('exactly 30 memorized: start', 241, $b['start_page']);
checkEquals('exactly 30 memorized: count', 30, $b['page_count']);

// --- smaller windows (seam used by tests; endpoint always uses 30) ---------
$b = $hifz->rabtBounds(1, 270, 5);
checkEquals('max 5: start', 266, $b['start_page']);
checkEquals('max 5: count', 5, $b['page_count']);

$b = $hifz->rabtBounds(1, 271, 5);
checkEquals('max 5 slides with boundary', 267, $b['start_page']);

$b = $hifz->rabtBounds(1, 270, 1);
checkEquals('max 1: newest page only', 270, $b['start_page']);
checkEquals('max 1: count', 1, $b['page_count']);

// --- start can never be pushed below the memorized start -------------------
$b = $hifz->rabtBounds(268, 270, 30);
checkEquals('window never below memorized start', 268, $b['start_page']);
check('window end equals boundary', $b['end_page'] === 270);
check('window count fits the memorized range', $b['page_count'] === 3);

// --- fail closed ------------------------------------------------------------
checkThrows(
    'boundary below memorized start',
    fn () => $hifz->rabtBounds(10, 5),
    AppException::class
);
checkThrows(
    'window below one page',
    fn () => $hifz->rabtBounds(1, 270, 0),
    AppException::class
);

check('MAX_RABT_PAGES is 30', HifzCalculationService::MAX_RABT_PAGES === 30);

exit(summary('RabtRange'));
