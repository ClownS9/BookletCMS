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
 * Shown in Settings › Check-up, by `php artisan booklet:doctor`, and to
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
            'backups', 'version_control',
            'inserts', 'stacy', 'page_cache', 'search_engines',
        ] as $check) {
            try {
                $result = self::{Str::camel($check)}($url);
            } catch (Throwable $e) {
                report($e);
                $result = ['label' => Str::headline($check), 'group' => 'server', 'state' => 'warn', 'text' => 'This check couldn’t run: '.Str::limit($e->getMessage(), 160)];
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

        return ['group' => 'server', 'label' => 'PHP', 'state' => $ok ? 'ok' : 'fail',
            'text' => 'PHP '.PHP_VERSION.($ok ? '.' : ', and Booklet needs 8.2 or newer.'),
            'fix' => $ok ? null : 'Ask your host to switch the site to PHP 8.3 (most control panels have a PHP version setting).'];
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
            $missing !== [] => ['group' => 'server', 'label' => 'PHP extensions', 'state' => 'fail', 'text' => 'Missing: '.$list($missing).'.',
                'fix' => 'Ask your host to turn these PHP extensions on.'],
            $without !== [] => ['group' => 'server', 'label' => 'PHP extensions', 'state' => 'warn', 'text' => 'Everything needed is there. Without '.$list($without).', those don’t work.',
                'fix' => 'Ask your host to turn on: '.implode(', ', array_keys($without)).'.'],
            default => ['group' => 'server', 'label' => 'PHP extensions', 'state' => 'ok', 'text' => 'Everything Booklet uses is there.'],
        };
    }

    protected static function database(): array
    {
        $driver = DB::connection()->getDriverName();
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (Throwable $e) {
            return ['group' => 'server', 'label' => 'Database', 'state' => 'fail', 'text' => 'Booklet can’t reach its database: '.Str::limit($e->getMessage(), 160),
                'fix' => 'Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.'];
        }
        $names = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite', 'sqlsrv' => 'SQL Server'];

        return ['group' => 'server', 'label' => 'Database', 'state' => 'ok', 'text' => 'Connected ('.($names[$driver] ?? $driver).').'];
    }

    protected static function migrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['group' => 'server', 'label' => 'Database tables', 'state' => 'fail', 'text' => 'The database is empty.', 'command' => 'php artisan migrate --force'];
        }
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $pending = array_diff($files, $migrator->getRepository()->getRan());

        return $pending === []
            ? ['group' => 'server', 'label' => 'Database tables', 'state' => 'ok', 'text' => 'Up to date.']
            : ['group' => 'server', 'label' => 'Database tables', 'state' => 'fail',
                'text' => count($pending).' '.Str::plural('change', count($pending)).' to the database haven’t been made yet (after an update, usually), so some pages may break.',
                'command' => 'php artisan migrate --force'];
    }

    protected static function folders(): array
    {
        $dirs = ['storage/app' => storage_path('app'), 'storage/framework' => storage_path('framework'), 'storage/logs' => storage_path('logs'), 'bootstrap/cache' => base_path('bootstrap/cache')];
        $stuck = array_keys(array_filter($dirs, fn ($dir) => ! is_dir($dir) || ! is_writable($dir)));

        return $stuck === []
            ? ['group' => 'server', 'label' => 'Folders Booklet writes to', 'state' => 'ok', 'text' => 'storage/ and bootstrap/cache are writable.']
            : ['group' => 'server', 'label' => 'Folders Booklet writes to', 'state' => 'fail', 'text' => 'Booklet can’t write to '.implode(', ', $stuck).'. Uploads, sessions and logs fail.',
                'fix' => 'Let the web server write to them (your host can do this), or on your own server:', 'command' => 'chmod -R ug+rw storage bootstrap/cache'];
    }

    protected static function storageLink(): array
    {
        $link = public_path('storage');

        return is_dir($link) || is_link($link)
            ? ['group' => 'server', 'label' => 'Pictures folder', 'state' => 'ok', 'text' => 'public/storage points to the uploads, so pictures show.']
            : ['group' => 'server', 'label' => 'Pictures folder', 'state' => 'fail', 'text' => 'public/storage is missing, so uploaded pictures don’t show on the site.',
                'command' => 'php artisan storage:link'];
    }

    protected static function space(): ?array
    {
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return null;
        }
        $nice = $free >= 1073741824 ? number_format($free / 1073741824, 1).' GB' : number_format($free / 1048576).' MB';

        return match (true) {
            $free < 100 * 1048576 => ['group' => 'server', 'label' => 'Disk space', 'state' => 'fail', 'text' => 'Only '.$nice.' free. Uploads and backups will start failing.', 'fix' => 'Delete old backups in Settings › Backups, or ask your host for more space.', 'href' => route('admin.backups')],
            $free < 1024 * 1048576 => ['group' => 'server', 'label' => 'Disk space', 'state' => 'warn', 'text' => $nice.' free. Each backup holds every picture, so space goes quickly.', 'href' => route('admin.backups')],
            default => ['group' => 'server', 'label' => 'Disk space', 'state' => 'ok', 'text' => $nice.' free.'],
        };
    }

    protected static function appKey(): array
    {
        return config('app.key')
            ? ['group' => 'safe', 'label' => 'App key', 'state' => 'ok', 'text' => 'Set. (Keep it: saved passwords for email and AI services can only be read with it.)']
            : ['group' => 'safe', 'label' => 'App key', 'state' => 'fail', 'text' => 'APP_KEY is empty, so sign-ins and saved passwords aren’t safe.', 'command' => 'php artisan key:generate'];
    }

    protected static function debug(): array
    {
        $debug = (bool) config('app.debug');
        $live = app()->environment('production');

        return match (true) {
            $debug && $live => ['group' => 'safe', 'label' => 'Error details', 'state' => 'fail', 'text' => 'APP_DEBUG is on, so when something breaks, readers see the site’s inner workings (and secrets).', 'fix' => 'Set APP_DEBUG=false in .env.'],
            $debug => ['group' => 'safe', 'label' => 'Error details', 'state' => 'warn', 'text' => 'APP_DEBUG is on (fine while building, but not for a site people visit).', 'fix' => 'Before going live, set APP_ENV=production and APP_DEBUG=false in .env.'],
            default => ['group' => 'safe', 'label' => 'Error details', 'state' => 'ok', 'text' => 'Hidden from readers.'],
        };
    }

    protected static function address(?string $url): array
    {
        $configured = rtrim((string) config('app.url'), '/');
        $host = parse_url($configured, PHP_URL_HOST) ?: '';
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local');

        if ($url && parse_url($url, PHP_URL_HOST) && strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) !== 0) {
            return ['group' => 'safe', 'label' => 'Site address', 'state' => 'warn',
                'text' => 'APP_URL says '.$configured.', but you’re visiting '.parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'. Links in emails and feeds point to the first.',
                'fix' => 'Set APP_URL in .env to the address people use.'];
        }
        if (str_starts_with($configured, 'http://') && ! $local) {
            return ['group' => 'safe', 'label' => 'Site address', 'state' => 'warn', 'text' => $configured.' isn’t https, so sign-ins travel unprotected.',
                'fix' => 'Turn on a free certificate (most hosts call it Let’s Encrypt or SSL), then make APP_URL start with https://.'];
        }

        return ['group' => 'safe', 'label' => 'Site address', 'state' => 'ok', 'text' => $configured.($local ? ' (on this computer).' : '.')];
    }

    // Running on its own --------------------------------------------------------------------

    protected static function scheduler(): array
    {
        $beat = (int) rescue(fn () => Cache::get(self::BEAT, 0), 0, false);
        $line = '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
        $what = 'scheduled posts going out, emails to subscribers, nightly backups and update checks';

        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            return ['group' => 'running', 'label' => 'Scheduler', 'state' => 'warn', 'text' => 'Can’t tell whether it runs: CACHE_STORE is “'.config('cache.default').'”, which forgets everything.', 'fix' => 'Use CACHE_STORE=database (or file) in .env.'];
        }
        if ($beat === 0) {
            return ['group' => 'running', 'label' => 'Scheduler', 'state' => 'fail', 'text' => 'It hasn’t run yet, so these don’t happen: '.$what.'.',
                'fix' => 'Add this to the server’s cron (your host’s control panel has a “Cron jobs” page):', 'command' => $line];
        }
        $ago = Carbon::createFromTimestamp($beat);

        return $ago->lt(now()->subMinutes(10))
            ? ['group' => 'running', 'label' => 'Scheduler', 'state' => 'fail', 'text' => 'It last ran '.$ago->diffForHumans().', so '.$what.' have stopped.',
                'fix' => 'Check the cron job is still there:', 'command' => $line]
            : ['group' => 'running', 'label' => 'Scheduler', 'state' => 'ok', 'text' => 'Running (last '.($ago->gt(now()->subMinute()) ? 'just now' : $ago->diffForHumans()).').'];
    }

    protected static function queue(): ?array
    {
        $driver = (string) config('queue.default');
        $failed = rescue(fn () => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, 0, false);
        if ($driver === 'sync') {
            return $failed ? ['group' => 'running', 'label' => 'Background jobs', 'state' => 'warn', 'text' => $failed.' failed '.Str::plural('job', $failed).' on record.', 'command' => 'php artisan queue:failed'] : null;
        }
        if ($driver === 'database') {
            $stuck = rescue(fn () => DB::table('jobs')->where('available_at', '<', now()->subMinutes(10)->timestamp)->count(), 0, false);
            if ($stuck) {
                return ['group' => 'running', 'label' => 'Background jobs', 'state' => 'fail', 'text' => $stuck.' '.Str::plural('job', $stuck).' waiting for more than ten minutes: nothing is working through them.',
                    'fix' => 'Run a queue worker (your host may call it a “daemon” or “background process”), or set QUEUE_CONNECTION=sync in .env.', 'command' => 'php artisan queue:work --tries=3'];
            }
        }

        return $failed
            ? ['group' => 'running', 'label' => 'Background jobs', 'state' => 'warn', 'text' => $failed.' failed '.Str::plural('job', $failed).' on record.', 'command' => 'php artisan queue:failed']
            : ['group' => 'running', 'label' => 'Background jobs', 'state' => 'ok', 'text' => 'Nothing waiting.'];
    }

    protected static function mail(): array
    {
        $status = MailSettings::status();

        return [
            'group' => 'running', 'label' => 'Email', 'href' => route('admin.settings.email'),
            'state' => ['on' => 'ok', 'env' => 'ok', 'off' => 'warn'][$status['state']] ?? 'warn',
            'text' => $status['text'],
            'fix' => $status['state'] === 'off' ? 'Set up your mail server in Settings › Email, so password resets and alerts arrive.' : null,
        ];
    }

    protected static function log(): ?array
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            return ['group' => 'running', 'label' => 'Problems logged', 'state' => 'ok', 'text' => 'None.'];
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

            return ['group' => 'running', 'label' => 'Problems logged', 'state' => 'warn',
                'text' => count($recent).' '.Str::plural('problem', count($recent)).' in the last hour'.($earlier ? ' ('.$earlier.' earlier today)' : '').'. The latest, at '.$last['at']->format('g:ia').': '.$last['what'],
                'fix' => ($copying($last['what']) ? 'If that was while new files were being copied in, it settles once they’re all there; check again in a few minutes. ' : '').'storage/logs/laravel.log has the details.'.$tidy];
        }
        if ($problems !== []) {
            $last = end($problems);

            return ['group' => 'running', 'label' => 'Problems logged', 'state' => $copying($last['what']) ? 'ok' : 'info',
                'text' => 'None in the last hour. '.$earlier.' earlier today, the latest at '.$last['at']->format('g:ia').': '.$last['what']
                    .($copying($last['what']) ? ' (That’s what copying in new files looks like, and it has stopped.)' : ''),
                'fix' => 'storage/logs/laravel.log has the details.'.$tidy];
        }

        return $big
            ? ['group' => 'running', 'label' => 'Problems logged', 'state' => 'warn', 'text' => 'None today, but the log file is '.round($size / 1048576).' MB.', 'fix' => 'Empty storage/logs/laravel.log, or set LOG_STACK=daily in .env to start a new one each day.']
            : ['group' => 'running', 'label' => 'Problems logged', 'state' => 'ok', 'text' => 'None today.'];
    }

    // Keeping it safe ---------------------------------------------------------------------------

    protected static function backups(): array
    {
        $latest = Backups::list()->first();
        $nightly = (bool) rescue(fn () => app(\App\Booklet\Site::class)->setting('backups_nightly', true), true, false);
        $href = route('admin.backups');

        if (! Backups::available()) {
            return ['group' => 'safe', 'label' => 'Backups', 'state' => 'fail', 'text' => 'Backups can’t be made: PHP’s zip extension is missing.', 'fix' => 'Ask your host to turn on the zip extension.', 'href' => $href];
        }
        if (! $latest) {
            return ['group' => 'safe', 'label' => 'Backups', 'state' => 'warn', 'text' => 'No backups yet.'.($nightly ? ' The first nightly one is made at 3:10am, if the scheduler runs.' : ''), 'fix' => 'Make one now in Settings › Backups.', 'href' => $href];
        }
        $age = $latest['at'];
        $copy = ' Keep a copy somewhere other than this server, too.';

        return match (true) {
            $nightly && $age->lt(now()->subDays(2)) => ['group' => 'safe', 'label' => 'Backups', 'state' => 'warn', 'text' => 'Nightly backups are on, but the newest is from '.$age->diffForHumans().'. The scheduler may not be running.', 'href' => $href],
            ! $nightly && $age->lt(now()->subDays(30)) => ['group' => 'safe', 'label' => 'Backups', 'state' => 'warn', 'text' => 'The newest backup is from '.$age->diffForHumans().', and nightly backups are off.', 'fix' => 'Switch nightly backups on in Settings › Backups.', 'href' => $href],
            default => ['group' => 'safe', 'label' => 'Backups', 'state' => 'ok', 'text' => 'Newest '.$age->diffForHumans().($nightly ? ', and one is made every night.' : '.').$copy, 'href' => $href],
        };
    }

    protected static function versionControl(): ?array
    {
        if (! VersionControl::on()) {
            return null; // not in use: nothing to say
        }
        $href = route('admin.settings.version-control');
        $label = 'Version control';
        if (! VersionControl::contentInFiles()) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => 'It’s switched on, but the book’s content is kept in the database, so there are no files to keep a history of.', 'href' => $href];
        }
        if (! VersionControl::installed()) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'fail', 'text' => 'Git isn’t installed on this server, so changes aren’t being committed.', 'fix' => 'Install git, or set BOOKLET_GIT_BINARY to where it is.', 'href' => $href];
        }
        if (! VersionControl::isRepo()) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'fail', 'text' => 'There’s no history to commit to yet.', 'fix' => 'Start one in Settings › Version control.', 'href' => $href];
        }
        $status = VersionControl::status();
        if (! empty($status['error'])) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'fail', 'text' => 'The last commit didn’t work: '.Str::limit((string) $status['error'], 200), 'href' => $href];
        }
        if (! empty($status['push_error']) && VersionControl::settings()['push']) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => 'Commits are made, but sending them on didn’t work: '.Str::limit((string) $status['push_error'], 200), 'href' => $href];
        }
        $waiting = count(VersionControl::pending());
        $first = Cache::get(VersionControl::FIRST_SEEN);
        if ($waiting && $first && $first < now()->subHour()->timestamp) {
            return ['group' => 'safe', 'label' => $label, 'state' => 'warn', 'text' => $waiting.' changed '.Str::plural('file', $waiting).' have waited over an hour. The scheduler may not be running.', 'href' => $href];
        }

        return ['group' => 'safe', 'label' => $label, 'state' => 'ok', 'text' => ! empty($status['at'])
            ? 'Every change is committed. The last commit was '.Carbon::parse($status['at'])->diffForHumans().'.'
            : 'On. The next change will be the first commit.', 'href' => $href];
    }

    // Extras -------------------------------------------------------------------------------------

    protected static function inserts(): ?array
    {
        $manager = app(\App\Inserts\InsertManager::class);
        $href = route('admin.settings.inserts');
        if ($manager->safeMode()) {
            return ['group' => 'extras', 'label' => 'Inserts', 'state' => 'warn', 'text' => 'Safe mode is on, so every insert is off.', 'fix' => 'Remove BOOKLET_SAFE_MODE from .env once the trouble is sorted.', 'href' => $href];
        }
        $all = $manager->all();
        if ($all->isEmpty()) {
            return null;
        }
        $broken = $all->filter(fn ($i) => $i->manifest('broken') || $i->tornOut())->map->name()->values();
        $recent = $all->filter(fn ($i) => $i->enabled() && $i->error() && $i->errorAt() && Carbon::parse($i->errorAt())->gt(now()->subDay()))->map->name()->values();
        $updates = $all->filter(fn ($i) => $i->updateReady() !== null)->map->name()->values();

        return match (true) {
            $broken->isNotEmpty() => ['group' => 'extras', 'label' => 'Inserts', 'state' => 'warn', 'text' => 'Torn out (it broke, so it’s kept off): '.$broken->implode(', ').'.', 'href' => $href],
            $recent->isNotEmpty() => ['group' => 'extras', 'label' => 'Inserts', 'state' => 'warn', 'text' => 'Had a problem in the last day: '.$recent->implode(', ').'.', 'href' => $href],
            $updates->isNotEmpty() => ['group' => 'extras', 'label' => 'Inserts', 'state' => 'info', 'text' => 'Updates ready: '.$updates->implode(', ').'.', 'href' => $href],
            default => ['group' => 'extras', 'label' => 'Inserts', 'state' => 'ok', 'text' => $manager->active()->count().' of '.$all->count().' switched on, all well.', 'href' => $href],
        };
    }

    protected static function stacy(): array
    {
        $ready = \App\Models\AiProvider::where('enabled', true)->get()->filter->isReady();
        $lost = \App\Models\AiProvider::where('enabled', true)->get()->filter(fn ($p) => $p->keyLost());

        return match (true) {
            $lost->isNotEmpty() => ['group' => 'extras', 'label' => 'Stacy', 'state' => 'warn', 'text' => 'A saved AI key can’t be read since APP_KEY changed.', 'fix' => 'Type the key again in Settings › Stacy’s brain.', 'href' => route('admin.settings.stacy')],
            $ready->isEmpty() => ['group' => 'extras', 'label' => 'Stacy', 'state' => 'info', 'text' => 'No AI service yet, so Stacy can’t answer.', 'href' => route('admin.settings.stacy')],
            default => ['group' => 'extras', 'label' => 'Stacy', 'state' => 'ok', 'text' => 'Thinking with '.$ready->map->name()->implode(', ').'.', 'href' => route('admin.settings.stacy')],
        };
    }

    protected static function searchEngines(): array
    {
        // An old robots.txt in public/ is served instead of the one Booklet writes (with the sitemap in it).
        if (is_file(public_path('robots.txt'))) {
            return ['group' => 'extras', 'label' => 'Search engines', 'state' => 'warn',
                'text' => 'public/robots.txt is an old fixed file, so search engines aren’t told where the sitemap is.',
                'fix' => 'Delete public/robots.txt; Booklet then writes one with the sitemap’s address.', 'command' => 'rm public/robots.txt'];
        }

        return ['group' => 'extras', 'label' => 'Search engines', 'state' => 'ok', 'text' => 'robots.txt points them to the sitemap, and every page says what it is (structured data).'];
    }

    protected static function pageCache(): array
    {
        $on = PageCache::enabled();

        return ['group' => 'extras', 'label' => 'Faster pages', 'state' => $on ? 'ok' : 'info',
            'text' => $on ? 'On: public pages are kept ready.' : 'Off. Switching it on in Settings makes public pages quicker.', 'href' => route('admin.settings')];
    }
}
