<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletWeeklyLetter extends Command
{
    protected $signature = 'booklet:weekly-letter {--force : Send again even if this week\'s already went}';

    protected $description = 'Send Stacy\'s weekly summary to everyone who has it switched on';

    public function handle()
    {
        $sent = \App\Stacy\WeeklyLetter::sendAll((bool) $this->option('force'));
        $this->info($sent ? "Sent {$sent} weekly ".\Illuminate\Support\Str::plural('letter', $sent).'.' : 'Nobody is waiting for a letter this week.');
    }
}
