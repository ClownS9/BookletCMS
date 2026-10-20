<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletSendPosts extends Command
{
    protected $signature = 'booklet:send-posts';

    protected $description = 'Email subscribers any new posts that are due (the scheduler does this every five minutes)';

    public function handle()
    {
        $n = \App\Support\Newsletter::sendDue();
        $this->info($n ? "Sent {$n} emails." : 'Nothing waiting to go out.');
    }
}
