<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletImport extends Command
{
    protected $signature = 'booklet:import
                            {--only=* : Groups to import, e.g. --only=entries --only=terms (default: all)}
                            {--fresh : Empty those tables first}
                            {--force : Run without asking}';

    protected $description = 'Copy content from flat files into the database';

    public function handle(): int
    {
        return BookletCopy::run($this, 'file', 'database');
    }
}
