<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * php booklet   (or php booklet list)
 *
 * Booklet's own commands, grouped, in their short form. Anything else
 * Laravel offers still works through php booklet (or php artisan).
 */
class BookletList extends Command
{
    protected $signature = 'booklet:list';

    protected $description = 'List Booklet’s commands';

    /** Where each command is listed. Any booklet: command not here goes under "More". */
    public const GROUPS = [
        'Upkeep' => ['doctor', 'cache:clear', 'optimize', 'down', 'up', 'reset'],
        'Content' => ['tidy', 'links', 'export', 'import', 'import-wordpress', 'git-commit', 'tidy-duplicates', 'builder-import'],
        'Pictures' => ['pictures', 'pictures:rebuild'],
        'Site' => ['publish-static', 'pages-clear', 'send-posts', 'weekly-letter'],
        'Backups' => ['backup', 'restore'],
        'Inserts & themes' => ['insert', 'insert-install', 'insert-update', 'insert-undo', 'make-theme', 'share', 'package'],
    ];

    public function handle(): int
    {
        $ours = collect(Artisan::all())
            ->filter(fn ($c, $name) => str_starts_with($name, 'booklet:') && $name !== 'booklet:list')
            ->mapWithKeys(fn ($c, $name) => [substr($name, 8) => $c->getDescription()]);

        $this->newLine();
        $this->line('  <options=bold>Booklet</> '.config('booklet.version', ''));
        $this->newLine();
        $this->line('  <fg=yellow>Usage:</> php booklet <command> [options]      <fg=gray>php booklet help <command> for more on one</>');

        $width = max(array_map('mb_strlen', $ours->keys()->all()) ?: [10]) + 4;
        $listed = [];
        $groups = self::GROUPS + ['More' => $ours->keys()->diff(collect(self::GROUPS)->flatten())->values()->all()];
        foreach ($groups as $group => $names) {
            $names = array_values(array_filter($names, fn ($n) => $ours->has($n)));
            if ($names === []) {
                continue;
            }
            $this->newLine();
            $this->line('  <fg=yellow>'.$group.'</>');
            foreach ($names as $name) {
                $listed[] = $name;
                $this->line('    <fg=green>'.str_pad($name, $width).'</>'.$ours[$name]);
            }
        }
        $this->newLine();
        $this->line('  <fg=gray>Laravel’s own commands work here too (php booklet migrate, php booklet schedule:run…).</>');
        $this->line('  <fg=gray>php artisan booklet:<command> still works, for scripts written before.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
