<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletInsertUpdate extends Command
{
    protected $signature = 'booklet:insert-update {name? : One insert (all of them if left out)} {--check : Only say which have updates}';

    protected $description = 'Look for newer versions of inserts added from a link, and bring them in';

    public function handle()
    {
        $installer = app(\App\Inserts\InsertInstaller::class);
        $ready = $installer->check($this->argument('name') ?: null);
        if ($ready === []) {
            $this->info('Everything added from a link is up to date.');

            return 0;
        }
        foreach ($ready as $key => $version) {
            $this->line("{$key}: version {$version} is ready.");
        }
        if ($this->option('check')) {
            return 0;
        }
        foreach ($ready as $key => $version) {
            try {
                $insert = app(\App\Inserts\InsertManager::class)->find($key);
                $done = $installer->install($installer->stageFrom($insert->source()));
                $this->info("Updated {$done['name']} from {$done['from']} to {$done['version']}.");
            } catch (\RuntimeException $e) {
                $this->error("{$key}: ".$e->getMessage());
            }
        }
    }
}
