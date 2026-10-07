<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Request;
use App\Response;
use App\Services\QuranStructureService;

/**
 * Canonical Quran structure reads (Prompt 08) exposed to the frontend.
 * The dataset is shared reference data — there is no per-user dimension, so
 * no ownership rule applies beyond the AuthMiddleware on the route.
 * Calculations and dataset checks live in QuranStructureService (§9).
 */
final class QuranController extends Controller
{
    private QuranStructureService $structure;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->structure = new QuranStructureService();
    }

    /**
     * Ayahs on a page in reading order — backs the flag-error ayah picker.
     *
     * Only ayahs that START on this page are returned (continues_previous = 0).
     * Ayahs that begin on a previous page and spill onto this one are excluded
     * because FlipCardService::validateLocation() maps each ayah to its
     * canonical starting page and would reject a card created for the
     * continuation page (422 "This ayah does not appear on that page").
     */
    public function pageAyahs(array $params = []): Response
    {
        $page = $this->routeId($params, 'page_number');

        $ayahs = [];
        foreach ($this->structure->pageAyahs($page) as $row) {
            // Skip ayahs whose start is on a previous page — they cannot be
            // flagged against this page number.
            if ((int) $row['continues_previous'] === 1) {
                continue;
            }
            $ayahs[] = [
                'surah_number' => (int) $row['surah_number'],
                'ayah_number' => (int) $row['ayah_number'],
                'ayah_index' => (int) $row['ayah_index'],
            ];
        }

        return $this->success(['page_number' => $page, 'ayahs' => $ayahs]);
    }
}
