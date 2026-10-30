<?php

namespace App\Console\Commands;

use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet up
 *
 * Readers are welcome again (after php booklet down).
 */
class BookletUp extends Command
{
    protected $signature = 'booklet:up';

    protected $description = 'Let readers back in after php booklet down';

    public function handle(): int
    {
        Upkeep::welcomeReadersBack()
            ? $this->info('Readers are welcome again.')
            : $this->line('Readers weren’t being kept away.');

        return self::SUCCESS;
    }
}
