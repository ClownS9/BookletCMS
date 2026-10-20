<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletImportWordpress extends Command
{
    protected $signature = 'booklet:import-wordpress {file : The WordPress export (.xml)} {--as= : Email of the admin it is brought in as} {--no-pictures : Leave the pictures on the old site} {--no-drafts} {--no-comments} {--no-redirects} {--authors=match : match, create or me}';

    protected $description = 'Bring a WordPress export (Tools › Export) into the book: posts, pages, pictures, comments, redirects';

    public function handle()
    {
        $by = $this->option('as')
            ? \App\Models\User::where('email', mb_strtolower((string) $this->option('as')))->first()
            : \App\Models\User::where('role', 'admin')->orderBy('id')->first();
        if (! $by) {
            $this->error('No admin to bring it in as. Use --as=you@example.com.');

            return 1;
        }
        $name = \App\Support\WordPressImport::keepUpload((string) $this->argument('file'));
        $summary = \App\Support\WordPressImport::summary($name);
        $this->line('“'.($summary['site']['title'] ?: $summary['site']['base']).'”: '.$summary['posts'].' posts, '.$summary['pages'].' pages, '.$summary['drafts'].' drafts, '.$summary['pictures'].' pictures, '.$summary['comments'].' comments.');

        $result = \App\Support\WordPressImport::run($name, $by, [
            'pictures' => ! $this->option('no-pictures'),
            'drafts' => ! $this->option('no-drafts'),
            'comments' => ! $this->option('no-comments'),
            'redirects' => ! $this->option('no-redirects'),
            'authors' => in_array($this->option('authors'), ['match', 'create', 'me'], true) ? $this->option('authors') : 'match',
        ]);
        \App\Support\WordPressImport::forget($name);

        foreach ($result['report'] as $what => $n) {
            if ($n) {
                $this->line(str_pad(ucfirst($what), 12).$n);
            }
        }
        foreach ($result['notes'] as $note) {
            $this->line(' · '.$note);
        }
        $this->info('Moved in.');
    }
}
