<?php

namespace App\Http\Controllers;

use App\Booklet\Facades\Booklet;
use App\Support\ShareImage;

/**
 * A share card that hasn't been drawn yet: draw it, keep it, send it. (Once
 * it exists the web server sends the file itself.)
 */
class ShareImageController extends Controller
{
    public function __invoke(string $file)
    {
        abort_unless(ShareImage::available() && preg_match('/^(site|[\w-]{1,64})-([a-f0-9]{10})\.png$/', $file, $m), 404);
        [, $id, $fingerprint] = $m;
        $disk = \App\Support\Disk::of('public');

        if ($id === 'site') {
            if ($fingerprint !== ShareImage::siteFingerprint()) {
                return redirect(ShareImage::siteUrl());
            }
            $png = ShareImage::drawSite();
        } else {
            $entry = Booklet::entries()->find($id);
            abort_unless($entry && $entry->status() === 'published' && ShareImage::drawable($entry->title()), 404);
            if ($fingerprint !== ShareImage::fingerprint($entry)) {
                return redirect(ShareImage::urlFor($entry)); // the title changed since this was shared
            }
            $png = ShareImage::draw($entry);
        }

        // Older cards for the same page go (titles change).
        foreach ($disk->files(ShareImage::FOLDER) as $old) {
            if (str_starts_with(basename($old), $id.'-')) {
                $disk->delete($old);
            }
        }
        $disk->put(ShareImage::FOLDER.'/'.$file, $png);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
