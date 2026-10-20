<?php

namespace App\Http\Controllers;

use App\Support\Pictures;

/**
 * A sized copy of a picture that hasn't been made yet: make it, keep it, send
 * it. (Once it exists the web server serves the file itself and this isn't
 * reached.) If a copy can't be made, the original is sent instead.
 */
class PictureController extends Controller
{
    public function __invoke(int $width, string $path)
    {
        $original = preg_replace('/\.webp$/', '', $path);

        if (str_contains($original, '..') || ! Pictures::disk()->exists($original)) {
            abort(404);
        }

        $made = Pictures::make($original, $width);

        if (! $made) {
            return redirect(Pictures::disk()->url($original), 302);
        }

        return response()->file(Pictures::disk()->path($made), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
