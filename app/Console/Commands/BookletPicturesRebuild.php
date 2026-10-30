<?php

namespace App\Console\Commands;

use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet pictures:rebuild
 *
 * Every sized copy and crop thrown away and the sized copies made again
 * (after changing picture sizes, or if some look wrong).
 */
class BookletPicturesRebuild extends Command
{
    protected $signature = 'booklet:pictures:rebuild';

    protected $description = 'Throw away every sized copy of the pictures and make them again';

    public function handle(): int
    {
        $this->line('Remaking the pictures. A big Media can take a few minutes…');
        $r = Upkeep::rebuildPictures(fn () => $this->output->write('.'));
        $this->newLine();
        if (! $r['available']) {
            $this->warn("Removed {$r['removed']} old copies. This PHP can’t make WebP pictures (the GD extension with WebP is needed), so the originals are used.");

            return self::SUCCESS;
        }
        $this->info("Removed {$r['removed']} old copies and made {$r['made']}.");

        return self::SUCCESS;
    }
}
