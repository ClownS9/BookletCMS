<?php

namespace App\Http\Controllers;

use App\Support\SiteIcons;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The site's icons and Booklet's (see SiteIcons), and the web app manifest.
 * Their links carry a fingerprint (?v=…), so they can be kept a long time.
 */
class IconController extends Controller
{
    public function show(string $set, string $file): BinaryFileResponse
    {
        $found = SiteIcons::file($set, $file);
        abort_unless($found !== null, 404);
        [$path, $type] = $found;

        $response = response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => request()->query('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($type === 'image/svg+xml') {
            // An uploaded SVG was cleaned when it arrived; this keeps it a picture, never a page.
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        return $response;
    }

    public function manifest(): JsonResponse
    {
        return response()->json(SiteIcons::manifest(), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
