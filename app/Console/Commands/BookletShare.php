<?php

namespace App\Console\Commands;

use App\Support\Sharing;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * php booklet share harbour
 * php booklet share events --to=~/Desktop
 *
 * Packs a theme (themes/) or an insert (inserts/) into a .zip to share,
 * after checking it the same way Booklet checks one being added. The same
 * as Share it, in The cover or on the Inserts shelf.
 */
class BookletShare extends Command
{
    protected $signature = 'booklet:share {name : The theme’s or insert’s folder name, like "harbour"}
        {--to= : The folder to put the .zip in (default: storage/app/private/share)}';

    protected $description = 'Pack a theme or an insert into a .zip to share, checked as Booklet checks one being added';

    public function handle(): int
    {
        $to = (string) $this->option('to');
        $to = str_starts_with($to, '~/') ? rtrim((string) getenv('HOME'), '/').substr($to, 1) : $to;

        try {
            $packed = Sharing::pack(strtolower((string) $this->argument('name')), null, $to ?: null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Packed {$packed['name']} {$packed['version']} ({$packed['count']} files): {$packed['path']}");
        if ($packed['kind'] === 'theme') {
            $this->line('  '.($packed['code'] ? 'A theme with templates: adding it asks for a password.' : 'A cover pack: safe for anyone to add.'));
        }
        foreach ($packed['problems'] as $problem) {
            $this->warn('  '.$problem);
        }

        $repo = Sharing::repository($packed['key']);
        $owner = Sharing::githubOwner() ?? 'you';
        $this->newLine();
        $this->line('To let people add it by name, get updates, and find it:');
        $this->line("  1. Make a public GitHub repository called {$repo}, and put what’s in the .zip at its top.");
        $this->line('  2. Give the repository the topic '.Sharing::TOPICS[$packed['kind']].' (the gear next to About, on its page).');
        $this->line("  3. Make a release tagged {$packed['version']} for this version, and a new one for each version after.");
        $this->line("  People then add it with: {$owner}/{$repo} (in ".($packed['kind'] === 'theme' ? 'Settings › The cover › Add a theme' : 'Settings › Inserts › Add').').');
        $this->line('  The same steps, with buttons, are under “Share it” in '.($packed['kind'] === 'theme' ? 'The cover' : 'Settings › Inserts').'.');

        return self::SUCCESS;
    }
}
