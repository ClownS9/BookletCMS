<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletPagesClear extends Command
{
    protected $signature = 'booklet:pages-clear';

    protected $description = 'Forget the kept copies of public pages (after changing theme files, say)';

    public function handle()
    {
        \App\Support\PageCache::flush();
        $this->info('Done. Public pages are put together fresh for the next readers.');
    }
}
