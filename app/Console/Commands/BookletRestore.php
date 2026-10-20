<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletRestore extends Command
{
    protected $signature = 'booklet:restore {file : A backup .zip (a path, or the name of one in storage/app/private/backups)} {--force : Don\'t ask first}';

    protected $description = 'Restore the book from a backup .zip';

    public function handle()
    {
        $file = (string) $this->argument('file');
        $path = is_file($file) ? $file : \App\Support\Backups::path($file);
        $manifest = \App\Support\Backups::manifest($path);
        $this->line('Backup of “'.($manifest['site'] ?? '?').'” made '.$manifest['created_at'].'.');
        if (! $this->option('force') && ! $this->confirm('Replace everything in this book with it? (A safety backup is made first.)')) {
            return;
        }
        $done = \App\Support\Backups::restore($path);
        $this->info('Restored. The book as it was is saved as '.$done['safety'].'.');
    }
}
