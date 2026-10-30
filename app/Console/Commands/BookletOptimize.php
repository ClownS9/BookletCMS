<?php

namespace App\Console\Commands;

use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet optimize
 *
 * Settings, routes, events and templates prepared ahead, for a live server.
 * Run it again after changing .env; `php booklet cache:clear` undoes it.
 */
class BookletOptimize extends Command
{
    protected $signature = 'booklet:optimize';

    protected $description = 'Make Booklet quick on a live server (settings, routes and templates prepared ahead)';

    public function handle(): int
    {
        $result = Upkeep::optimize();
        foreach ($result['done'] as $line) {
            $this->line('  <fg=green>✓</> '.$line);
        }
        foreach ($result['failed'] as $line) {
            $this->line('  <fg=yellow>!</> '.$line);
        }
        $this->info('Done. After changing .env, run php booklet optimize again (or php booklet cache:clear).');

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
