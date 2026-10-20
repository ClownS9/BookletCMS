<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletLinks extends Command
{
    protected $signature = 'booklet:links';

    protected $description = 'Check every link in published posts and pages, and list the broken ones';

    public function handle()
    {
        $report = \App\Support\LinkCheck::run();
        $this->info('Checked '.$report['links'].' links.');
        foreach ($report['broken'] as $bad) {
            $this->line(' · '.$bad['url'].' in “'.$bad['title'].'”: '.$bad['reason']);
        }
        if ($report['broken'] === []) {
            $this->info('None broken.');
        }
    }
}
