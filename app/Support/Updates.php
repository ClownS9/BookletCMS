<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * New versions of Booklet: knowing one is out (and what changed), and
 * updating from the book, safely.
 *
 * Releases come from GitHub (config booklet.updates.repo), each with a
 * booklet-x.y.zip attached: the whole of Booklet, vendor folder included, as
 * `php booklet package` makes it.
 *
 * Updating, step by step (each its own request, so slow hosting doesn't time out):
 *   1. backup     a backup of the book, as Settings › Backups makes one
 *   2. download   the release's .zip, checked against GitHub's fingerprint of it
 *   3. check      it's Booklet, it's the version promised, it's complete; unpacked aside
 *   4. apply      Booklet's own files are swapped for the new ones (the old ones kept aside)
 *   5. finish     the database is brought up to date and caches emptied
 * Anything failing before 5 leaves the site exactly as it was. Afterwards,
 * "Go back" undoes the database changes and puts the old files back.
 *
 * Never touched: .env, storage/ (uploads, backups), content/, the database
 * file, themes and inserts you added, public/storage.
 */
final class Updates
{
    public const CACHE = 'booklet.updates.latest';

    public const MAX_ZIP = 120 * 1024 * 1024;

    /**
     * What belongs to Booklet itself: these are swapped whole on update.
     * Everything else in the folder is yours and is left alone.
     */
    public const OWNED = [
        'app', 'config', 'lang', 'routes', 'vendor',
        'database/migrations', 'database/seeders', 'database/factories',
        'resources/views', 'resources/css', 'resources/js', 'resources/fonts', 'resources/install',
        // Not the .htaccess files: hosting panels write their own lines into them (the PHP version, say).
        'public/booklet', 'public/index.php',
        'bootstrap/app.php', 'bootstrap/providers.php',
        'themes/harbour', 'themes/README.md', 'inserts/README.md',
        'artisan', 'booklet', 'composer.json', 'composer.lock', '.env.example', 'README.md',
    ];

    /** Must be in a release, or it isn't a whole Booklet. */
    public const ESSENTIAL = ['app', 'vendor/autoload.php', 'public/index.php', 'artisan', 'bootstrap/app.php', 'booklet.json'];

    /** Where Booklet's files are (tests point it at a folder of their own). */
    public static ?string $root = null;

    public static function root(): string
    {
        return rtrim(self::$root ?? base_path(), '/');
    }

    /** Booklet's working folder for updates: downloads, the unpacked release, the old files. */
    public static function dir(string $sub = ''): string
    {
        return rtrim((string) config('booklet.paths.private', storage_path('app/private')), '/').'/updates'.($sub !== '' ? '/'.$sub : '');
    }

    public static function current(): string
    {
        return (string) config('booklet.version', '1.0');
    }

    // Is there a new one? ------------------------------------------------------------------

    /** What the last check found (even if it isn't newer). */
    public static function latest(): ?array
    {
        $found = Cache::get(self::CACHE);

        return is_array($found) ? $found : null;
    }

    /** A newer version, when there is one. */
    public static function available(): ?array
    {
        $latest = self::latest();

        return $latest && ! empty($latest['version']) && version_compare((string) $latest['version'], self::current(), '>') ? $latest : null;
    }

    /** Ask GitHub for the newest release. Throws when it can't be asked. */
    public static function check(): ?array
    {
        $repo = (string) config('booklet.updates.repo', '');
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            return null;
        }
        $headers = ['User-Agent' => 'Booklet', 'Accept' => 'application/vnd.github+json'];
        if ($token = config('booklet.github_token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        try {
            $response = Http::withHeaders($headers)->timeout(15)->connectTimeout(8)->get('https://api.github.com/repos/'.$repo.'/releases/latest');
        } catch (Throwable) {
            throw new RuntimeException(__('GitHub didn’t answer. Try again in a minute.'));
        }
        if ($response->status() === 404) {
            Cache::forever(self::CACHE, ['version' => null, 'checked_at' => now()->toIso8601String()]);

            return null;
        }
        if ($response->status() === 403 || $response->status() === 429) {
            throw new RuntimeException(__('GitHub is asking Booklet to slow down. Try again in an hour.'));
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('GitHub said :status.', ['status' => $response->status()]));
        }

        $release = (array) $response->json();
        $asset = collect((array) ($release['assets'] ?? []))
            ->first(fn ($a) => is_array($a) && preg_match('/^booklet[-_ ]?v?[\d.]+.*\.zip$/i', (string) ($a['name'] ?? '')));
        $digest = (string) ($asset['digest'] ?? '');

        $found = [
            'version' => ltrim((string) ($release['tag_name'] ?? ''), 'vV') ?: null,
            'name' => (string) ($release['name'] ?? ''),
            'notes' => Str::limit((string) ($release['body'] ?? ''), 20000, ''),
            'page' => (string) ($release['html_url'] ?? ''),
            'published_at' => (string) ($release['published_at'] ?? ''),
            'zip' => $asset ? (string) ($asset['browser_download_url'] ?? '') : null,
            'size' => $asset ? (int) ($asset['size'] ?? 0) : null,
            'sha256' => str_starts_with($digest, 'sha256:') ? strtolower(substr($digest, 7)) : null,
            'checked_at' => now()->toIso8601String(),
        ];
        Cache::forever(self::CACHE, $found);

        return self::available();
    }

    /** Check again if the last check was a day ago (quietly; problems are only logged). */
    public static function checkIfDue(): void
    {
        if (! config('booklet.updates.check', true)) {
            return;
        }
        $checked = self::latest()['checked_at'] ?? null;
        if ($checked && now()->diffInHours(\Illuminate\Support\Carbon::parse($checked)) < 24) {
            return;
        }
        if (! Cache::add('booklet.updates.checking', true, 600)) {
            return; // someone else is asking right now
        }
        // Not reaching GitHub now and then is normal: it's tried again tomorrow, and the page says when it last looked.
        rescue(fn () => self::check(), null, false);
    }

    /** What changed, in a line: the notes' points joined up, headings left out. */
    public static function summary(?array $release, int $length = 140): string
    {
        $points = collect(preg_split('/\R/', (string) ($release['notes'] ?? '')) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->reject(fn ($line) => $line === '' || str_starts_with($line, '#') || preg_match('/^[-=*_]{3,}$/', $line))
            ->map(fn ($line) => trim(strip_tags((string) Str::inlineMarkdown(preg_replace('/^([-*+]|\d+[.)])\s+/', '', $line) ?? '', ['html_input' => 'strip']))))
            ->filter();

        return Str::limit(html_entity_decode($points->implode(' · '), ENT_QUOTES), $length);
    }

    /** What changed, as safe HTML (the release's notes are Markdown). */
    public static function notesHtml(?array $release): string
    {
        $notes = trim((string) ($release['notes'] ?? ''));

        return $notes === '' ? '' : (string) Str::markdown($notes, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    // Can it be done here? ------------------------------------------------------------------

    /** Why updating from the book can't work on this server (null: it can). */
    public static function blocked(?array $release = null): ?string
    {
        $root = self::root();
        if (is_dir($root.'/.git')) {
            return __('This copy of Booklet is kept with git, so update it with git (git pull, then php booklet migrate).');
        }
        if (! class_exists(ZipArchive::class)) {
            return __('This server can’t open .zip files (PHP’s zip extension is missing). Turn it on in your hosting panel.');
        }
        foreach (self::OWNED as $path) {
            $parent = dirname($root.'/'.$path);
            if (file_exists($root.'/'.$path) && ! is_writable($parent)) {
                return __('Booklet can’t change its own files (:path isn’t writable). Ask your host, or give the folder permission 755.', ['path' => trim(substr($parent, strlen($root)), '/') ?: '.']);
            }
        }
        if ($release && empty($release['zip'])) {
            return __('This release doesn’t have a ready-made Booklet .zip attached, so it can’t be installed from here. Download it from its page instead.');
        }
        $free = @disk_free_space($root);
        if ($release && $free !== false && $free < max(3 * (int) ($release['size'] ?? 0), 150 * 1024 * 1024)) {
            return __('There isn’t enough free space on the server for the update (it needs about :mb MB).', ['mb' => (int) ceil(max(3 * (int) ($release['size'] ?? 0), 150 * 1024 * 1024) / 1048576)]);
        }

        return null;
    }

    // Where an update is up to ----------------------------------------------------------------

    /**
     * {stage, version, backup, batch, from, at, by, ...} — the update under way,
     * and the one that can be undone.
     */
    public static function state(): array
    {
        $file = self::dir('state.json');

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }

    protected static function save(array $state): void
    {
        File::ensureDirectoryExists(self::dir());
        file_put_contents(self::dir('state.json'), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Throw away an update that didn't go ahead (download, unpacked files). */
    public static function cancel(): void
    {
        File::deleteDirectory(self::dir('staging'));
        foreach (glob(self::dir('*.zip')) ?: [] as $zip) {
            @unlink($zip);
        }
        $state = self::state();
        unset($state['stage'], $state['version'], $state['backup'], $state['token']);
        self::save($state);
    }

    // 1–3: getting ready (nothing of the site changes) ----------------------------------------

    /**
     * Do the next step of getting ready. Returns the stage reached:
     * backup → downloaded → checked (ready to apply).
     */
    public static function prepare(array $release, ?User $by = null): string
    {
        $version = (string) ($release['version'] ?? '');
        throw_if($version === '' || ! version_compare($version, self::current(), '>'), RuntimeException::class, __('There’s no newer version to update to.'));
        if ($why = self::blocked($release)) {
            throw new RuntimeException($why);
        }
        @set_time_limit(0);

        $state = self::state();
        if (($state['version'] ?? null) !== $version) {
            self::cancel();
            $state = self::state();
            $state['version'] = $version;
            $state['stage'] = null;
        }

        try {
            switch ($state['stage'] ?? null) {
                case null:
                    $made = Backups::create('before-update', $by);
                    $state['backup'] = $made['name'];
                    $state['stage'] = 'backup';
                    break;

                case 'backup':
                    $file = SafeFetch::download((string) $release['zip'], $why, self::MAX_ZIP);
                    if (! $file) {
                        throw new RuntimeException(__('The update couldn’t be downloaded: :why.', ['why' => $why]));
                    }
                    if (! empty($release['sha256']) && ! hash_equals((string) $release['sha256'], hash_file('sha256', $file))) {
                        @unlink($file);
                        throw new RuntimeException(__('The download didn’t match GitHub’s fingerprint of it, so it wasn’t used. Try again later.'));
                    }
                    File::ensureDirectoryExists(self::dir());
                    File::move($file, self::dir('booklet-'.$version.'.zip'));
                    $state['stage'] = 'downloaded';
                    break;

                case 'downloaded':
                    self::unpack(self::dir('booklet-'.$version.'.zip'), $version);
                    @unlink(self::dir('booklet-'.$version.'.zip'));
                    $state['stage'] = 'checked';
                    $state['token'] = Str::random(40);
                    break;
            }
        } catch (Throwable $e) {
            self::cancel();
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        self::save($state);

        return (string) $state['stage'];
    }

    /** Open the release, make sure it's a whole Booklet of the right version, and unpack it aside. */
    public static function unpack(string $zipFile, string $version): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('The download isn’t a .zip Booklet can open.'));
        }
        try {
            // Everything in one folder (booklet/…), or at the top.
            $tops = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($name === '' || str_starts_with($name, '__MACOSX/')) {
                    continue;
                }
                if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, ':')) {
                    throw new RuntimeException(__('The download has a file in it that points outside its folder, so it wasn’t used.'));
                }
                $tops[explode('/', $name)[0]] = true;
            }
            $prefix = $zip->locateName('booklet.json') !== false ? ''
                : (count($tops) === 1 && $zip->locateName(array_key_first($tops).'/booklet.json') !== false ? array_key_first($tops).'/' : null);
            if ($prefix === null) {
                throw new RuntimeException(__('The download isn’t a Booklet release (it has no booklet.json).'));
            }
            $manifest = (array) json_decode((string) $zip->getFromName($prefix.'booklet.json'), true);
            if (ltrim((string) ($manifest['version'] ?? ''), 'vV') !== $version) {
                throw new RuntimeException(__('The download says it’s version :says, not :version, so it wasn’t used.', ['says' => (string) ($manifest['version'] ?? '?'), 'version' => $version]));
            }
            foreach (self::ESSENTIAL as $path) {
                if ($zip->locateName($prefix.$path) === false && $zip->locateName($prefix.$path.'/') === false && ! self::hasFolder($zip, $prefix.$path.'/')) {
                    throw new RuntimeException(__('The download is missing :path, so it wasn’t used.', ['path' => $path]));
                }
            }

            $staging = self::dir('staging');
            File::deleteDirectory($staging);
            File::ensureDirectoryExists($staging);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                if ($relative === '' || str_starts_with($name, '__MACOSX/')) {
                    continue;
                }
                $target = $staging.'/'.$relative;
                if (str_ends_with($relative, '/')) {
                    File::ensureDirectoryExists($target);

                    continue;
                }
                File::ensureDirectoryExists(dirname($target));
                $stream = $zip->getStream($name);
                if ($stream === false || file_put_contents($target, $stream) === false) {
                    throw new RuntimeException(__('The download couldn’t be unpacked (is the server out of space?).'));
                }
                if ($relative === 'artisan' || $relative === 'booklet') {
                    @chmod($target, 0755);
                }
            }
        } finally {
            $zip->close();
        }
    }

    protected static function hasFolder(ZipArchive $zip, string $folder): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with(str_replace('\\', '/', (string) $zip->getNameIndex($i)), $folder)) {
                return true;
            }
        }

        return false;
    }

    // 4–5: the swap, and the database -----------------------------------------------------------

    /**
     * Swap Booklet's own files for the new ones. The old ones are kept (for
     * "Go back"). Returns the one-time code the finishing step needs.
     */
    public static function apply(?User $by = null): string
    {
        $state = self::state();
        throw_unless(($state['stage'] ?? null) === 'checked' && is_dir(self::dir('staging')), RuntimeException::class, __('The update isn’t ready yet. Start it again from Settings › Updates.'));

        $root = self::root();
        $staging = self::dir('staging');
        $previous = self::dir('previous');
        File::deleteDirectory($previous);
        File::ensureDirectoryExists($previous);

        $manifest = (array) json_decode((string) @file_get_contents($staging.'/booklet.json'), true);
        $owned = array_values(array_unique(array_merge(self::OWNED, array_filter((array) ($manifest['owned'] ?? []), fn ($p) => is_string($p) && self::safePath($p)))));

        $state['batch'] = Schema::hasTable('migrations') ? (int) DB::table('migrations')->max('batch') : 0;
        $state['from'] = self::current();

        self::down();
        $moved = [];
        try {
            foreach ($owned as $path) {
                if (file_exists($root.'/'.$path)) {
                    File::ensureDirectoryExists(dirname($previous.'/'.$path));
                    self::move($root.'/'.$path, $previous.'/'.$path);
                    $moved[] = [$previous.'/'.$path, $root.'/'.$path];
                }
                if (file_exists($staging.'/'.$path)) {
                    File::ensureDirectoryExists(dirname($root.'/'.$path));
                    self::move($staging.'/'.$path, $root.'/'.$path);
                    $moved[] = [$root.'/'.$path, $staging.'/'.$path];
                }
            }
            // New folders a release brings (and nothing of yours: only what isn't there yet).
            foreach (['storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
                File::ensureDirectoryExists($root.'/'.$dir);
            }
        } catch (Throwable $e) {
            // Put back exactly what was moved, newest first.
            foreach (array_reverse($moved) as [$now, $was]) {
                if (file_exists($now)) {
                    File::ensureDirectoryExists(dirname($was));
                    @rename($now, $was);
                }
            }
            self::up();
            self::cancel();
            throw new RuntimeException(__('Booklet couldn’t swap its files (:why). Nothing was changed.', ['why' => $e->getMessage()]));
        }

        $state['stage'] = 'applied';
        $state['owned'] = $owned;
        $state['by'] = $by?->id;
        self::save($state);
        File::deleteDirectory($staging);
        self::freshen();

        return (string) $state['token'];
    }

    /**
     * After the swap (running the new version): bring the database up to
     * date. If that fails, the old files go back.
     */
    public static function finish(string $token, ?User $by = null): string
    {
        $state = self::state();
        throw_unless(($state['stage'] ?? null) === 'applied' && hash_equals((string) ($state['token'] ?? ''), $token), RuntimeException::class, __('There’s no update to finish.'));
        @set_time_limit(0);

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);
            $why = $e->getMessage();
            try {
                self::undo($by, quiet: true);
            } catch (Throwable $undo) {
                report($undo);
                self::up();
                throw new RuntimeException(__('The update stopped while changing the database (:why), and going back didn’t work either. Restore the backup “:backup” from Settings › Backups.', ['why' => $why, 'backup' => $state['backup'] ?? '']));
            }
            throw new RuntimeException(__('The update stopped while changing the database (:why). Booklet went back to :version, and nothing of yours was lost.', ['why' => $why, 'version' => $state['from'] ?? '']));
        }

        self::clearCaches();
        self::up();

        $state['stage'] = null;
        $state['token'] = null;
        $state['undo'] = ['from' => $state['from'], 'to' => $state['version'], 'batch' => $state['batch'], 'backup' => $state['backup'] ?? null, 'at' => now()->toIso8601String(), 'by' => $by?->id, 'owned' => $state['owned'] ?? self::OWNED];
        $state['history'] = array_slice(array_merge([['from' => $state['from'], 'to' => $state['version'], 'at' => now()->toIso8601String(), 'by' => $by?->id]], (array) ($state['history'] ?? [])), 0, 20);
        $to = (string) $state['version'];
        unset($state['version'], $state['backup'], $state['batch'], $state['from'], $state['owned']);
        self::save($state);
        ActivityLog::record('updated Booklet to', 'settings', 'booklet', null, $to);

        return $to;
    }

    /** The update that can be gone back on: from, to, at… (null: none). */
    public static function undoable(): ?array
    {
        $undo = self::state()['undo'] ?? null;

        return is_array($undo) && is_dir(self::dir('previous')) ? $undo : null;
    }

    /**
     * Go back to the version before the last update: the database changes
     * it made are undone (while its files are still here to undo them), then
     * the old files go back.
     */
    public static function undo(?User $by = null, bool $quiet = false): string
    {
        $state = self::state();
        $undo = $quiet ? ['from' => $state['from'] ?? '', 'batch' => $state['batch'] ?? null, 'owned' => $state['owned'] ?? self::OWNED] : self::undoable();
        throw_unless($undo && is_dir(self::dir('previous')), RuntimeException::class, __('There’s nothing to go back to.'));
        @set_time_limit(0);

        // The new version's database changes, undone by the new version's own files.
        $batch = $undo['batch'] ?? null;
        if (is_int($batch) && Schema::hasTable('migrations')) {
            for ($i = 0; $i < 20 && (int) DB::table('migrations')->max('batch') > $batch; $i++) {
                Artisan::call('migrate:rollback', ['--force' => true]);
            }
        }

        $root = self::root();
        $previous = self::dir('previous');
        $leaving = self::dir('leaving');
        File::deleteDirectory($leaving);
        self::down();
        try {
            foreach ((array) ($undo['owned'] ?? self::OWNED) as $path) {
                if (! is_string($path) || ! self::safePath($path)) {
                    continue;
                }
                if (file_exists($root.'/'.$path)) {
                    File::ensureDirectoryExists(dirname($leaving.'/'.$path));
                    self::move($root.'/'.$path, $leaving.'/'.$path);
                }
                if (file_exists($previous.'/'.$path)) {
                    File::ensureDirectoryExists(dirname($root.'/'.$path));
                    self::move($previous.'/'.$path, $root.'/'.$path);
                }
            }
        } finally {
            self::freshen();
            self::clearCaches();
            self::up();
        }
        File::deleteDirectory($leaving);
        File::deleteDirectory($previous);

        $back = (string) ($undo['from'] ?? '');
        unset($state['undo'], $state['stage'], $state['token'], $state['version'], $state['batch'], $state['from'], $state['owned'], $state['backup']);
        if (! $quiet) {
            $state['history'] = array_slice(array_merge([['from' => $undo['to'] ?? '', 'to' => $back, 'at' => now()->toIso8601String(), 'by' => $by?->id, 'back' => true]], (array) ($state['history'] ?? [])), 0, 20);
            ActivityLog::record('went back to Booklet', 'settings', 'booklet', null, $back);
        }
        self::save($state);

        return $back;
    }

    // Helpers ------------------------------------------------------------------------------------

    protected static function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! preg_match('#(^|/)\.\.?(/|$)#', $path)
            && ! preg_match('#^(\.env|storage|content|database/[^/]+\.sqlite|public/storage)(/|$)#', $path)
            && basename($path) !== '.htaccess';
    }

    protected static function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RuntimeException('couldn’t move '.basename($from));
        }
    }

    /** Readers see "back in a moment" while files are swapped (the update's own pages still work). */
    protected static function down(): void
    {
        if (self::$root === null) {
            rescue(fn () => Artisan::call('down', ['--retry' => 30]), null, false);
        }
    }

    protected static function up(): void
    {
        if (self::$root === null) {
            rescue(fn () => Artisan::call('up'), null, false);
        }
    }

    protected static function clearCaches(): void
    {
        if (self::$root === null) {
            foreach (['view:clear', 'config:clear', 'route:clear', 'event:clear'] as $command) {
                rescue(fn () => Artisan::call($command), null, false);
            }
        }
        Cache::forget(self::CACHE.'.notice');
    }

    /** PHP keeps compiled copies of files: forget them, so the new files are read. */
    protected static function freshen(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }
}
