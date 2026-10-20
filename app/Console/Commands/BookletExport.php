<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletExport extends Command
{
    protected $signature = 'booklet:export
                            {--only=* : Groups to export, e.g. --only=entries --only=terms (default: all)}
                            {--fresh : Delete existing files of those groups first}
                            {--force : Run without asking}';

    protected $description = 'Copy content from the database into flat files (Statamic layout)';

    public function handle(): int
    {
        return BookletCopy::run($this, 'database', 'file');
    }
}
