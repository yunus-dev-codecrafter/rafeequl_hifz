<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Response;

/**
 * Serves the SPA HTML shell (Prompt 11) — presentation glue only.
 *
 * The shell is a static file sitting in the web document root next to
 * index.php (dev layout: public/shell.html; deployed shared-hosting
 * layout: htdocs/shell.html — see docs/deployment/infinityfree.md);
 * all data comes from the /api/v1 endpoints, and the client-side hash
 * router handles navigation, so no other server-rendered pages exist.
 */
final class ShellController extends Controller
{
    /** GET / — the application shell. */
    public function shell(array $params = []): Response
    {
        $html = false;

        // Document root first: it is the directory the web server actually
        // serves, so it is correct under any layout.
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot !== '') {
            $html = @file_get_contents($documentRoot . '/shell.html');
        }

        // CLI / no-document-root fallback: the development layout.
        if ($html === false) {
            $html = @file_get_contents(BASE_PATH . '/public/shell.html');
        }

        if ($html === false) {
            throw new AppException('Application shell not found');
        }

        return Response::html($html);
    }
}
