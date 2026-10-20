<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;

class Inspire extends Command
{
    protected $signature = 'inspire';

    protected $description = 'Display an inspiring quote';

    public function handle()
    {
        $this->comment(Inspiring::quote());
    }
}
