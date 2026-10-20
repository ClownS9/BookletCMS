<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletInsertInstall extends Command
{
    protected $signature = 'booklet:insert-install {from : A .zip file, a link to one, or a GitHub repository (someone/repo)} {--force : Don\'t ask first}';

    protected $description = 'Add an insert from a .zip, a link or GitHub (or update one that\'s already here)';

    public function handle()
    {
        $installer = app(\App\Inserts\InsertInstaller::class);
        if ($why = $installer->blocked()) {
            $this->error($why);

            return 1;
        }
        $from = (string) $this->argument('from');
        try {
            $token = is_file($from)
                ? $installer->stage($from, ['type' => 'upload', 'file' => basename($from)], basename($from))
                : $installer->stageFrom($installer->parseSource($from));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $look = $installer->staged($token);
        $this->line('“'.$look['name'].'” '.$look['version'].($look['author'] ? ' by '.$look['author'] : '').': '.$look['about']);
        if ($look['replaces']) {
            $this->line('Replaces version '.$look['replaces']['version'].' (kept, so it can be undone with booklet:insert-undo '.$look['key'].').');
        }
        if (! $this->option('force') && ! $this->confirm('An insert is code that runs on your site. Add it?', true)) {
            $installer->discard($token);

            return 0;
        }
        try {
            $done = $installer->install($token);
        } catch (\RuntimeException $e) {
            $installer->discard($token);
            $this->error($e->getMessage());

            return 1;
        }
        $this->info($done['updated'] ? "Updated {$done['name']} to {$done['version']}." : "Added {$done['name']}. Switch it on in Settings › Inserts.");
    }
}
