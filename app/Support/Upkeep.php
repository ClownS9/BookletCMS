<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looking after the book: clearing what's safe to clear, making it quick for
 * a live server, remaking pictures, tidying how posts and pages are written,
 * sending readers away for a moment, and starting again: clearing the parts
 * you choose (a backup is always made first), or everything, as a new install.
 *
 * Used by `php booklet …` and by Settings › Upkeep alike. Each job notes when
 * it last ran, by whom, and what it did.
 */
class Upkeep
{
    /** When each job last ran: job => [at, by, said]. Not cleared by clearCaches(). */
    public const LAST = 'booklet.upkeep.last';

    /** While readers are kept away: a small file, so it works even if the cache is off. */
    public const AWAY_FILE = 'framework/booklet-readers-away.json';

    // Clearing and quickening ------------------------------------------------

    /**
     * Clear everything that's safe to clear and is made again as needed: kept
     * public pages, compiled templates, cached settings, routes and events,
     * the theme catalogue, and PHP's kept copies of files.
     *
     * Unlike Laravel's own cache:clear, Booklet's working notes in the cache
     * (webhook timings, the last link check, sign-in codes…) are left alone.
     *
     * @return list<string> what was done, in plain words
     */
    public static function clearCaches(): array
    {
        $done = [];
        PageCache::flush();
        $done[] = __('Public pages will be put together fresh.');
        Remember::forget();
        Booklet::flush();

        foreach (['view:clear' => __('Compiled templates cleared.'), 'config:clear' => __('Cached settings cleared.'), 'route:clear' => __('Cached routes cleared.'), 'event:clear' => __('Cached events cleared.')] as $command => $said) {
            if (rescue(fn () => self::inPlace(fn () => Artisan::call($command)) === 0, false, false)) {
                $done[] = $said;
            }
        }

        $catalogue = 0;
        $catalogue += self::forgetStartingWith('booklet.catalog.');
        if ($catalogue > 0) {
            $done[] = __('Theme and insert listings will be fetched again.');
        }

        if (function_exists('opcache_reset') && @opcache_reset()) {
            $done[] = __('PHP will read Booklet’s files afresh.');
        }
        clearstatcache(true);

        self::noted('clear', implode(' ', $done));

        return $done;
    }

    /**
     * Make it quick for a live server: settings, routes, events and templates
     * prepared ahead. Anything that can't be prepared is said, not hidden.
     *
     * @return array{done: list<string>, failed: list<string>}
     */
    public static function optimize(?bool $apart = null): array
    {
        // Preparing settings and routes starts a fresh copy of Booklet to read them. In
        // the terminal that's fine; in the middle of a page it would take over that
        // page's own workings (its templates lose what they share, like the error list).
        // So from the book it runs on its own, as the terminal would.
        $apart ??= ! app()->runningInConsole();
        $php = $apart ? (new \Symfony\Component\Process\PhpExecutableFinder)->find(false) : false;

        $done = [];
        $failed = [];
        foreach (['config:cache' => __('Settings prepared.'), 'route:cache' => __('Routes prepared.'), 'event:cache' => __('Events prepared.'), 'view:cache' => __('Templates prepared.')] as $command => $said) {
            try {
                $ok = $php
                    ? \Illuminate\Support\Facades\Process::path(base_path())->timeout(180)
                        ->run([$php, 'artisan', $command, '--no-interaction', '--no-ansi'])->successful()
                    : self::inPlace(fn () => Artisan::call($command)) === 0;
                $ok ? $done[] = $said : $failed[] = $command;
            } catch (Throwable $e) {
                $failed[] = $command.': '.Str::limit($e->getMessage(), 120);
            }
            if (! in_array($said, $done, true)) {
                // A half-made cache is worse than none.
                rescue(fn () => self::inPlace(fn () => Artisan::call(str_replace(':cache', ':clear', $command))), null, false);
            }
        }
        clearstatcache(); // so this very page sees the prepared files
        self::noted('optimize', implode(' ', $done).($failed ? ' '.__('Couldn’t: :what.', ['what' => implode('; ', $failed)]) : ''));

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Run a command here, then make sure this request still answers with its
     * own Booklet (a command that starts a fresh copy points the facades at it).
     */
    protected static function inPlace(callable $run): mixed
    {
        $app = app();
        try {
            return $run();
        } finally {
            \Illuminate\Container\Container::setInstance($app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        }
    }

    // Pictures ----------------------------------------------------------------

    /** Throw away every sized copy and crop, then make the sized copies again. */
    public static function rebuildPictures(?callable $tick = null): array
    {
        @set_time_limit(0);
        $removed = Pictures::clear() + rescue(fn () => Crops::clear(), 0, false);
        $made = 0;
        $skipped = 0;
        if (Pictures::available()) {
            foreach (Booklet::assets()->all() as $asset) {
                if (! Pictures::resizable($asset->path(), $asset->container())) {
                    $skipped++;

                    continue;
                }
                foreach (Pictures::WIDTHS as $width) {
                    if (rescue(fn () => Pictures::make($asset->path(), $width), null, false)) {
                        $made++;
                    }
                }
                $tick && $tick($asset);
            }
        }
        PageCache::flush();
        $result = ['removed' => $removed, 'made' => $made, 'skipped' => $skipped, 'available' => Pictures::available()];
        self::noted('pictures', Pictures::available()
            ? __(':removed old copies removed, :made made.', ['removed' => number_format($removed), 'made' => number_format($made)])
            : __(':removed old copies removed. This server can’t make WebP pictures, so the originals are used.', ['removed' => number_format($removed)]));

        return $result;
    }

    // Tidying how things are written ------------------------------------------

    /** What tidying does, by name (shown on the page and with --help). */
    public const TIDY_RULES = [
        'endings' => 'Line endings made the same',
        'spaces' => 'Stray spaces at the ends of lines removed',
        'invisible' => 'Invisible characters from pasting removed',
        'blank' => 'Runs of empty lines made one',
        'headings' => 'Headings start under the title and go one step at a time',
        'title' => 'Extra spaces in titles removed',
    ];

    /**
     * Every post and page whose words would change, and how. Nothing is saved.
     *
     * @return list<array{entry: Entry, rules: list<string>, title: ?string, content: ?string}>
     */
    public static function tidyPreview(?string $collection = null): array
    {
        $out = [];
        foreach (Booklet::entries()->all() as $entry) {
            if ($collection && $entry->collectionHandle() !== $collection) {
                continue;
            }
            $rules = [];
            $title = (string) $entry->get('title', '');
            $newTitle = trim((string) preg_replace('/[ \t\x{00A0}]{2,}/u', ' ', str_replace("\u{00A0}", ' ', $title)));
            if ($newTitle !== $title && $newTitle !== '') {
                $rules[] = 'title';
            }
            $content = $entry->get('content');
            $newContent = null;
            if (is_string($content) && $content !== '') {
                [$newContent, $contentRules] = self::tidyText($content);
                $rules = array_merge($rules, $contentRules);
            }
            if ($rules !== []) {
                $out[] = [
                    'entry' => $entry,
                    'rules' => array_values(array_unique($rules)),
                    'title' => in_array('title', $rules, true) ? $newTitle : null,
                    'content' => $newContent !== null && $newContent !== $content ? $newContent : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Tidy those entries (all that need it, or only the given ids): each is
     * saved with an edition in its history, so any change can be taken back.
     */
    public static function tidyApply(?array $ids = null, ?string $collection = null, ?int $by = null): int
    {
        $count = 0;
        foreach (self::tidyPreview($collection) as $change) {
            $entry = $change['entry'];
            if ($ids !== null && ! in_array($entry->id(), $ids, true)) {
                continue;
            }
            if ($change['title'] !== null) {
                $entry->set('title', $change['title']);
            }
            if ($change['content'] !== null) {
                $entry->set('content', $change['content']);
            }
            $entry->save();
            rescue(fn () => Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'edited', (string) $by, 'Tidied: '.implode(', ', $change['rules'])), null, false);
            rescue(fn () => ActivityLog::record('tidied', 'edits', 'entry', $entry->id(), $entry->title()), null, false);
            $count++;
        }
        PageCache::flush();
        self::noted('tidy', trans_choice(':count post or page tidied.|:count posts and pages tidied.', $count));

        return $count;
    }

    /**
     * The same words, tidied: [text, rules that changed something]. Code
     * blocks are left exactly as they are, and so is a two-space line break.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function tidyText(string $text): array
    {
        $rules = [];
        $was = $text;

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($text !== $was) {
            $rules[] = 'endings';
        }

        $lines = explode("\n", $text);
        $inCode = false;
        $previous = 1; // the title
        $shift = null; // how far headings move, once the first one is seen
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inCode = ! $inCode;

                continue;
            }
            if ($inCode) {
                continue;
            }
            $clean = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}]/u', '', $line) ?? $line;
            $clean = str_replace("\u{00A0}", ' ', $clean);
            if ($clean !== $line) {
                $rules[] = 'invisible';
            }
            // Trailing spaces go, except a two-space line break with more on the next line.
            $isBreak = preg_match('/\S {2}$/', $clean) && isset($lines[$i + 1]) && trim($lines[$i + 1]) !== '';
            if (! $isBreak) {
                $trimmed = rtrim($clean, " \t");
                if ($trimmed !== $clean) {
                    $rules[] = 'spaces';
                }
                $clean = $trimmed;
            }
            if (preg_match('/^(\s{0,3})(#{1,6})(\s+.+)$/', $clean, $m)) {
                $level = strlen($m[2]);
                $shift ??= $level === 1 ? 1 : 0; // a # heading under the title becomes ##
                $want = min(6, $level + $shift);
                if ($want > $previous + 1) {
                    $want = $previous + 1;
                }
                if ($want !== $level) {
                    $rules[] = 'headings';
                    $clean = $m[1].str_repeat('#', $want).$m[3];
                }
                $previous = $want;
            }
            $lines[$i] = $clean;
        }
        $text = implode("\n", $lines);

        $squeezed = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $squeezed = trim($squeezed, "\n");
        if ($squeezed !== $text) {
            $rules[] = 'blank';
        }

        return [$squeezed, array_values(array_unique($rules))];
    }

    // Readers away for a while ------------------------------------------------

    /**
     * Readers see a "back soon" page; the people of the book keep working
     * (and see the site as it is). Returns what's kept about it.
     */
    public static function sendReadersAway(string $message = '', ?string $by = null, ?int $minutes = null): array
    {
        $note = [
            'since' => now()->toIso8601String(),
            'by' => $by,
            'message' => Str::limit(trim(strip_tags($message)), 300, ''),
            'until' => $minutes ? now()->addMinutes($minutes)->toIso8601String() : null,
        ];
        $path = storage_path(self::AWAY_FILE);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($note, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        PageCache::flush();
        self::noted('down', $note['message'] !== '' ? $note['message'] : __('Readers see “back soon”.'));

        return $note;
    }

    public static function welcomeReadersBack(): bool
    {
        $path = storage_path(self::AWAY_FILE);
        if (! is_file($path)) {
            return false;
        }
        @unlink($path);
        PageCache::flush();
        self::noted('up', __('Readers are welcome again.'));

        return true;
    }

    /** While readers are kept away: since, by, message, until; otherwise null. */
    public static function readersAway(): ?array
    {
        $path = storage_path(self::AWAY_FILE);
        if (! is_file($path)) {
            return null;
        }
        $note = json_decode((string) @file_get_contents($path), true);
        if (! is_array($note)) {
            return ['since' => null, 'by' => null, 'message' => '', 'until' => null];
        }
        // A time was given and it's passed: back by itself.
        if (! empty($note['until']) && rescue(fn () => now()->greaterThan($note['until']), false, false)) {
            @unlink($path);

            return null;
        }

        return $note;
    }

    // Starting again ------------------------------------------------------------

    /**
     * What a reset can clear, each on its own. 'backup' says whether a backup
     * carries it (so the backup made first can bring it back).
     *
     * @var array<string, array{label: string, about: string, backup: bool}>
     */
    public const PARTS = [
        'content' => ['label' => 'Posts, pages and their history', 'backup' => true,
            'about' => 'Every post and page with its history, menus, categories and tags, built pages and saved layouts, comments, sticky notes, bookmarks, proof copies, form replies and what inserts keep. The starter book is put back.'],
        'media' => ['label' => 'Media', 'backup' => true,
            'about' => 'Every picture and file in Media, and their smaller copies.'],
        'people' => ['label' => 'People of the book', 'backup' => true,
            'about' => 'Everyone who signs in to the book except you, and the roles you made.'],
        'readers' => ['label' => 'Readers and subscribers', 'backup' => true,
            'about' => 'Reader accounts, groups, subscribers and the letters sent to them.'],
        'settings' => ['label' => 'Settings and look', 'backup' => true,
            'about' => 'The site’s settings and look go back to Booklet’s own (its name and tagline stay), redirects go and every insert is switched off. Theme and insert files stay in their folders.'],
        'connections' => ['label' => 'Connected services', 'backup' => false,
            'about' => 'AI services, the email server, webhooks and Content API keys. Backups never carry their keys, so you set them up again.'],
        'records' => ['label' => 'Records and stats', 'backup' => false,
            'about' => 'Activity, visits and reading stats, Stacy’s conversations and the bin. Backups don’t carry these, so they can’t be brought back.'],
    ];

    /** Tables each part empties (Booklet's own stores are handled by name below). */
    protected const PART_TABLES = [
        'content' => ['comments', 'entry_notes', 'dog_ears', 'page_build_versions', 'page_builds', 'shared_sections', 'page_layouts', 'proof_notes', 'proofs', 'insert_records'],
        'readers' => ['group_members', 'groups', 'reader_password_resets', 'readers', 'post_emails', 'subscribers'],
        'settings' => ['redirects'],
        'connections' => ['ai_providers', 'mail_settings', 'webhook_deliveries', 'webhooks', 'api_keys'],
        'records' => ['activity_log', 'site_visits', 'page_stats', 'referrer_stats', 'visitor_days', 'entry_reader_days', 'entry_sources', 'visit_hours', 'device_stats', 'stacy_messages', 'stacy_conversations', 'bin_items'],
    ];

    /** Booklet's stores that hold the writing. */
    protected const CONTENT_STORES = ['entries', 'trees', 'terms', 'revisions', 'form_submissions'];

    /** Only known parts, in their usual order; 'everything' is every part. */
    public static function resetParts(array $asked): array
    {
        if (in_array('everything', $asked, true)) {
            return array_keys(self::PARTS);
        }

        return array_values(array_intersect(array_keys(self::PARTS), $asked));
    }

    /**
     * Back up, then clear the chosen parts. With $everything the book is
     * emptied completely, everyone included, and the next visit goes to First
     * setup, as a new install does. Otherwise $keep (the person resetting)
     * always stays, and the starter book is put back where content went.
     *
     * Backups themselves, and theme and insert files, are never touched.
     *
     * @return array{backup: string, parts: list<string>, everything: bool}
     */
    public static function reset(array $parts = ['content'], ?\App\Models\User $keep = null, bool $everything = false, ?\App\Models\User $by = null): array
    {
        $parts = $everything ? array_keys(self::PARTS) : self::resetParts($parts);
        throw_if($parts === [], \InvalidArgumentException::class, 'Choose something to clear.');
        throw_if(in_array('people', $parts, true) && ! $everything && ! $keep, \InvalidArgumentException::class, 'Say whose account stays.');
        @set_time_limit(0);

        $siteName = (string) rescue(fn () => Booklet::globals('site')?->get('site_name'), '', false);
        $tagline = rescue(fn () => Booklet::globals('site')?->get('tagline'), null, false);
        $backup = Backups::create('before-reset', $by ?? $keep)['name'];

        $store = fn (string $name) => \App\Booklet\Stores\StoreFactory::make(\App\Booklet\Schema\Definitions::get($name))->truncate();
        $empty = function (array $tables) {
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
            foreach ($tables as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        };

        if (in_array('media', $parts, true)) {
            self::clearMedia();
            $store('assets_meta');
        }

        if (in_array('content', $parts, true)) {
            foreach (self::CONTENT_STORES as $name) {
                $store($name);
            }
        }

        if (in_array('settings', $parts, true)) {
            $store('global_set_variables');
            rescue(function () {
                DB::table('inserts')->update(['enabled' => false, 'settings' => null]);
                app(\App\Inserts\InsertManager::class)->forget();
            }, null, false);
        }

        foreach ($parts as $part) {
            $empty(self::PART_TABLES[$part] ?? []);
        }

        if (in_array('people', $parts, true)) {
            $leaving = \App\Models\User::query()->when($keep && ! $everything, fn ($q) => $q->whereKeyNot($keep->getKey()))->get();
            foreach ($leaving as $person) {
                if ($person->avatar) {
                    rescue(fn () => Disk::of('public')->delete($person->avatar), null, false);
                }
                $person->delete(); // their passkeys and bookmarks go with them
            }
            $empty(['roles']);
            rescue(fn () => DB::table('sessions')->when($keep && ! $everything, fn ($q) => $q->where('user_id', '!=', $keep->getKey()))->delete(), null, false);
            rescue(fn () => DB::table('password_reset_tokens')->delete(), null, false);
        }

        if ($everything) {
            // Every store, how the book is arranged too: First setup starts it again.
            foreach (array_keys(\App\Booklet\Schema\Definitions::all()) as $name) {
                $store($name);
            }
        }

        Booklet::flush();
        Remember::forget();

        if (! $everything) {
            // Put back what a book needs: Posts, Pages, menus, a Media album and the site's settings.
            app(\Database\Seeders\BookletStarterSeeder::class)->run();
            Booklet::flush();
            if (in_array('settings', $parts, true) && $siteName !== '') {
                Booklet::globals('site')?->merge(['site_name' => $siteName, 'tagline' => $tagline])->save();
                Booklet::flush();
            }
            if (in_array('content', $parts, true) && $keep) {
                // The starter post is the one who started again's.
                Booklet::entries()->all()->filter(fn (Entry $e) => ! $e->get('author'))->each(fn (Entry $e) => $e->set('author', $keep->getKey())->save());
            }
        }

        self::clearCaches();
        $labels = implode(', ', array_map(fn ($p) => __(self::PARTS[$p]['label']), $parts));
        if (! $everything) {
            rescue(fn () => ActivityLog::record('started again, clearing', 'settings', 'book', null, $labels), null, false);
        }
        self::noted('reset', $everything
            ? __('Emptied everything, ready for First setup; backed up first as :name.', ['name' => $backup])
            : __('Cleared :parts; backed up first as :name.', ['parts' => $labels, 'name' => $backup]));

        return ['backup' => $backup, 'parts' => $parts, 'everything' => $everything];
    }

    /** Every file in Media (not Booklet's own folders, like profile pictures), and the smaller copies. */
    protected static function clearMedia(): void
    {
        rescue(fn () => Pictures::clear(), null, false);
        rescue(fn () => Crops::clear(), null, false);
        foreach (Booklet::assetContainers()->all() as $container) {
            $disk = rescue(fn () => Disk::of($container->attribute('disk') ?? 'public'), null, false);
            if (! $disk) {
                continue;
            }
            foreach ($disk->allFiles() as $path) {
                if (! str_starts_with(basename($path), '.') && ! Pictures::isSystem($path)) {
                    $disk->delete($path);
                }
            }
        }
        foreach (Booklet::assets()->all() as $asset) {
            // Anything known but kept elsewhere.
            rescue(fn () => Disk::of(Booklet::assetContainers()->find($asset->container())?->attribute('disk') ?? 'public')->delete($asset->path()), null, false);
        }
    }

    // Notes ---------------------------------------------------------------------

    /** @return array<string, array{at: string, by: ?string, terminal?: bool, said: string}> */
    public static function last(): array
    {
        return (array) rescue(fn () => Cache::get(self::LAST, []), [], false);
    }

    protected static function noted(string $job, string $said): void
    {
        // From the terminal there's no one signed in; the page says "the terminal" in its own language.
        $by = request()->user()->name ?? null;
        $terminal = $by === null && app()->runningInConsole();
        rescue(fn () => Cache::forever(self::LAST, array_merge(self::last(), [$job => ['at' => now()->toIso8601String(), 'by' => $by, 'terminal' => $terminal, 'said' => $said]])), null, false);
    }

    /** Forget cache keys that start with a prefix, where the cache can list them. */
    protected static function forgetStartingWith(string $prefix): int
    {
        $count = 0;
        $store = Cache::getStore();
        if ($store instanceof \Illuminate\Cache\DatabaseStore) {
            $table = config('cache.stores.database.table', 'cache');
            $full = config('cache.prefix').$prefix;
            $count = rescue(fn () => DB::table($table)->where('key', 'like', str_replace(['%', '_'], ['\%', '\_'], $full).'%')->delete(), 0, false);
        } elseif ($store instanceof \Illuminate\Cache\FileStore || $store instanceof \Illuminate\Cache\ArrayStore) {
            // Their keys can't be listed; the listings expire within minutes anyway.
            $count = 0;
        }

        return (int) $count;
    }
}
