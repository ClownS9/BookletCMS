<?php

namespace App\Http\Controllers;

use App\Support\Crops;
use App\Support\Pictures;

/**
 * A named size of a picture that hasn't been cut yet: cut it, keep it, send
 * it. Only addresses the site itself hands out are cut (the right size, the
 * picture's current focal point); anything else gets the original picture.
 */
class CropController extends Controller
{
    public function __invoke(string $size, string $spec, string $path)
    {
        $original = preg_replace('/\.webp$/', '', $path);

        if (str_contains($original, '..') || ! Pictures::disk()->exists($original)) {
            abort(404);
        }

        $made = Crops::make($size, $spec, $original);

        if (! $made) {
            return redirect(Pictures::disk()->url($original), 302);
        }

        return response()->file(Pictures::disk()->path($made), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
