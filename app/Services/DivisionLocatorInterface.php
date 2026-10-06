<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DivisionType;

/**
 * Division-lookup seam (conventions §9) so revision targeting can be
 * unit-tested without the canonical dataset. The real implementation is
 * App\Services\QuranStructureService.
 */
interface DivisionLocatorInterface
{
    /**
     * Division covering the page; when a page sits inside two divisions
     * (shared boundary), the one that started latest wins.
     * Null = no such division (caller decides whether that is an error).
     */
    public function divisionNumberAtPage(DivisionType $type, int $page): ?int;

    /** End page of a division, or null when the number does not exist. */
    public function divisionEndPage(DivisionType $type, int $number): ?int;
}
