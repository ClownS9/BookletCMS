<?php

namespace App\Http\Controllers;

use App\Themes\ThemeManager;

/**
 * A theme's own files (styles, fonts, pictures; scripts for a theme with
 * code), from themes/{theme}/assets/. Only from that folder, and only kinds
 * of file a page can use. Pictures in SVG were cleaned when the theme was
 * added, and are sent so a browser won't run anything in them.
 */
class ThemeAssetController extends Controller
{
    public const TYPES = [
        'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    public function __invoke(string $theme, string $path)
    {
        $found = ThemeManager::find($theme);
        abort_unless($found !== null, 404);

        $root = realpath($found->path.'/assets');
        $file = $root ? realpath($root.'/'.$path) : false;
        $ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
        $type = self::TYPES[$ext] ?? null;
        // A cover pack's scripts never run, even if one is there.
        abort_unless($file && $type && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) && ($ext !== 'js' || $found->codeOn()), 404);

        $headers = [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*', // fonts load from another address on a plain-file copy, say
        ];
        if ($ext === 'svg') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return response()->file($file, $headers);
    }
}
