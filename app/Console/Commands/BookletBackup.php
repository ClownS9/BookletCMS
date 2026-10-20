<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletBackup extends Command
{
    protected $signature = 'booklet:backup';

    protected $description = 'Back up the whole book (content, settings, media, people) to one .zip';

    public function handle()
    {
        $made = \App\Support\Backups::create('manual');
        $this->info('Backed up to storage/app/private/'.\App\Support\Backups::DIR.'/'.$made['name'].' ('.number_format($made['size'] / 1048576, 1).' MB).');
    }
}
