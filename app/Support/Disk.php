<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A storage disk, typed as what it really is. Storage::disk() promises only
 * the basic Filesystem contract, which has no url() or mimeType(), so
 * editors flag those calls even though every disk Booklet uses (local,
 * public, s3, and the fakes in tests) is a FilesystemAdapter that has them.
 */
class Disk
{
    public static function of(?string $name = null): FilesystemAdapter
    {
        $disk = Storage::disk($name);
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The "'.($name ?? 'default').'" disk isn’t a standard Laravel disk, so Booklet can’t make links to its files.');
        }

        return $disk;
    }
}
