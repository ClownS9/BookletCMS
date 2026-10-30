<?php

namespace App\Console\Commands;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet reset
 * php booklet reset --only=content,media
 * php booklet reset --everything
 *
 * Start again: clear the parts you choose, or everything (the next visit goes
 * to First setup, as a new install does). A backup is always made first, and
 * you type the site's name to go ahead. Theme and insert files, and backups,
 * are never touched. The same is in Settings › Upkeep.
 */
class BookletReset extends Command
{
    protected $signature = 'booklet:reset
        {--only= : What to clear, comma separated: content, media, people, readers, settings, connections, records}
        {--everything : Empty the whole book, everyone included, ready for First setup}
        {--keep= : With people: the email of the admin who stays (asked if not given)}
        {--force : Don’t ask (for scripts); a backup is still made first}';

    protected $description = 'Start again: clear what you choose, or everything (backs up first)';

    public function handle(): int
    {
        $name = (string) (rescue(fn () => Booklet::globals('site')?->get('site_name'), null, false) ?: 'booklet');
        $everything = (bool) $this->option('everything');
        $interactive = $this->input->isInteractive() && ! $this->option('force');

        // What to clear.
        if ($everything) {
            $parts = array_keys(Upkeep::PARTS);
        } elseif ($this->option('only') !== null) {
            $asked = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
            $unknown = array_diff($asked, [...array_keys(Upkeep::PARTS), 'everything']);
            if ($unknown !== []) {
                $this->error('Not something reset knows: '.implode(', ', $unknown).'. Choose from '.implode(', ', array_keys(Upkeep::PARTS)).'.');

                return self::FAILURE;
            }
            $everything = in_array('everything', $asked, true);
            $parts = Upkeep::resetParts($asked);
        } elseif ($interactive) {
            $choices = array_map(fn ($p) => $p['label'], Upkeep::PARTS) + ['everything' => 'Everything, like a new install'];
            $parts = (array) $this->choice('What should go? (comma separated)', $choices, 'content', null, true);
            $everything = in_array('everything', $parts, true);
            $parts = Upkeep::resetParts($parts);
        } else {
            $parts = ['content']; // as before: the writing, with the starter book put back
        }

        if ($parts === []) {
            $this->error('Choose something to clear.');

            return self::FAILURE;
        }

        // Whose account stays.
        $keep = null;
        if (in_array('people', $parts, true) && ! $everything) {
            $admins = User::query()->where('role', 'admin')->orderBy('id')->get();
            if ($this->option('keep')) {
                $keep = $admins->firstWhere('email', mb_strtolower(trim((string) $this->option('keep'))))
                    ?? $admins->first(fn ($u) => mb_strtolower($u->email) === mb_strtolower(trim((string) $this->option('keep'))));
                if (! $keep) {
                    $this->error('No admin with that email. Whoever stays must be an admin.');

                    return self::FAILURE;
                }
            } elseif ($admins->count() === 1 || ! $interactive) {
                $keep = $admins->first();
            } elseif ($admins->isNotEmpty()) {
                $email = $this->choice('Whose account stays?', $admins->pluck('email', 'email')->all(), $admins->first()->email);
                $keep = $admins->firstWhere('email', $email);
            }
            if (! $keep) {
                $this->error('There’s no admin to keep. Use --everything to empty the book for First setup.');

                return self::FAILURE;
            }
        }

        // Say what happens.
        $this->newLine();
        if ($everything) {
            $this->warn('  Everything goes: every post and page, media, everyone (you too), settings, connected services and records.');
            $this->warn('  The next visit goes to First setup, as a new install does.');
        } else {
            $this->line('  <options=bold>Goes:</>');
            foreach ($parts as $part) {
                $this->line('   • '.Upkeep::PARTS[$part]['label'].' <fg=gray>— '.Upkeep::PARTS[$part]['about'].'</>');
            }
            if ($keep) {
                $this->line('  <options=bold>Stays:</> '.$keep->name.' ('.$keep->email.')');
            }
        }
        $lost = array_filter($parts, fn ($p) => ! Upkeep::PARTS[$p]['backup']);
        if ($lost !== []) {
            $this->warn('  Not in backups, so gone for good: '.implode(', ', array_map(fn ($p) => mb_strtolower(Upkeep::PARTS[$p]['label']), $lost)).'.');
        }
        $this->line('  A backup is made first. Theme and insert files, and backups, stay.');
        $this->newLine();

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

        $done = Upkeep::reset($parts, $keep instanceof User ? $keep : null, $everything);
        $this->info('Started again. The book as it was is backed up as '.$done['backup'].' (php booklet restore '.$done['backup'].' brings it back).');
        if ($everything) {
            $this->line('Open the site to go through First setup.');
        }

        return self::SUCCESS;
    }
}
