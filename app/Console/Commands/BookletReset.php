<?php

namespace App\Console\Commands;

use App\Booklet\Facades\Booklet;
use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet reset
 *
 * Start the book again from the starter book. A backup is made first, and
 * you type the site's name to go ahead. Posts, pages, their history, menus,
 * categories and tags, comments, notes and bookmarks go; people, settings,
 * themes, inserts and media stay. Only from the terminal, on purpose.
 */
class BookletReset extends Command
{
    protected $signature = 'booklet:reset
        {--force : Don’t ask (for scripts); a backup is still made first}';

    protected $description = 'Start again from the starter book (backs up first; people, settings and media stay)';

    public function handle(): int
    {
        $name = (string) (rescue(fn () => Booklet::globals('site')?->get('site_name'), null, false) ?: 'booklet');
        $this->warn('This empties every post and page (and their history), the menus, categories and tags, comments, notes and bookmarks,');
        $this->warn('then puts the starter book back. People, settings, themes, inserts and media stay. A backup is made first.');

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Run it in a terminal to confirm, or add --force.');

                return self::FAILURE;
            }
            $typed = (string) $this->ask('Type the site’s name ('.$name.') to go ahead');
            if (mb_strtolower(trim($typed)) !== mb_strtolower(trim($name))) {
                $this->line('Nothing changed.');

                return self::FAILURE;
            }
        }

        $backup = Upkeep::reset();
        $this->info('Started again. The book as it was is backed up as '.$backup.' (php booklet restore '.$backup.' brings it back).');

        return self::SUCCESS;
    }
}
