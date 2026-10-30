<?php

namespace App\Console\Commands;

use App\Support\Updates;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use ZipArchive;

/**
 * php booklet package
 * php booklet package --to=~/Desktop
 *
 * The download people install from: the whole of Booklet in one .zip, the
 * vendor folder included (shared hosting has no terminal to run Composer),
 * without anything of this site's own (.env, the database, uploads, content,
 * logs, tests). Attach it to a GitHub release (tagged with the version) and
 * every Booklet finds it in Settings › Updates.
 *
 * Run `composer install --no-dev --optimize-autoloader` first, so the
 * vendor folder has only what Booklet needs to run.
 */
class BookletPackage extends Command
{
    protected $signature = 'booklet:package
        {--to= : The folder to put the .zip in (default: storage/app/private/packages)}
        {--with-dev : Allow development packages in vendor (bigger; for testing only)}';

    protected $description = 'Pack Booklet into the .zip people install and update from';

    /** What goes in: Booklet's own files, and the empty folders it needs. */
    public const INCLUDE = [
        'app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'database/migrations', 'database/seeders', 'database/factories',
        'lang', 'public/booklet', 'public/index.php', 'public/.htaccess', 'public/favicon.ico',
        'resources/views', 'resources/css', 'resources/js', 'resources/fonts', 'resources/install', 'resources/blueprints', 'resources/fieldsets',
        'routes', 'themes', 'inserts/README.md', 'database/.gitignore', 'vendor', 'artisan', 'booklet', 'composer.json', 'composer.lock', '.env.example', '.htaccess', 'README.md', 'LICENSE',
    ];

    /** Folders made empty (with a .gitignore so they survive being unpacked anywhere). */
    public const EMPTY = ['bootstrap/cache', 'storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs'];

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('PHP’s zip extension is missing.');

            return self::FAILURE;
        }
        $base = base_path();
        if (! is_file($base.'/vendor/autoload.php')) {
            $this->error('There’s no vendor folder: run composer install --no-dev --optimize-autoloader first.');

            return self::FAILURE;
        }
        if (is_dir($base.'/vendor/phpunit') && ! $this->option('with-dev')) {
            $this->error('vendor has development packages in it (phpunit…). Run composer install --no-dev --optimize-autoloader first, or add --with-dev.');

            return self::FAILURE;
        }

        $version = Updates::current();
        $to = (string) $this->option('to');
        $to = str_starts_with($to, '~/') ? rtrim((string) getenv('HOME'), '/').substr($to, 1) : ($to ?: storage_path('app/private/packages'));
        @mkdir($to, 0775, true);
        $path = rtrim($to, '/').'/booklet-'.$version.'.zip';
        @unlink($path);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Couldn’t make '.$path);

            return self::FAILURE;
        }

        $count = 0;
        foreach (self::INCLUDE as $item) {
            $full = $base.'/'.$item;
            if (is_file($full)) {
                $zip->addFile($full, 'booklet/'.$item);
                $count++;
            } elseif (is_dir($full)) {
                $finder = (new Finder)->files()->in($full)->ignoreDotFiles(false)->ignoreVCS(true)
                    ->notName(['.DS_Store', 'Thumbs.db', '.phpunit.result.cache'])
                    ->exclude($item === 'vendor' ? [] : ['node_modules']);
                foreach ($finder as $file) {
                    $relative = $item.'/'.str_replace('\\', '/', $file->getRelativePathname());
                    $zip->addFile($file->getPathname(), 'booklet/'.$relative);
                    $count++;
                }
            }
        }
        foreach (['artisan', 'booklet'] as $runnable) {
            if (is_file($base.'/'.$runnable)) {
                $zip->setExternalAttributesName('booklet/'.$runnable, ZipArchive::OPSYS_UNIX, 0755 << 16);
            }
        }
        foreach (self::EMPTY as $dir) {
            if ($zip->locateName('booklet/'.$dir.'/.gitignore') === false) {
                $zip->addFromString('booklet/'.$dir.'/.gitignore', "*\n!.gitignore\n");
            }
        }
        $zip->addFromString('booklet/booklet.json', (string) json_encode([
            'name' => 'Booklet',
            'version' => $version,
            'built_at' => now()->toIso8601String(),
            'php' => '>='.\App\Install\WebInstaller::PHP,
            'owned' => Updates::OWNED,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        $size = round(filesize($path) / 1048576, 1);
        $this->info("Packed Booklet {$version} ({$count} files, {$size} MB): {$path}");
        $this->line('  Attach it to a GitHub release tagged v'.$version.' on '.config('booklet.updates.repo').', with what changed in the release notes.');
        $this->line('  sha256 '.hash_file('sha256', $path));

        return self::SUCCESS;
    }
}
