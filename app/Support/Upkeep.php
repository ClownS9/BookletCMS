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
 * sending readers away for a moment, and (from the terminal only) starting
 * again from the starter book.
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
            if (rescue(fn () => Artisan::call($command) === 0, false, false)) {
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
    public static function optimize(): array
    {
        $done = [];
        $failed = [];
        foreach (['config:cache' => __('Settings prepared.'), 'route:cache' => __('Routes prepared.'), 'event:cache' => __('Events prepared.'), 'view:cache' => __('Templates prepared.')] as $command => $said) {
            try {
                Artisan::call($command) === 0 ? $done[] = $said : $failed[] = $command;
            } catch (Throwable $e) {
                $failed[] = $command.': '.Str::limit($e->getMessage(), 120);
                // A half-made cache is worse than none.
                rescue(fn () => Artisan::call(str_replace(':cache', ':clear', $command)), null, false);
            }
        }
        self::noted('optimize', implode(' ', $done).($failed ? ' '.__('Couldn’t: :what.', ['what' => implode('; ', $failed)]) : ''));

        return ['done' => $done, 'failed' => $failed];
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

    // Starting again (terminal only) -------------------------------------------

    /**
     * Back up, then empty the book's writing (posts, pages, their history,
     * menus and page tree, categories and tags, comments, notes, bookmarks)
     * and put the starter book back. People, settings, themes, inserts and
     * media stay. Returns the backup's name.
     */
    public static function reset(?int $by = null): string
    {
        @set_time_limit(0);
        $backup = Backups::create('before-reset')['name'];

        foreach (['entries', 'trees', 'terms', 'revisions'] as $name) {
            \App\Booklet\Stores\StoreFactory::make(\App\Booklet\Schema\Definitions::get($name))->truncate();
        }
        foreach (['comments', 'entry_notes', 'dog_ears'] as $table) {
            rescue(fn () => DB::table($table)->delete(), null, false);
        }
        Booklet::flush();
        Remember::forget();
        app(\Database\Seeders\BookletStarterSeeder::class)->run();
        Booklet::flush();
        PageCache::flush();
        rescue(fn () => ActivityLog::record('started the book again from', 'settings', 'book', null, __('the starter book')), null, false);
        self::noted('reset', __('Started again from the starter book; backed up first as :name.', ['name' => $backup]));

        return $backup;
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
