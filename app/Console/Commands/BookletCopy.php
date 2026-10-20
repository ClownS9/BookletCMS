<?php

namespace App\Console\Commands;

use App\Booklet\Facades\Booklet;
use App\Booklet\Schema\Definitions;
use Illuminate\Console\Command;

/**
 * Shared by booklet:export and booklet:import.
 */
class BookletCopy
{
    public static function run(Command $command, string $from, string $to): int
    {
        $groups = $command->option('only') ?: Definitions::groups();
        $unknown = array_diff($groups, Definitions::groups());

        if ($unknown !== []) {
            $command->error('Unknown group(s): '.implode(', ', $unknown).'. Choose from: '.implode(', ', Definitions::groups()).'.');

            return Command::FAILURE;
        }

        $label = $to === 'file' ? 'files' : 'the database';
        $fresh = (bool) $command->option('fresh');

        if (! $command->option('force') && ! $command->confirm(
            "Copy ".implode(', ', $groups)." from {$from} to {$label}".($fresh ? ' (emptying the target first)' : '').'?',
            true,
        )) {
            return Command::SUCCESS;
        }

        $counts = Booklet::copy($groups, $from, $to, $fresh);

        $command->table(['Repository', 'Items copied'], collect($counts)->map(fn ($n, $name) => [$name, $n])->values()->all());

        $command->newLine();
        $command->line("Done. To read from {$label} now, set these in .env:");
        foreach ($groups as $group) {
            $command->line('  BOOKLET_'.strtoupper($group)."_DRIVER={$to}");
        }

        return Command::SUCCESS;
    }
}
