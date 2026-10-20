<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletPictures extends Command
{
    protected $signature = 'booklet:pictures {--clear : Throw away every sized copy (they\'re remade as needed)}';

    protected $description = 'Make the smaller copies of every picture in Media now (or --clear them)';

    public function handle()
    {
        if ($this->option('clear')) {
            $this->info('Removed '.\App\Support\Pictures::clear().' sized copies.');

            return;
        }
        if (! \App\Support\Pictures::available()) {
            $this->error('This PHP can’t make WebP pictures (the GD extension with WebP is needed). The original pictures are used as they are.');

            return;
        }
        $made = 0;
        foreach (\App\Booklet\Facades\Booklet::assets()->all() as $asset) {
            if (! \App\Support\Pictures::resizable($asset->path(), $asset->container())) {
                continue;
            }
            foreach (\App\Support\Pictures::WIDTHS as $width) {
                if (\App\Support\Pictures::make($asset->path(), $width)) {
                    $made++;
                }
            }
        }
        $this->info("Made {$made} sized copies (any already made were kept).");
    }
}
