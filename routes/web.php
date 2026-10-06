<?php

declare(strict_types=1);

use App\Controllers\ShellController;

/*
 * Web routes (conventions §7): non-API pages.
 * Currently a single SPA shell — navigation happens client-side via the
 * hash router, so every screen is reached through GET /.
 */

return [
    ['GET', '/', [ShellController::class, 'shell']],
];
