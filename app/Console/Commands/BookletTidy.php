<?php

namespace App\Console\Commands;

use App\Support\Upkeep;
use Illuminate\Console\Command;

/**
 * php booklet tidy            (shows what would change)
 * php booklet tidy --apply    (changes it; each is kept in its history)
 *
 * Tidies how posts and pages are written: line endings, stray spaces,
 * invisible characters pasted in, runs of empty lines, heading steps and
 * titles. Code blocks are left exactly as they are.
 */
class BookletTidy extends Command
{
    protected $signature = 'booklet:tidy
        {--apply : Make the changes (without it, only show them)}
        {--only= : One chapter, like "posts" or "pages"}';

    protected $description = 'Tidy how posts and pages are written (spaces, empty lines, headings); shows first, --apply to change';

    public function handle(): int
    {
        $only = $this->option('only') ?: null;
        $changes = Upkeep::tidyPreview($only);
        if ($changes === []) {
            $this->info('Everything is tidy already.');

            return self::SUCCESS;
        }
        $this->table(['Chapter', 'Title', 'What changes'], array_map(fn ($c) => [
            $c['entry']->collectionHandle(),
            \Illuminate\Support\Str::limit($c['entry']->title() ?: 'Untitled', 50),
            implode(', ', array_map(fn ($r) => Upkeep::TIDY_RULES[$r], $c['rules'])),
        ], $changes));

        if (! $this->option('apply')) {
            $this->line(count($changes).' to tidy. Nothing has changed yet: run php booklet tidy --apply to tidy them.');

            return self::SUCCESS;
        }
        $done = Upkeep::tidyApply(null, $only);
        $this->info("Tidied {$done}. Each one’s earlier version is in its History, if you want any back.");

        return self::SUCCESS;
    }
}
