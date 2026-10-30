<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The check-up: is everything the book needs from its server there and
 * working? (The scheduler running, mail going out, backups being made,
 * folders it can write to…) Each check says what it found in plain words,
 * and how to fix it when something's wrong.
 *
 * Shown in Settings › Check-up, by `php booklet doctor`, and to
 * Stacy. One check that can't run never stops the others.
 */
class Health
{
    public const GROUPS = [
        'server' => 'The server',
        'running' => 'Running on its own',
        'safe' => 'Keeping it safe',
        'extras' => 'Extras',
    ];

    /** The scheduler leaves this every minute (routes/console.php). */
    public const BEAT = 'booklet.scheduler.beat';

    /** Where the check-up's last result is kept, for the Settings card and Stacy's nudges. */
    public const LAST = 'booklet.health.last';

    /**
     * @return array<int, array{key: string, group: string, label: string, state: string, text: string, fix: ?string, command: ?string, href: ?string}>
     *                                                                                                                                                   state: ok · warn · fail · info
     */
    public static function run(?string $url = null): array
    {
        $checks = [];
        foreach ([
            'php', 'extensions', 'database', 'migrations', 'folders', 'storage_link', 'space', 'app_key', 'debug', 'address',
            'scheduler', 'queue', 'mail', 'log',
            'backups', 'version_control', 'content_files', 'site_menus',
            'inserts', 'theme', 'stacy', 'page_cache', 'search_engines',
        ] as $check) {
            try {
                $result = self::{Str::camel($check)}($url);
            } catch (Throwable $e) {
                report($e);
                $result = ['label' => Str::headline($check), 'group' => 'server', 'state' => 'warn', 'text' => __('This check couldn’t run: :error', ['error' => Str::limit($e->getMessage(), 160)])];
            }
            if ($result) {
                $checks[] = $result + ['key' => $check, 'fix' => null, 'command' => null, 'href' => null];
            }
        }

        rescue(fn () => Cache::put(self::LAST, [
            'at' => now()->toIso8601String(),
            'fail' => collect($checks)->where('state', 'fail')->pluck('label')->values()->all(),
            'warn' => collect($checks)->where('state', 'warn')->pluck('label')->values()->all(),
        ], now()->addDay()), null, false);

        return $checks;
    }

    /** The last check-up (made at most an hour ago, or now). */
    public static function summary(): array
    {
        $last = rescue(fn () => Cache::get(self::LAST), null, false);
        if (! is_array($last) || Carbon::parse($last['at'])->lt(now()->subHour())) {
            self::run();
            $last = (array) rescue(fn () => Cache::get(self::LAST), [], false);
        }

        return ['fail' => (array) ($last['fail'] ?? []), 'warn' => (array) ($last['warn'] ?? []), 'at' => $last['at'] ?? null];
    }

    // The server ---------------------------------------------------------------------------

    protected static function php(): array
    {
        $ok = version_compare(PHP_VERSION, '8.2.0', '>=');

        return ['group' => 'server', 'label' => __('PHP'), 'state' => $ok ? 'ok' : 'fail',
            'text' => $ok ? 'PHP '.PHP_VERSION.'.' : __('PHP :version, and Booklet needs 8.2 or newer.', ['version' => PHP_VERSION]),
            'fix' => $ok ? null : __('Ask your host to switch the site to PHP 8.3 (most control panels have a PHP version setting).')];
    }

    protected static function extensions(): array
    {
        $needed = ['mbstring' => 'text in every language', 'openssl' => 'passwords and secure links', 'pdo' => 'the database', 'fileinfo' => 'checking uploads', 'curl' => 'talking to other sites (Stacy, imports)'];
        $nice = ['zip' => 'backups and adding inserts', 'gd' => 'smaller copies of pictures', 'intl' => 'dates in other languages'];

        $missing = array_filter($needed, fn ($what, $ext) => ! extension_loaded($ext), ARRAY_FILTER_USE_BOTH);
        $without = array_filter($nice, fn ($what, $ext) => ! extension_loaded($ext), ARRAY_FILTER_USE_BOTH);
        if (extension_loaded('gd') && ! Pictures::available()) {
            $without['gd with WebP'] = 'smaller copies of pictures';
        }

        $list = fn (array $exts) => collect($exts)->map(fn ($what, $ext) => $ext.' ('.$what.')')->implode(', ');

        return match (true) {
            $missing !== [] => ['group' => 'server', 'label' => __('PHP extensions'), 'state' => 'fail', 'text' => __('Missing: :list.', ['list' => $list($missing)]),
                'fix' => __('Ask your host to turn these PHP extensions on.')],
            $without !== [] => ['group' => 'server', 'label' => __('PHP extensions'), 'state' => 'warn', 'text' => __('Everything needed is there. Without :list, those don’t work.', ['list' => $list($without)]),
                'fix' => __('Ask your host to turn on: :list.', ['list' => implode(', ', array_keys($without))])],
            default => ['group' => 'server', 'label' => __('PHP extensions'), 'state' => 'ok', 'text' => __('Everything Booklet uses is there.')],
        };
    }

    protected static function database(): array
    {
        $driver = DB::connection()->getDriverName();
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (Throwable $e) {
            return ['group' => 'server', 'label' => __('Database'), 'state' => 'fail', 'text' => 'Booklet can’t reach its database: '.Str::limit($e->getMessage(), 160),
                'fix' => __('Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.')];
        }
        $names = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite', 'sqlsrv' => 'SQL Server'];

        return ['group' => 'server', 'label' => __('Database'), 'state' => 'ok', 'text' => __('Connected (:name).', ['name' => $names[$driver] ?? $driver])];
    }

    protected static function migrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['group' => 'server', 'label' => __('Database tables'), 'state' => 'fail', 'text' => __('The database is empty.'), 'command' => 'php booklet migrate --force'];
        }
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $pending = array_diff($files, $migrator->getRepository()->getRan());

        return $pending === []
            ? ['group' => 'server', 'label' => __('Database tables'), 'state' => 'ok', 'text' => __('Up to date.')]
            : ['group' => 'server', 'label' => __('Database tables'), 'state' => 'fail',
                'text' => trans_choice(':count change to the database hasn’t been made yet (after an update, usually), so some pages may break.|:count changes to the database haven’t been made yet (after an update, usually), so some pages may break.', count($pending)),
                'command' => 'php booklet migrate --force'];
    }

    protected static function folders(): array
    {
        $dirs = ['storage/app' => storage_path('app'), 'storage/framework' => storage_path('framework'), 'storage/logs' => storage_path('logs'), 'bootstrap/cache' => base_path('bootstrap/cache')];
        $stuck = array_keys(array_filter($dirs, fn ($dir) => ! is_dir($dir) || ! is_writable($dir)));

        return $stuck === []
            ? ['group' => 'server', 'label' => __('Folders Booklet writes to'), 'state' => 'ok', 'text' => __('storage/ and bootstrap/cache are writable.')]
            : ['group' => 'server', 'label' => __('Folders Booklet writes to'), 'state' => 'fail', 'text' => 'Booklet can’t write to '.implode(', ', $stuck).'. Uploads, sessions and logs fail.',
                'fix' => __('Let the web server write to them (your host can do this), or on your own server:'), 'command' => 'chmod -R ug+rw storage bootstrap/cache'];
    }

    protected static function storageLink(): array
    {
        $link = public_path('storage');

        return is_dir($link) || is_link($link)
            ? ['group' => 'server', 'label' => __('Pictures folder'), 'state' => 'ok', 'text' => __('public/storage points to the uploads, so pictures show.')]
            : ['group' => 'server', 'label' => __('Pictures folder'), 'state' => 'fail', 'text' => __('public/storage is missing, so uploaded pictures don’t show on the site.'),
                'command' => 'php booklet storage:link'];
    }

    protected static function space(): ?array
    {
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return null;
        }
        $nice = $free >= 1073741824 ? number_format($free / 1073741824, 1).' GB' : number_format($free / 1048576).' MB';

        return match (true) {
            $free < 100 * 1048576 => ['group' => 'server', 'label' => __('Disk space'), 'state' => 'fail', 'text' => __('Only :size free. Uploads and backups will start failing.', ['size' => $nice]), 'fix' => __('Delete old backups in Settings › Backups, or ask your host for more space.'), 'href' => route('admin.backups')],
            $free < 1024 * 1048576 => ['group' => 'server', 'label' => __('Disk space'), 'state' => 'warn', 'text' => __(':size free. Each backup holds every picture, so space goes quickly.', ['size' => $nice]), 'href' => route('admin.backups')],
            default => ['group' => 'server', 'label' => __('Disk space'), 'state' => 'ok', 'text' => __(':size free.', ['size' => $nice])],
        };
    }

    protected static function appKey(): array
    {
        return config('app.key')
            ? ['group' => 'safe', 'label' => __('App key'), 'state' => 'ok', 'text' => __('Set. (Keep it: saved passwords for email and AI services can only be read with it.)')]
            : ['group' => 'safe', 'label' => __('App key'), 'state' => 'fail', 'text' => __('APP_KEY is empty, so sign-ins and saved passwords aren’t safe.'), 'command' => 'php booklet key:generate'];
    }

    protected static function debug(): array
    {
        $debug = (bool) config('app.debug');
        $live = app()->environment('production');

        return match (true) {
            $debug && $live => ['group' => 'safe', 'label' => __('Error details'), 'state' => 'fail', 'text' => __('APP_DEBUG is on, so when something breaks, readers see the site’s inner workings (and secrets).'), 'fix' => __('Set APP_DEBUG=false in .env.')],
            $debug => ['group' => 'safe', 'label' => __('Error details'), 'state' => 'warn', 'text' => __('APP_DEBUG is on (fine while building, but not for a site people visit).'), 'fix' => __('Before going live, set APP_ENV=production and APP_DEBUG=false in .env.')],
            default => ['group' => 'safe', 'label' => __('Error details'), 'state' => 'ok', 'text' => __('Hidden from readers.')],
        };
    }

    protected static function address(?string $url): array
    {
        $configured = rtrim((string) config('app.url'), '/');
        $host = parse_url($configured, PHP_URL_HOST) ?: '';
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local');

        if ($url && parse_url($url, PHP_URL_HOST) && strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) !== 0) {
            return ['group' => 'safe', 'label' => __('Site address'), 'state' => 'warn',
                'text' => __('APP_URL says :configured, but you’re visiting :visiting. Links in emails and feeds point to the first.', ['configured' => $configured, 'visiting' => parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST)]),
                'fix' => __('Set APP_URL in .env to the address people use.')];
        }
        if (str_starts_with($configured, 'http://') && ! $local) {
            return ['group' => 'safe', 'label' => __('Site address'), 'state' => 'warn', 'text' => __(':address isn’t https, so sign-ins travel unprotected.', ['address' => $configured]),
                'fix' => __('Turn on a free certificate (most hosts call it Let’s Encrypt or SSL), then make APP_URL start with https://.')];
        }

        return ['group' => 'safe', 'label' => __('Site address'), 'state' => 'ok', 'text' => $local ? __(':address (on this computer).', ['address' => $configured]) : $configured.'.'];
    }

    // Running on its own --------------------------------------------------------------------

    protected static function scheduler(): array
    {
        $beat = (int) rescue(fn () => Cache::get(self::BEAT, 0), 0, false);
        $line = '* * * * * cd '.base_path().' && php booklet schedule:run >> /dev/null 2>&1';
        $what = __('scheduled posts going out, emails to subscribers, nightly backups and update checks');

        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            return ['group' => 'running', 'label' => __('Scheduler'), 'state' => 'warn', 'text' => 'Can’t tell whether it runs: CACHE_STORE is “'.config('cache.default').'”, which forgets everything.', 'fix' => __('Use CACHE_STORE=database (or file) in .env.')];
        }
        if ($beat === 0) {
            return ['group' => 'running', 'label' => __('Scheduler'), 'state' => 'fail', 'text' => __('It hasn’t run yet, so these don’t happen: :what.', ['what' => $what]),
                'fix' => __('Add this to the server’s cron (your host’s control panel has a “Cron jobs” page):'), 'command' => $line];
        }
        $ago = Carbon::createFromTimestamp($beat);

        return $ago->lt(now()->subMinutes(10))
            ? ['group' => 'running', 'label' => __('Scheduler'), 'state' => 'fail', 'text' => 'It last ran '.$ago->diffForHumans().', so '.$what.' have stopped.',
                'fix' => __('Check the cron job is still there:'), 'command' => $line]
            : ['group' => 'running', 'label' => __('Scheduler'), 'state' => 'ok', 'text' => 'Running (last '.($ago->gt(now()->subMinute()) ? 'just now' : $ago->diffForHumans()).').'];
    }

    protected static function queue(): ?array
    {
        $driver = (string) config('queue.default');
        $failed = rescue(fn () => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, 0, false);
        if ($driver === 'sync') {
            return $failed ? ['group' => 'running', 'label' => __('Background jobs'), 'state' => 'warn', 'text' => trans_choice(':count failed job on record.|:count failed jobs on record.', $failed), 'command' => 'php booklet queue:failed'] : null;
        }
        if ($driver === 'database') {
            $stuck = rescue(fn () => DB::table('jobs')->where('available_at', '<', now()->subMinutes(10)->timestamp)->count(), 0, false);
            if ($stuck) {
                return ['group' => 'running', 'label' => __('Background jobs'), 'state' => 'fail', 'text' => trans_choice(':count job waiting for more than ten minutes: nothing is working through it.|:count jobs waiting for more than ten minutes: nothing is working through them.', $stuck),
                    'fix' => __('Run a queue worker (your host may call it a “daemon” or “background process”), or set QUEUE_CONNECTION=sync in .env.'), 'command' => 'php booklet queue:work --tries=3'];
            }
        }

        return $failed
            ? ['group' => 'running', 'label' => __('Background jobs'), 'state' => 'warn', 'text' => trans_choice(':count failed job on record.|:count failed jobs on record.', $failed), 'command' => 'php booklet queue:failed']
            : ['group' => 'running', 'label' => __('Background jobs'), 'state' => 'ok', 'text' => __('Nothing waiting.')];
    }

    protected static function mail(): array
    {
        $status = MailSettings::status();

        return [
            'group' => 'running', 'label' => __('Email'), 'href' => route('admin.settings.email'),
            'state' => ['on' => 'ok', 'env' => 'ok', 'off' => 'warn'][$status['state']] ?? 'warn',
            'text' => $status['text'],
            'fix' => $status['state'] === 'off' ? 'Set up your mail server in Settings › Email, so password resets and alerts arrive.' : null,
        ];
    }

    protected static function log(): ?array
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            return ['group' => 'running', 'label' => __('Problems logged'), 'state' => 'ok', 'text' => __('None.')];
        }

        // Only the end of the file: today's problems are there.
        $size = filesize($file);
        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - 512 * 1024));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        // Today's problems: when, and what (the first line, without the details).
        $today = now()->format('Y-m-d');
        preg_match_all('/^\[('.preg_quote($today, '/').' [0-9:]{8})[^\]]*\] \w+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $tail, $m, PREG_SET_ORDER);
        $problems = array_map(fn ($hit) => ['at' => rescue(fn () => Carbon::parse($hit[1]), now(), false), 'what' => Str::limit(trim(Str::before($hit[2], ' {"')), 180)], $m);
        $recent = array_values(array_filter($problems, fn ($p) => $p['at']->gt(now()->subHour())));
        $earlier = count($problems) - count($recent);
        $big = $size > 50 * 1048576;
        $tidy = $big ? ' The file is '.round($size / 1048576).' MB, so it’s worth emptying once read.' : '';

        // A page or class "not found" for a moment is what copying in new files looks like.
        $copying = fn (string $what) => (bool) preg_match('/^(Invalid route action|Class "[^"]+" not found|View \[[^\]]+\] not found|Target class \[)/', $what);

        if ($recent !== []) {
            $last = end($recent);

            return ['group' => 'running', 'label' => __('Problems logged'), 'state' => 'warn',
                'text' => trans_choice(':count problem in the last hour|:count problems in the last hour', count($recent))
                    .($earlier ? ' '.__('(:count earlier today)', ['count' => $earlier]) : '')
                    .'. '.__('The latest, at :time: :what', ['time' => $last['at']->translatedFormat('g:ia'), 'what' => $last['what']]),
                'fix' => ($copying($last['what']) ? __('If that was while new files were being copied in, it settles once they’re all there; check again in a few minutes.').' ' : '').__('storage/logs/laravel.log has the details.').$tidy];
        }
        if ($problems !== []) {
            $last = end($problems);

            return ['group' => 'running', 'label' => __('Problems logged'), 'state' => $copying($last['what']) ? 'ok' : 'info',
                'text' => __('None in the last hour. :count earlier today, the latest at :time: :what', ['count' => $earlier, 'time' => $last['at']->translatedFormat('g:ia'), 'what' => $last['what']])
                    .($copying($last['what']) ? ' '.__('(That’s what copying in new files looks like, and it has stopped.)') : ''),
                'fix' => __('storage/logs/laravel.log has the details.').$tidy];
        }

        return $big
            ? ['group' => 'running', 'label' => __('Problems logged'), 'state' => 'warn', 'text' => 'None today, but the log file is '.round($size / 1048576).' MB.', 'fix' => __('Empty storage/logs/laravel.log, or set LOG_STACK=daily in .env to start a new one each day.')]
            : ['group' => 'running', 'label' => __('Problems logged'), 'state' => 'ok', 'text' => __('None today.')];
    }

    // Keeping it safe ---------------------------------------------------------------------------

    protected static function backups(): array
    {
        $latest = Backups::list()->first();
        $nightly = (bool) rescue(fn () => app(\App\Booklet\Site::class)->setting('backups_nightly', true), true, false);
        $href = route('admin.backups');

        if (! Backups::available()) {
            return ['group' => 'safe', 'label' => __('Backups'), 'state' => 'fail', 'text' => __('Backups can’t be made: PHP’s zip extension is missing.'), 'fix' => __('Ask your host to turn on the zip extension.'), 'href' => $href];
        }
        if (! $latest) {
            return ['group' => 'safe', 'label' => __('Backups'), 'state' => 'warn', 'text' => __('No backups yet.').($nightly ? ' '.__('The first nightly one is made at 3:10am, if the scheduler runs.') : ''), 'fix' => __('Make one now in Settings › Backups.'), 'href' => $href];
        }
        $age = $latest['at'];
        $copy = ' Keep a copy somewhere other than this server, too.';

        return match (true) {
            $nightly && $age->lt(now()->subDays(2)) => ['group' => 'safe', 'label' => __('Backups'), 'state' => 'warn', 'text' => 'Nightly backups are on, but the newest is from '.$age->diffForHumans().'. The scheduler may not be running.', 'href' => $href],
            ! $nightly && $age->lt(now()->subDays(30)) => ['group' => 'safe', 'label' => __('Backups'), 'state' => 'warn', 'text' => 'The newest backup is from '.$age->diffForHumans().', and nightly backups are off.', 'fix' => __('Switch nightly backups on in Settings › Backups.'), 'href' => $href],
            default => ['group' => 'safe', 'label' => __('Backups'), 'state' => 'ok', 'text' => 'Newest '.$age->diffForHumans().($nightly ? ', and one is made every night.' : '.').$copy, 'href' => $href],
        };
    }

    /**
     * Every content file can be read? A broken one (a typo in its YAML, or
     * unfinished merge marks) is left out of the site, so say which.
     */
    protected static function contentFiles(): ?array
    {
        $folders = array_filter([
            config('booklet.paths.content'),
            resource_path('blueprints'), resource_path('fieldsets'), resource_path('forms'),
        ], fn ($f) => is_string($f) && is_dir($f));
        if ($folders === []) {
            return null;
        }

        $broken = [];
        $marked = false;
        foreach ($folders as $folder) {
            foreach (\Illuminate\Support\Facades\File::allFiles($folder) as $file) {
                if (! in_array($file->getExtension(), ['md', 'yaml', 'yml'], true)) {
                    continue;
                }
                $text = (string) $file->getContents();
                try {
                    $file->getExtension() === 'md' ? \App\Booklet\Support\FrontMatter::parse($text) : \Symfony\Component\Yaml\Yaml::parse($text);
                } catch (\Symfony\Component\Yaml\Exception\ParseException) {
                    $broken[] = str_replace(base_path().'/', '', $file->getPathname());
                    $marked = $marked || preg_match('/^(<{7}|>{7})( |$)/m', $text) === 1;
                }
            }
        }
        if ($broken === []) {
            return ['group' => 'running', 'label' => __('Content files'), 'state' => 'ok', 'text' => __('Every content file can be read.')];
        }

        return ['group' => 'running', 'label' => __('Content files'), 'state' => 'fail',
            'text' => trans_choice(':count file can’t be read, so it’s left out of the site: :files.|:count files can’t be read, so they’re left out of the site: :files.', count($broken), ['files' => Str::limit(implode(', ', $broken), 200)]),
            'fix' => $marked
                ? 'They have unfinished merge marks (lines starting <<<<<<< and >>>>>>>). Keep the lines you want and delete the marks.'
                : 'Open them and fix the YAML at the top (often a missing quote or a tab instead of spaces).'];
    }

    /** The menus readers see today: links to deleted or draft pages, addresses with nothing at them… */
    protected static function siteMenus(): ?array
    {
        $lines = [];
        foreach (array_keys(\App\Booklet\Support\Menus::all()) as $handle) {
            $showing = \App\Booklet\Support\Menus::activeSeason($handle) ?? $handle;
            $rows = \App\Booklet\Support\MenuOutline::fromTree(\App\Booklet\Support\Menus::items($showing));
            if (MenuHealth::warnings($rows, $handle) > 0) {
                $title = \App\Booklet\Support\Menus::allWithSeasons()[$showing] ?? $handle;
                $result = MenuHealth::check($rows, $handle);
                $first = collect($result['menu'])->where('level', 'warn')->pluck('text')
                    ->merge(collect($result['rows'])->map(fn ($p, $i) => collect($p)->where('level', 'warn')->map(fn ($x) => '“'.MenuHealth::label($rows[$i]).'”: '.$x['text']))->flatten())
                    ->first();
                $lines[] = $title.': '.$first;
            }
        }
        $href = route('admin.menus');
        if ($lines === []) {
            return ['group' => 'running', 'label' => __('Site menus'), 'state' => 'ok', 'text' => __('Every link in the menus goes somewhere readers can see.'), 'href' => $href];
        }

        return ['group' => 'running', 'label' => __('Site menus'), 'state' => 'warn', 'text' => Str::limit(implode(' ', $lines), 260), 'fix' => __('Open Site menus: rows marked “!” say what’s up.'), 'href' => $href];
    }

    protected static function versionControl(): ?array
    {
        if (! VersionControl::on()) {
            return null; // not in use: nothing to say
        }
        $href = route('admin.settings.version-control');
        $label = 'Version control';
        if (! VersionControl::contentInFiles()) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => __('It’s switched on, but the book’s content is kept in the database, so there are no files to keep a history of.'), 'href' => $href];
        }
        if (! VersionControl::installed()) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'fail', 'text' => __('Git isn’t installed on this server, so changes aren’t being committed.'), 'fix' => __('Install git, or set BOOKLET_GIT_BINARY to where it is.'), 'href' => $href];
        }
        if (! VersionControl::isRepo()) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'fail', 'text' => __('There’s no history to commit to yet.'), 'fix' => __('Start one in Settings › Version control.'), 'href' => $href];
        }
        $status = VersionControl::status();
        if (! empty($status['error'])) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'fail', 'text' => __('The last commit didn’t work: :error', ['error' => Str::limit((string) $status['error'], 200)]), 'href' => $href];
        }
        if (! empty($status['push_error']) && VersionControl::settings()['push']) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => __('Commits are made, but sending them on didn’t work: :error', ['error' => Str::limit((string) $status['push_error'], 200)]), 'href' => $href];
        }
        if (($private = VersionControl::privateTracked()) !== []) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => 'Git is keeping private files from before: '.Str::limit(implode(', ', $private), 160).'.', 'fix' => __('Take them out with one click in Settings › Version control.'), 'href' => $href];
        }
        $waiting = count(VersionControl::pending());
        $first = Cache::get(VersionControl::FIRST_SEEN);
        if ($waiting && $first && $first < now()->subHour()->timestamp) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => trans_choice(':count changed file has waited over an hour. The scheduler may not be running.|:count changed files have waited over an hour. The scheduler may not be running.', $waiting), 'href' => $href];
        }

        return ['group' => 'safe', 'label' => $label, 'state' => 'ok', 'text' => ! empty($status['at'])
            ? __('Every change is committed. The last commit was :when.', ['when' => Carbon::parse($status['at'])->diffForHumans()])
            : 'On. The next change will be the first commit.', 'href' => $href];
    }

    // Extras -------------------------------------------------------------------------------------

    protected static function inserts(): ?array
    {
        $manager = app(\App\Inserts\InsertManager::class);
        $href = route('admin.settings.inserts');
        if ($manager->safeMode()) {
            return ['group' => 'extras', 'label' => __('Inserts'), 'state' => 'warn', 'text' => __('Safe mode is on, so every insert is off.'), 'fix' => __('Remove BOOKLET_SAFE_MODE from .env once the trouble is sorted.'), 'href' => $href];
        }
        $all = $manager->all();
        if ($all->isEmpty()) {
            return null;
        }
        $broken = $all->filter(fn ($i) => $i->manifest('broken') || $i->tornOut())->map->name()->values();
        $recent = $all->filter(fn ($i) => $i->enabled() && $i->error() && $i->errorAt() && Carbon::parse($i->errorAt())->gt(now()->subDay()))->map->name()->values();
        $updates = $all->filter(fn ($i) => $i->updateReady() !== null)->map->name()->values();

        return match (true) {
            $broken->isNotEmpty() => ['group' => 'extras', 'label' => __('Inserts'), 'state' => 'warn', 'text' => 'Torn out (it broke, so it’s kept off): '.$broken->implode(', ').'.', 'href' => $href],
            $recent->isNotEmpty() => ['group' => 'extras', 'label' => __('Inserts'), 'state' => 'warn', 'text' => 'Had a problem in the last day: '.$recent->implode(', ').'.', 'href' => $href],
            $updates->isNotEmpty() => ['group' => 'extras', 'label' => __('Inserts'), 'state' => 'info', 'text' => 'Updates ready: '.$updates->implode(', ').'.', 'href' => $href],
            default => ['group' => 'extras', 'label' => __('Inserts'), 'state' => 'ok', 'text' => $manager->active()->count().' of '.$all->count().' switched on, all well.', 'href' => $href],
        };
    }

    /** The theme the site wears: there, fitting this Booklet, and its templates working. */
    protected static function theme(): ?array
    {
        $saved = Look::clean(rescue(fn () => (array) (\App\Booklet\Facades\Booklet::globals('site')?->get('look') ?? []), [], false));
        $wanted = (string) (rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('look')['theme'] ?? null, null, false) ?? 'booklet');
        $href = route('admin.settings.look');
        $theme = \App\Themes\ThemeManager::forLook($saved);

        if ($wanted !== 'booklet' && ! $theme) {
            return ['group' => 'extras', 'label' => __('Theme'), 'state' => 'warn', 'text' => 'The site was set to wear the theme “'.$wanted.'”, but it isn’t in themes/ (or needs a newer Booklet), so it wears Booklet’s own.', 'fix' => __('Put its folder back in themes/, or choose a cover in Settings › The cover.'), 'href' => $href];
        }
        if (! $theme) {
            return null;
        }
        $error = \App\Themes\ThemeManager::error($theme);
        if ($error && Carbon::parse($error['at'])->gt(now()->subDay())) {
            return ['group' => 'extras', 'label' => __('Theme'), 'state' => 'warn', 'text' => $theme->name().'’s template '.($error['file'] ? '('.$error['file'].') ' : '').'broke on '.$error['where'].': '.Str::limit($error['message'], 140).' Readers get Booklet’s own page there instead.', 'fix' => __('An update of the theme from whoever made it should fix it.'), 'href' => $href];
        }
        if ($theme->hasCode() && config('booklet.safe_mode')) {
            return ['group' => 'extras', 'label' => __('Theme'), 'state' => 'warn', 'text' => 'Safe mode is on, so '.$theme->name().' is worn as a cover pack: its styles, without its templates or scripts.', 'href' => $href];
        }

        return ['group' => 'extras', 'label' => __('Theme'), 'state' => 'ok', 'text' => $theme->name().' '.$theme->version().', '.($theme->hasCode() ? 'with its own templates' : 'a cover pack').', all well.', 'href' => $href];
    }

    protected static function stacy(): array
    {
        $ready = \App\Models\AiProvider::where('enabled', true)->get()->filter->isReady();
        $lost = \App\Models\AiProvider::where('enabled', true)->get()->filter(fn ($p) => $p->keyLost());

        return match (true) {
            $lost->isNotEmpty() => ['group' => 'extras', 'label' => __('Stacy'), 'state' => 'warn', 'text' => __('A saved AI key can’t be read since APP_KEY changed.'), 'fix' => __('Type the key again in Settings › Stacy’s brain.'), 'href' => route('admin.settings.stacy')],
            $ready->isEmpty() => ['group' => 'extras', 'label' => __('Stacy'), 'state' => 'info', 'text' => __('No AI service yet, so Stacy can’t answer.'), 'href' => route('admin.settings.stacy')],
            default => ['group' => 'extras', 'label' => __('Stacy'), 'state' => 'ok', 'text' => __('Thinking with :names.', ['names' => $ready->map->name()->implode(', ')]), 'href' => route('admin.settings.stacy')],
        };
    }

    protected static function searchEngines(): array
    {
        // An old robots.txt in public/ is served instead of the one Booklet writes (with the sitemap in it).
        if (is_file(public_path('robots.txt'))) {
            return ['group' => 'extras', 'label' => __('Search engines'), 'state' => 'warn',
                'text' => __('public/robots.txt is an old fixed file, so search engines aren’t told where the sitemap is.'),
                'fix' => __('Delete public/robots.txt; Booklet then writes one with the sitemap’s address.'), 'command' => 'rm public/robots.txt'];
        }

        return ['group' => 'extras', 'label' => __('Search engines'), 'state' => 'ok', 'text' => __('robots.txt points them to the sitemap, and every page says what it is (structured data).')];
    }

    protected static function pageCache(): array
    {
        $on = PageCache::enabled();

        return ['group' => 'extras', 'label' => __('Faster pages'), 'state' => $on ? 'ok' : 'info',
            'text' => $on ? 'On: public pages are kept ready.' : 'Off. Switching it on in Settings makes public pages quicker.', 'href' => route('admin.settings')];
    }
}
