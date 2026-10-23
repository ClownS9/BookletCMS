<?php

namespace App\Console\Commands;

use App\Support\StaticSite;
use Illuminate\Console\Command;

class BookletPublishStatic extends Command
{
    protected $signature = 'booklet:publish-static
        {--when-due : Only when something changed and things have been quiet for a couple of minutes (what the scheduler runs)}
        {--send : Send the files to GitHub Pages afterwards, even if that is off}
        {--address= : Where the files will live (otherwise the address set in Settings)}';

    protected $description = 'Publish the public site as plain files (for GitHub Pages, Netlify or any host)';

    public function handle(): int
    {
        if ($this->option('when-due') && ! StaticSite::due()) {
            return self::SUCCESS;
        }

        try {
            $made = StaticSite::build($this->option('address') ?: null);
            $this->info("Made {$made['pages']} pages and {$made['files']} files (".number_format($made['bytes'] / 1048576, 1)." MB) for {$made['address']}.");
            foreach ($made['skipped'] as $path) {
                $this->warn("Left out {$path}: it didn’t open.");
            }
            if ($this->option('send') || StaticSite::settings()['github']) {
                StaticSite::sendToGithub();
                $this->info('Sent to GitHub Pages (the gh-pages branch).');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
