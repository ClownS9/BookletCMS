<?php

namespace App\Http\Controllers;

use App\Inserts\InsertManager;

/**
 * An insert's own files (its CSS, scripts, pictures, fonts), from
 * inserts/{insert}/assets/. Only for inserts that are switched on, only
 * from that folder, and only kinds of file a page can use.
 */
class InsertAssetController extends Controller
{
    public const TYPES = [
        'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'json' => 'application/json',
    ];

    public function __invoke(InsertManager $inserts, string $insert, string $path)
    {
        $found = $inserts->active()->get($insert);
        abort_unless($found !== null, 404);

        $root = realpath($found->path.'/assets');
        $file = $root ? realpath($root.'/'.$path) : false;
        $type = self::TYPES[strtolower(pathinfo((string) $file, PATHINFO_EXTENSION))] ?? null;
        abort_unless($file && $type && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
