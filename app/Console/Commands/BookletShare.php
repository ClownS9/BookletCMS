<?php

namespace App\Console\Commands;

use App\Inserts\InsertInstaller;
use App\Inserts\InsertManager;
use App\Support\StaticSite;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * php artisan booklet:share harbour
 * php artisan booklet:share events --to=~/Desktop
 *
 * Packs a theme (themes/) or an insert (inserts/) into a .zip to share,
 * after checking it the same way Booklet checks one being added, so what
 * you send is what others can add. Then says how to put it on GitHub, so
 * people can add it by name, get updates, and (later) find it.
 */
class BookletShare extends Command
{
    protected $signature = 'booklet:share {name : The theme’s or insert’s folder name, like "harbour"}
        {--to= : The folder to put the .zip in (default: storage/app/private/share)}';

    protected $description = 'Pack a theme or an insert into a .zip to share, checked as Booklet checks one being added';

    /** The GitHub topics that mark a repository as one of these. */
    public const TOPICS = ['theme' => 'booklet-theme', 'insert' => 'booklet-insert'];

    public function handle(): int
    {
        $key = strtolower((string) $this->argument('name'));
        $theme = ThemeManager::find($key);
        $insert = $theme ? null : app(InsertManager::class)->find($key);
        if (! $theme && ! $insert) {
            $this->error("There's no theme or insert called {$key} (in themes/ or inserts/).");

            return self::FAILURE;
        }
        $kind = $theme ? 'theme' : 'insert';
        $folder = $theme ? $theme->path : $insert->path;
        $version = $theme ? $theme->version() : $insert->version();
        $name = $theme ? $theme->name() : $insert->name();

        $to = rtrim((string) ($this->option('to') ?: StaticSite::privatePath('share')), '/');
        $to = str_starts_with($to, '~/') ? rtrim((string) getenv('HOME'), '/').substr($to, 1) : $to;
        File::ensureDirectoryExists($to);
        $zipPath = $to.'/booklet-'.$key.'-'.preg_replace('/[^A-Za-z0-9.-]/', '', $version).'.zip';

        // Everything in the folder, at the top of the .zip (as a GitHub repository has it).
        @unlink($zipPath);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("The .zip couldn't be made at {$zipPath}.");

            return self::FAILURE;
        }
        $files = Finder::create()->files()->in($folder)->ignoreDotFiles(true)->ignoreVCS(true)->exclude(['node_modules']);
        $count = 0;
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
            $count++;
        }
        $zip->close();

        // Checked as if someone were adding it.
        try {
            if ($theme) {
                $installer = app(ThemeInstaller::class);
                $token = $installer->stage($zipPath, ['type' => 'upload'], basename($zipPath));
                $meta = $installer->staged($token);
                $installer->discard($token);
            } else {
                $installer = app(InsertInstaller::class);
                $token = $installer->stage($zipPath, ['type' => 'upload'], basename($zipPath));
                $meta = $installer->staged($token);
                $installer->discard($token);
            }
        } catch (RuntimeException $e) {
            @unlink($zipPath);
            $this->error('It wouldn’t be accepted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Packed {$name} {$version} ({$count} files): {$zipPath}");
        if ($kind === 'theme') {
            $this->line('  '.(! empty($meta['code']) ? 'A theme with templates: adding it asks for a password.' : 'A cover pack: safe for anyone to add.'));
        }
        foreach ((array) ($meta['problems'] ?? []) as $problem) {
            $this->warn('  '.$problem);
        }

        $this->newLine();
        $this->line('To let people add it by name, get updates, and find it:');
        $this->line('  1. Make a GitHub repository called booklet-'.$key.', and put what’s in the .zip at its top.');
        $this->line('  2. Give the repository the topic '.self::TOPICS[$kind].' (the gear next to About, on its page).');
        $this->line('  3. Make a release tagged '.$version.' for this version, and a new one for each version after.');
        $this->line('  People then add it with: you/booklet-'.$key.' (in '.($kind === 'theme' ? 'Settings › The cover › Add a theme' : 'Settings › Inserts › Add').').');

        return self::SUCCESS;
    }
}
