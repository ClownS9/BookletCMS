<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Automated version control (Statamic Pro's Git automation): with the book's
 * content kept as files, every change is committed to git on its own.
 *
 * After a change Booklet waits for a quiet couple of minutes (so a burst of
 * autosaves is one commit), then commits only the book's own folders
 * (content/, resources/blueprints, fieldsets, forms; pictures if asked). Each
 * commit is in the name of whoever made the changes, with a message from the
 * activity log ("Hana edited “Kuih lapis”"). Pushing to a remote happens
 * only when it's switched on.
 *
 * Git runs as a plain program with its arguments listed (never through a
 * shell) and never waits for a password: a push that needs one fails and
 * says so.
 */
class VersionControl
{
    public const STATUS = 'booklet.git.status';

    public const FIRST_SEEN = 'booklet.git.first_seen';

    /** Waiting this long with changes in a busy book, commit anyway. */
    public const MAX_WAIT_MINUTES = 30;

    // Settings ---------------------------------------------------------------------------------

    protected static function settingsFile(): string
    {
        return storage_path('app/private/version-control.json');
    }

    /** @return array{on: bool, push: bool, media: bool, everything: bool} */
    public static function settings(): array
    {
        $saved = rescue(fn () => json_decode((string) @file_get_contents(self::settingsFile()), true), [], false);
        $saved = is_array($saved) ? $saved : [];

        return [
            'on' => (bool) (config('booklet.git.enabled') ?? ($saved['on'] ?? false)),
            'push' => (bool) (config('booklet.git.push') ?? ($saved['push'] ?? false)),
            'media' => (bool) ($saved['media'] ?? false),
            'everything' => (bool) ($saved['everything'] ?? false),
        ];
    }

    public static function saveSettings(array $changes): void
    {
        $settings = array_merge(self::settings(), array_intersect_key($changes, array_flip(['on', 'push', 'media', 'everything'])));
        File::ensureDirectoryExists(dirname(self::settingsFile()));
        file_put_contents(self::settingsFile(), json_encode(array_map('boolval', $settings), JSON_PRETTY_PRINT));
    }

    /** Set in .env (BOOKLET_GIT / BOOKLET_GIT_PUSH), so not changeable from the admin. */
    public static function fixedByEnv(string $what): bool
    {
        return config('booklet.git.'.($what === 'on' ? 'enabled' : $what)) !== null;
    }

    public static function on(): bool
    {
        return self::settings()['on'];
    }

    /** Version control only makes sense when the book's content is kept as files. */
    public static function contentInFiles(): bool
    {
        return rescue(fn () => \App\Booklet\Facades\Booklet::driver('entries') === 'file', false, false);
    }

    public static function root(): string
    {
        return rtrim((string) (config('booklet.git.path') ?: base_path()), '/');
    }

    // Running git ---------------------------------------------------------------------------------

    /** @param  list<string>  $args */
    public static function git(array $args, ?User $author = null, int $timeout = 60): ProcessResult
    {
        $committer = (array) config('booklet.git.committer', []);
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',  // never wait for a password
            'GIT_ASKPASS' => 'echo',
            'GCM_INTERACTIVE' => 'never',
            'GIT_COMMITTER_NAME' => (string) ($committer['name'] ?? 'Booklet'),
            'GIT_COMMITTER_EMAIL' => (string) ($committer['email'] ?? 'booklet@localhost'),
            'GIT_AUTHOR_NAME' => $author?->name ?: (string) ($committer['name'] ?? 'Booklet'),
            'GIT_AUTHOR_EMAIL' => $author?->email ?: (string) ($committer['email'] ?? 'booklet@localhost'),
        ];

        return Process::path(self::root())->env($env)->timeout($timeout)
            ->run([(string) config('booklet.git.binary', 'git'), ...$args]);
    }

    public static function installed(): bool
    {
        return rescue(fn () => self::git(['--version'], timeout: 10)->successful(), false, false);
    }

    /** Is the book's folder (or a folder above it) a git repository? */
    public static function isRepo(): bool
    {
        return rescue(fn () => trim(self::git(['rev-parse', '--is-inside-work-tree'], timeout: 10)->output()) === 'true', false, false);
    }

    /** Start a history in the book's folder (only when there isn't one). */
    public static function init(): void
    {
        self::guardPrivateFolders();
        if (self::isRepo()) {
            return;
        }
        $result = self::git(['init']);
        if (! $result->successful()) {
            throw new RuntimeException('Git couldn’t start a history here: '.Str::limit(trim($result->errorOutput()), 200));
        }
        rescue(fn () => self::git(['symbolic-ref', 'HEAD', 'refs/heads/main']), null, false); // "main", like GitHub
    }

    /**
     * Booklet's own storage (revisions, form submissions) never goes to git:
     * it gets a .gitignore of its own, the way Laravel's storage folders do,
     * so even a plain "git add ." by hand leaves it out. Made again if it
     * goes missing; one already there (perhaps edited) is left alone.
     */
    public static function guardPrivateFolders(): void
    {
        $folder = (string) config('booklet.paths.storage', storage_path('booklet'));
        $file = $folder.'/.gitignore';
        if ($folder === '' || is_file($file)) {
            return;
        }

        rescue(function () use ($folder, $file) {
            if (! is_dir($folder)) {
                mkdir($folder, 0755, true);
            }
            file_put_contents($file, "# Booklet's revisions and form submissions: kept on this server, never in git.\n*\n!.gitignore\n");
        }, null, false);
    }

    // What's tracked ----------------------------------------------------------------------------

    /**
     * The folders committed, relative to the repository (only ones that exist).
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        return collect((array) config('booklet.git.paths', []))
            ->map(fn ($p) => trim((string) $p, '/'))
            ->filter(fn ($p) => $p !== '' && ! str_contains($p, '..') && file_exists(self::root().'/'.$p))
            ->values()->all();
    }

    /** Media files on the public disk, relative to the repository (not Booklet's own copies). */
    protected static function mediaFiles(): array
    {
        $public = rtrim(Disk::of('public')->path(''), '/');
        if (! str_starts_with($public, self::root().'/')) {
            return [];
        }
        $prefix = substr($public, strlen(self::root()) + 1);

        return rescue(fn () => \App\Booklet\Facades\Booklet::assets()->all()
            ->filter(fn ($a) => self::onPublicDisk($a) && ! Pictures::isSystem($a->path()) && is_file($public.'/'.$a->path()))
            ->map(fn ($a) => $prefix.'/'.$a->path())
            ->values()->all(), [], false);
    }

    /** Is an asset kept on the public disk (so it lives in the book's folder)? */
    protected static function onPublicDisk(\App\Booklet\Data\Asset $asset): bool
    {
        return (\App\Booklet\Facades\Booklet::assetContainers()->find($asset->container())?->attribute('disk') ?? 'public') === 'public';
    }

    /**
     * Never committed, even with "everything" on and even if .gitignore
     * forgets them: secrets, the database, other people's code, caches,
     * logs and backups. (.env.example is fine: it has no secrets.)
     */
    public const NEVER = [
        '.env', '.env.[!e]*', '**/.env', 'auth.json', '**/*.pem', 'storage/*.key',
        '**/*.sqlite', '**/*.sqlite-*', '**/*.sqlite3', '**/*.db',
        'vendor/**', 'node_modules/**',
        'storage/logs/**', 'storage/framework/**', 'storage/app/private/**', 'storage/booklet/**', 'storage/pail/**',
        // public/storage is a symlink (storage:link): name the link itself; git won't look "beyond" a symlink.
        'public/storage', 'public/hot', '.phpunit.result.cache', '**/.DS_Store',
    ];

    /** Committing the whole project (code too), not just the book's content? */
    public static function everything(): bool
    {
        return self::settings()['everything'];
    }

    /**
     * What git is pointed at: the book's folders (and Media, if asked), or with
     * "everything" on, the whole project minus what's never committed.
     *
     * @return list<string>
     */
    public static function scope(): array
    {
        $never = array_map(fn ($p) => ':(exclude,glob)'.$p, self::NEVER);
        if (self::everything()) {
            return ['.', ...$never];
        }

        return [...self::paths(), ...(self::settings()['media'] ? [self::mediaRoot()] : []), ...$never];
    }

    /**
     * Changed files waiting to be committed: [status, path] ("M", "A", "D", "??").
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function pending(): array
    {
        $paths = self::paths();
        if (($paths === [] && ! self::everything()) || ! self::isRepo()) {
            return [];
        }
        $out = self::git(['status', '--porcelain', '--untracked-files=all', '--', ...self::scope()], timeout: 30)->output();

        return collect(preg_split('/\R/', $out))->filter()->map(fn ($line) => [trim(substr($line, 0, 2)), trim(substr($line, 3), '"')])->values()->all();
    }

    protected static function mediaRoot(): string
    {
        $public = rtrim(Disk::of('public')->path(''), '/');

        return str_starts_with($public, self::root().'/') ? substr($public, strlen(self::root()) + 1) : '.';
    }

    // Committing --------------------------------------------------------------------------------

    /**
     * Commit when it's time: there are changes, and the book has been quiet for
     * the delay (or changes have waited long enough in a busy book).
     */
    public static function due(): bool
    {
        if (! self::on() || ! self::contentInFiles() || self::pending() === []) {
            Cache::forget(self::FIRST_SEEN);

            return false;
        }
        $first = Cache::get(self::FIRST_SEEN);
        if (! $first) {
            Cache::forever(self::FIRST_SEEN, now()->timestamp);
            $first = now()->timestamp;
        }
        $quiet = (int) config('booklet.git.delay', 2);
        $last = ActivityLog::query()->latest('created_at')->value('created_at');
        // Quiet in the admin, and the changed files themselves left alone (someone may be mid-edit in a code editor).
        $touched = collect(self::pending())->take(500)
            ->map(fn ($f) => @filemtime(self::root().'/'.$f[1]) ?: 0)->max() ?: 0;
        $settled = (! $last || Carbon::parse($last)->lte(now()->subMinutes($quiet)))
            && $touched <= now()->subMinutes($quiet)->timestamp;

        return $settled || $first <= now()->subMinutes(self::MAX_WAIT_MINUTES)->timestamp;
    }

    /**
     * Commit the book's changes. Returns the new commit's short hash, or null
     * when there was nothing to commit.
     */
    public static function commit(?string $message = null, ?User $by = null): ?string
    {
        if (! self::isRepo()) {
            throw new RuntimeException('There’s no history here yet. Start one in Settings › Version control.');
        }
        $paths = self::paths();
        if ($paths === [] && ! self::everything()) {
            throw new RuntimeException('None of the folders to keep a history of are here: '.implode(', ', (array) config('booklet.git.paths')).'.');
        }

        // Everything in scope: the book's folders (or the whole project, with "everything" on).
        $added = self::git(['-c', 'advice.addIgnoredFile=false', 'add', '--all', '--', ...self::scope()]);
        // Git calls it an error when some excluded paths are already in .gitignore; everything else was added.
        $onlyIgnored = str_contains($added->errorOutput(), 'are ignored by one of your .gitignore files')
            && ! str_contains($added->errorOutput(), 'fatal:');
        if (! $added->successful() && ! $onlyIgnored) {
            return self::failed('Git couldn’t gather the changes: '.trim($added->errorOutput()));
        }
        if (self::settings()['media']) {
            foreach (array_chunk(self::mediaFiles(), 200) as $chunk) {
                self::git(['add', '--force', '--', ...$chunk]);
            }
            self::git(['add', '--update', '--', self::mediaRoot()]); // removed and changed ones
        }

        // Only what's in scope: anything someone staged by hand elsewhere stays as it was.
        $staged = array_values(array_filter(preg_split('/\R/', trim(self::git(['diff', '--cached', '--name-only', '--', ...self::scope()])->output()))));
        if ($staged === []) {
            Cache::forget(self::FIRST_SEEN);

            return null;
        }

        [$subject, $body, $author] = self::describe($by, $staged);
        $subject = $message !== null && trim($message) !== '' ? Str::limit(trim($message), 100, '…') : $subject;
        $result = self::git(['commit', '--no-verify', '--no-gpg-sign', '-m', $subject, ...($body !== '' ? ['-m', $body] : []), '--', ...self::scope()], $author);
        if (! $result->successful()) {
            return self::failed('Git couldn’t commit: '.Str::limit(trim($result->errorOutput() ?: $result->output()), 300));
        }

        $hash = trim(self::git(['rev-parse', '--short', 'HEAD'])->output());
        Cache::forget(self::FIRST_SEEN);
        self::remember(['hash' => $hash, 'at' => now()->toIso8601String(), 'error' => null, 'pushed' => null,
            'log_id' => (int) ActivityLog::query()->max('id')]);

        if (self::settings()['push']) {
            self::push();
        }

        return $hash;
    }

    /** Send commits to the remote (origin, unless set otherwise). Returns true when it went. */
    public static function push(): bool
    {
        $remote = (string) config('booklet.git.remote', 'origin');
        if (! in_array($remote, self::remotes(), true)) {
            self::remember(['pushed' => false, 'push_error' => "There’s no remote called “{$remote}” to send to."]);

            return false;
        }
        $branch = (string) (config('booklet.git.branch') ?: trim(self::git(['rev-parse', '--abbrev-ref', 'HEAD'])->output()));
        $result = self::git(['push', $remote, 'HEAD:'.$branch], timeout: 90);
        self::remember($result->successful()
            ? ['pushed' => now()->toIso8601String(), 'push_error' => null]
            : ['pushed' => false, 'push_error' => Str::limit(trim($result->errorOutput()), 300)]);

        return $result->successful();
    }

    /** @return list<string> */
    public static function remotes(): array
    {
        return array_values(array_filter(preg_split('/\R/', trim(rescue(fn () => self::git(['remote'], timeout: 10)->output(), '', false)))));
    }

    /**
     * The commit's message and author, from what people did since the last
     * commit: [subject, body, author (one person) or null].
     *
     * @return array{0: string, 1: string, 2: ?User}
     */
    public static function describe(?User $by = null, array $staged = []): array
    {
        // Files outside the book's own folders (with "everything" on): code, the README…
        $content = [...self::paths(), self::mediaRoot()];
        $other = array_values(array_filter($staged, fn ($f) => ! collect($content)->contains(fn ($p) => $p !== '.' && ($f === $p || str_starts_with($f, rtrim($p, '/').'/')))));
        $otherLine = $other === [] ? '' : implode(', ', array_slice($other, 0, 6)).(count($other) > 6 ? ' and '.(count($other) - 6).' more' : '');

        // What people did since the last commit: by the log line it got up to, or by time.
        $after = self::status()['log_id'] ?? null;
        $since = $after === null ? self::lastCommitTime() : null;
        $logs = ActivityLog::query()->with('user')
            ->when($after !== null, fn ($q) => $q->where('id', '>', (int) $after))
            ->when($since, fn ($q) => $q->where('created_at', '>', $since))
            ->whereNotIn('kind', ['people'])
            ->where(fn ($q) => $q->whereNull('subject_key')->orWhereNotIn('subject_key', ['version_control'])) // about git itself, not the book
            ->oldest('created_at')->limit(200)->get();

        $lines = $logs->map(function (ActivityLog $log) {
            $who = $log->actor === 'stacy' ? 'Stacy (for '.($log->user->name ?? 'someone').')' : ($log->user->name ?? 'Someone');

            return trim($who.' '.$log->action.' '.($log->subject_label ? '“'.$log->subject_label.'”' : ''));
        })->unique()->values();

        $people = $logs->pluck('user')->filter()->unique('id')->values();
        $author = $by ?? ($people->count() === 1 ? $people->first() : null);

        if ($lines->isEmpty()) {
            if ($other !== []) {
                // Only project files changed (nobody did anything in the admin): name them.
                $subject = 'Updated '.implode(', ', array_slice($other, 0, 2)).(count($other) > 2 ? ' and '.(count($other) - 2).' more '.Str::plural('file', count($other) - 2) : '');

                return [Str::limit($subject, 100, '…'), count($other) > 2 ? 'Changed: '.$otherLine : '', $by];
            }

            return ['Changes to the book'.($by ? ' by '.$by->name : ''), '', $author];
        }
        $also = $other !== [] ? 'Also changed: '.$otherLine : '';
        if ($lines->count() === 1) {
            return [Str::limit($lines->first(), 100, '…'), $also, $other !== [] ? $by : $author];
        }
        $names = $people->pluck('name')->take(3)->implode(' and ');
        $subject = $lines->count().' changes'.($names !== '' ? ' by '.$names : '');
        $body = $lines->take(40)->map(fn ($l) => '- '.$l)->implode("\n").($lines->count() > 40 ? "\n- and ".($lines->count() - 40).' more' : '');

        return [$subject, trim($body.($also !== '' ? "\n\n".$also : '')), $author];
    }

    protected static function lastCommitTime(): ?Carbon
    {
        $at = trim(rescue(fn () => self::git(['log', '-1', '--format=%cI', '--', ...self::scope()], timeout: 10)->output(), '', false));

        return $at !== '' ? Carbon::parse($at) : null;
    }

    protected static function failed(string $error): ?string
    {
        self::remember(['error' => $error, 'failed_at' => now()->toIso8601String()]);

        throw new RuntimeException($error);
    }

    protected static function remember(array $status): void
    {
        Cache::forever(self::STATUS, array_merge((array) Cache::get(self::STATUS, []), $status));
    }

    /** The last commit, push and any problem: for Settings and the check-up. */
    public static function status(): array
    {
        return (array) Cache::get(self::STATUS, []);
    }

    // Reading the history -----------------------------------------------------------------------

    /**
     * The book's commits, newest first. Each: hash, short, author, email,
     * at (Carbon, in the site's time), subject, body, files (how many changed).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function history(int $limit = 20, int $skip = 0): Collection
    {
        $paths = self::paths();
        if (($paths === [] && ! self::everything()) || ! self::isRepo()) {
            return collect();
        }
        $sep = "\x1f";
        $end = "\x1e";
        $out = self::git(['log', '-n', (string) $limit, '--skip='.$skip, '--format=%H'.$sep.'%h'.$sep.'%an'.$sep.'%ae'.$sep.'%aI'.$sep.'%s'.$sep.'%b'.$end, '--shortstat', '--', ...self::scope()], timeout: 30)->output();

        // @phpstan-ignore return.type (each commit's shape is listed above; the pairing-up below hides it)
        return collect(explode($end, $out))->map(fn ($chunk) => trim($chunk))->filter()->values()
            // --shortstat for one commit lands at the start of the next chunk: pair them back up.
            ->pipe(function (Collection $chunks) use ($sep) {
                $commits = [];
                foreach ($chunks as $chunk) {
                    if (preg_match('/^\s*(\d+) files? changed/', $chunk, $m) && $commits !== []) {
                        $commits[count($commits) - 1]['files'] = (int) $m[1];
                        $chunk = trim((string) preg_replace('/^[^\n]*files? changed[^\n]*\n?/', '', $chunk));
                        if ($chunk === '') {
                            continue;
                        }
                    }
                    $parts = explode($sep, $chunk);
                    if (count($parts) < 7) {
                        continue;
                    }
                    $commits[] = [
                        'hash' => $parts[0], 'short' => $parts[1], 'author' => $parts[2], 'email' => $parts[3],
                        'at' => Carbon::parse($parts[4])->setTimezone(config('app.timezone')), 'subject' => $parts[5], 'body' => trim($parts[6]), 'files' => 0,
                    ];
                }

                return collect($commits);
            });
    }

    /**
     * What a commit changed, in the book's folders: [status, path, what it is].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function files(string $hash): array
    {
        if (! preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
            return [];
        }
        $out = self::git(['show', '--format=', '--name-status', '--no-renames', $hash, '--', ...self::scope()], timeout: 30)->output();

        return collect(preg_split('/\R/', trim($out)))->filter()->map(function ($line) {
            [$status, $path] = array_pad(preg_split('/\t/', $line, 2), 2, '');

            return [substr($status, 0, 1), $path, self::label($path)];
        })->values()->all();
    }

    /** "content/collections/posts/kuih-lapis.md" → "Posts: kuih-lapis". */
    public static function label(string $path): string
    {
        $parts = explode('/', $path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return match (true) {
            ($parts[1] ?? '') === 'collections' && count($parts) > 3 => Str::headline($parts[2]).': '.$name,
            ($parts[1] ?? '') === 'collections' => 'Chapter: '.Str::headline($name),
            ($parts[1] ?? '') === 'taxonomies' && count($parts) > 3 => Str::headline($parts[2]).': '.$name,
            ($parts[1] ?? '') === 'taxonomies' => Str::headline($name).' (the list)',
            ($parts[1] ?? '') === 'globals' => 'Settings: '.Str::headline($parts[count($parts) - 2] !== 'globals' ? $parts[count($parts) - 2].' '.$name : $name),
            ($parts[1] ?? '') === 'navigation' || ($parts[1] ?? '') === 'trees' => 'Menus and page order',
            ($parts[1] ?? '') === 'assets' => 'Media details',
            ($parts[1] ?? '') === 'blueprints' => 'Blueprint: '.Str::headline($name),
            ($parts[1] ?? '') === 'fieldsets' => 'Fieldset: '.Str::headline($name),
            ($parts[1] ?? '') === 'forms' => 'Form: '.Str::headline($name),
            default => $path,
        };
    }

    /**
     * Put the book's folders back to how they were at a commit, as a new commit
     * (so it can be undone too). Anything not yet committed is committed first.
     */
    public static function restore(string $hash, User $by): ?string
    {
        if (! preg_match('/^[0-9a-f]{7,40}$/', $hash) || ! self::git(['cat-file', '-e', $hash.'^{commit}'], timeout: 10)->successful()) {
            throw new RuntimeException('That point in the history isn’t there.');
        }
        rescue(fn () => self::commit(null, $by), null, false); // keep what's there now first

        $when = Carbon::parse(trim(self::git(['log', '-1', '--format=%aI', $hash])->output()))->setTimezone(config('app.timezone'))->format('j M Y, H:i');
        $result = self::git(['restore', '--source='.$hash, '--staged', '--worktree', '--', ...self::paths()]);
        if (! $result->successful()) {
            // Older git: the same, the long way round.
            self::git(['rm', '-r', '--cached', '--quiet', '--', ...self::paths()]);
            $result = self::git(['checkout', $hash, '--', ...self::paths()]);
            if (! $result->successful()) {
                throw new RuntimeException('Git couldn’t put that back: '.Str::limit(trim($result->errorOutput()), 200));
            }
        }

        \App\Booklet\Facades\Booklet::flush();
        rescue(fn () => PageCache::flush(), null, false);
        ActivityLog::record('put the book back to', 'settings', 'version', $hash, trim($when));

        return self::commit('Put the book back to how it was on '.trim($when), $by);
    }
}

