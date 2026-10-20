<?php

namespace App\Console\Commands;

use App\Support\VersionControl;
use Illuminate\Console\Command;

class BookletGitCommit extends Command
{
    protected $signature = 'booklet:git-commit
        {--when-due : Only when there are changes and things have been quiet for a couple of minutes (what the scheduler runs)}
        {--message= : The commit message (otherwise one is written from what people did)}
        {--push : Send commits to the remote afterwards, even if pushing is off}';

    protected $description = 'Commit the book’s content changes to git';

    public function handle(): int
    {
        if ($this->option('when-due') && ! VersionControl::due()) {
            return self::SUCCESS;
        }

        try {
            $hash = VersionControl::commit($this->option('message'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($hash ? "Committed {$hash}." : 'Nothing to commit.');
        if ($this->option('push') && ! VersionControl::settings()['push']) {
            $this->line(VersionControl::push() ? 'Sent to the remote.' : 'Couldn’t send: '.(VersionControl::status()['push_error'] ?? 'unknown problem'));
        }

        return self::SUCCESS;
    }
}
