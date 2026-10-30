<?php

namespace App\Console\Commands;

use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet cache:clear
 *
 * Everything that's safe to clear, cleared: kept pages, compiled templates,
 * cached settings and routes. Booklet's working notes stay (unlike Laravel's
 * own cache:clear, which empties the whole cache).
 */
class BookletCacheClear extends Command
{
    protected $signature = 'booklet:cache:clear';

    protected $description = 'Clear what’s safe to clear (kept pages, templates, cached settings); made again as needed';

    public function handle(): int
    {
        foreach (Upkeep::clearCaches() as $line) {
            $this->line('  <fg=green>✓</> '.$line);
        }
        $this->info('Done.');

        return self::SUCCESS;
    }
}
