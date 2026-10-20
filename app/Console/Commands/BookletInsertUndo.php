<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletInsertUndo extends Command
{
    protected $signature = 'booklet:insert-undo {name}';

    protected $description = 'Put back the copy of an insert that its last update replaced';

    public function handle()
    {
        try {
            $version = app(\App\Inserts\InsertInstaller::class)->undo((string) $this->argument('name'));
            $this->info("Back to version {$version}.");
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
