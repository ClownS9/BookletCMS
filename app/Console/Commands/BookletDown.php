<?php

namespace App\Console\Commands;

use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet down "Moving to a new server, back by 6"
 *
 * Readers see a "back soon" page; everyone who signs in to the book keeps
 * working and sees the site as it is. `php booklet up` lets readers back.
 */
class BookletDown extends Command
{
    protected $signature = 'booklet:down
        {message? : A line for readers, like "Back by six"}
        {--for= : Minutes until readers are let back in by themselves}';

    protected $description = 'Show readers a “back soon” page while the book keeps working (php booklet up to undo)';

    public function handle(): int
    {
        $minutes = $this->option('for') !== null ? max(1, (int) $this->option('for')) : null;
        $note = Upkeep::sendReadersAway((string) $this->argument('message'), null, $minutes);
        $this->info('Readers now see “back soon”.'.($note['until'] ? ' They’re let back in at '.\Illuminate\Support\Carbon::parse($note['until'])->format('H:i').'.' : ' Run php booklet up to let them back in.'));
        $this->line('Everyone who signs in to the book can still use it and see the site.');

        return self::SUCCESS;
    }
}
