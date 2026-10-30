<?php

namespace App\Inserts;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds the inserts in inserts/, and tucks the switched-on ones into the
 * book: their views, pages, [[slips]], routes, events and schedule.
 *
 * An insert whose insert.php fails to load is "torn out": kept off (and
 * said so on the shelf) until someone presses Try again, so one broken
 * insert never takes the book down with it. BOOKLET_SAFE_MODE=true in .env
 * starts the book with every insert off.
 */
class InsertManager
{
    /** Things an insert can react to, with what it's handed. */
    public const EVENTS = [
        'entry.published' => 'An entry went live (the Entry)',
        'comment.received' => 'A reader left a comment (the Comment)',
        'message.received' => 'Someone wrote from the contact form (the message)',
        'subscriber.confirmed' => 'A reader confirmed their email subscription (the Subscriber)',
    ];

    public const FREQUENCIES = ['everyFiveMinutes', 'hourly', 'daily', 'weekly'];

    /** @var Collection<string, Insert>|null */
    protected ?Collection $inserts = null;

    protected bool $booted = false;

    public function path(): string
    {
        return (string) config('booklet.inserts_path', base_path('inserts'));
    }

    public function safeMode(): bool
    {
        return (bool) config('booklet.safe_mode', false);
    }

    /** @return Collection<string, Insert> every insert on the shelf, on or off */
    public function all(): Collection
    {
        if ($this->inserts !== null) {
            return $this->inserts;
        }

        $found = collect();
        foreach (glob($this->path().'/*/insert.php') ?: [] as $file) {
            $key = basename(dirname($file));
            if (! preg_match('/^[a-z][a-z0-9-]{0,59}$/', $key)) {
                continue; // folder names are the insert's address: plain lowercase only
            }
            try {
                $manifest = (static fn (string $__file) => require $__file)($file);
                if (! is_array($manifest)) {
                    throw new \UnexpectedValueException('insert.php must return an array that describes the insert.');
                }
            } catch (Throwable $e) {
                $this->tearOut($key, $e, onlyIfOn: true);
                $manifest = ['name' => Str::headline($key), 'about' => 'This insert couldn’t be read.', 'broken' => true];
            }
            $found[$key] = new Insert($key, dirname($file), $manifest);
            if (empty($manifest['broken'])) {
                // Remembered, so its [[slips]] quietly disappear (not show as text) if it ever breaks.
                $names = array_keys($found[$key]->slips());
                rescue(fn () => \Illuminate\Support\Facades\Cache::get('booklet.insert-slips.'.$key) === $names
                    || \Illuminate\Support\Facades\Cache::forever('booklet.insert-slips.'.$key, $names), null, false);
            }
        }

        return $this->inserts = $found->sortBy(fn (Insert $i) => $i->name())->values()->keyBy(fn (Insert $i) => $i->key);
    }

    public function find(string $key): ?Insert
    {
        return $this->all()->get($key);
    }

    /** @return Collection<string, Insert> the ones in use right now */
    public function active(): Collection
    {
        return $this->safeMode() ? collect() : $this->all()->filter(fn (Insert $i) => ! $i->manifest('broken') && $i->active() && ! $i->builtIn());
    }

    /**
     * Inserts that became part of Booklet: never run any more, and the shelf
     * says where they went.
     */
    public const BUILT_IN = ['page-builder' => 'Building on the page, for every page and post and the blog page (since Booklet 1.4). What you built with it comes across with php booklet builder-import'];

    // Public pages --------------------------------------------------------------------------

    /**
     * The public pages switched-on inserts make ('pages' in insert.php: a
     * list of paths, or of ['path' => '/events', 'updated' => a date]), for
     * the sitemap and for publishing as plain files.
     *
     * @return list<array{path: string, updated: mixed}>
     */
    public function publicPages(): array
    {
        $pages = [];
        foreach ($this->active() as $insert) {
            $list = $insert->manifest('pages');
            if ($list === null) {
                continue;
            }
            try {
                $list = is_callable($list) ? $list($insert) : $list;
            } catch (Throwable $e) {
                report($e);
                $this->noteError($insert->key, $e);

                continue;
            }
            foreach ((array) $list as $page) {
                $path = is_array($page) ? ($page['path'] ?? null) : $page;
                if (is_string($path) && preg_match('#^/[A-Za-z0-9._~/-]*$#', $path) && ! str_contains($path, '..')) {
                    $pages[] = ['path' => '/'.trim($path, '/'), 'updated' => is_array($page) ? ($page['updated'] ?? null) : null];
                }
            }
        }

        return $pages;
    }

    /**
     * The home page, if a switched-on insert makes it ('home' in insert.php:
     * a function given the insert and the request, answering with a page,
     * or null to leave the home page as it is). Booklet 1.2.
     */
    public function home(\Illuminate\Http\Request $request): mixed
    {
        return $this->page('home', $request);
    }

    /**
     * The blog page, if a switched-on insert draws it ('blog' in insert.php:
     * a function given the insert and the request, answering with a page,
     * or null to leave the blog as Booklet draws it). Booklet 1.3.
     */
    public function blog(\Illuminate\Http\Request $request): mixed
    {
        return $this->page('blog', $request);
    }

    /** Ask the inserts that answer $hook for a page; the first answer wins. */
    protected function page(string $hook, \Illuminate\Http\Request $request): mixed
    {
        foreach ($this->active() as $insert) {
            $home = $insert->manifest($hook);
            if (! is_callable($home)) {
                continue;
            }
            try {
                $page = $home($insert, $request);
                if ($page instanceof \Illuminate\Contracts\View\View) {
                    $page = ViewState::protect(fn () => response($page->render()));
                }
                if ($page !== null && $page !== '' && $page !== false) {
                    return $page;
                }
            } catch (Throwable $e) {
                // Booklet's own page instead of an error page.
                report($e);
                $this->noteError($insert->key, $e);
            }
        }

        return null;
    }

    // Guarding forms -----------------------------------------------------------------------

    /** The public forms (and sign-in) an insert can guard: a CAPTCHA, a block list… */
    public const GUARDED_FORMS = ['comment', 'contact', 'subscribe', 'login', 'reset'];

    /**
     * Switched-on inserts guarding this form ('guard' in insert.php: which
     * 'forms', a 'widget' to show in them, and a 'check' that can refuse).
     *
     * @return Collection<string, Insert>
     */
    public function guards(string $form): Collection
    {
        return $this->active()->filter(function (Insert $insert) use ($form) {
            $guard = $insert->manifest('guard');
            if (! is_array($guard)) {
                return false;
            }
            $forms = $guard['forms'] ?? [];
            if (is_callable($forms)) {
                try {
                    $forms = (array) $forms($insert);
                } catch (Throwable $e) {
                    report($e);
                    $this->noteError($insert->key, $e);

                    return false;
                }
            }

            return in_array($form, (array) $forms, true);
        });
    }

    /** What the guarding inserts show in a form (a CAPTCHA box, say), as HTML. */
    public function guardWidget(string $form): string
    {
        $html = '';
        foreach ($this->guards($form) as $insert) {
            $widget = $insert->manifest('guard')['widget'] ?? null;
            if (! is_callable($widget)) {
                continue;
            }
            try {
                $html .= (string) $widget($form, $insert);
            } catch (Throwable $e) {
                report($e);
                $this->noteError($insert->key, $e);
            }
        }

        return $html;
    }

    /**
     * Ask the guarding inserts about something sent. The first that says no
     * gives the reason (shown to the person); null: it may go ahead. An insert
     * that breaks here never stops a form: its problem is noted on the shelf.
     *
     * @param  array<string, string>  $answers  what was typed (name, email, the message…)
     */
    public function guardCheck(string $form, array $answers, ?string $token = null): ?string
    {
        foreach ($this->guards($form) as $insert) {
            $check = $insert->manifest('guard')['check'] ?? null;
            if (! is_callable($check)) {
                continue;
            }
            try {
                $why = $check($form, $answers, request(), (string) $token, $insert);
            } catch (Throwable $e) {
                report($e);
                $this->noteError($insert->key, $e);

                continue;
            }
            if (is_string($why) && trim($why) !== '') {
                return trim($why);
            }
        }

        return null;
    }

    public function forget(): void
    {
        $this->inserts = null;
    }

    // Tucking them in ----------------------------------------------------------------

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        foreach ($this->active() as $insert) {
            try {
                if (is_dir($insert->path.'/views')) {
                    view()->addNamespace('insert-'.$insert->key, $insert->path.'/views');
                }
                if (is_callable($routes = $insert->manifest('routes'))) {
                    Route::middleware('web')->name('site.insert.'.$insert->key.'.')->group(fn ($router) => $routes($router, $insert));
                }
            } catch (Throwable $e) {
                $this->tearOut($insert->key, $e);
            }
        }
    }

    /** Read the shelf again and tuck in what's switched on now (as the next request would). */
    public function reboot(): void
    {
        $this->forget();
        $this->booted = false;
        $this->boot();
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /** Let inserts react to something that happened. A problem is noted, never passed on. */
    public function fire(string $event, mixed ...$args): void
    {
        // Webhooks hear these too (entries are told from their repository, so every way of saving counts).
        if (! str_starts_with($event, 'entry.')) {
            rescue(fn () => \App\Support\Webhooks::heard($event, $args[0] ?? null), null, true);
        }

        foreach ($this->active() as $insert) {
            $handler = ((array) $insert->manifest('when', []))[$event] ?? null; // event names have dots, so not a dotted lookup
            if (! is_callable($handler)) {
                continue;
            }
            try {
                $handler(...[...$args, $insert]);
            } catch (Throwable $e) {
                report($e);
                $this->noteError($insert->key, $e);
            }
        }
    }

    /**
     * Scheduled entries that have gone live since the last look also count as
     * published (run every few minutes by the scheduler).
     */
    public function fireScheduledGoneLive(): int
    {
        $key = 'booklet.inserts.live-check';
        $since = \Illuminate\Support\Carbon::createFromTimestamp((int) \Illuminate\Support\Facades\Cache::get($key, now()->subMinutes(10)->timestamp));
        \Illuminate\Support\Facades\Cache::forever($key, now()->timestamp);
        if ($this->active()->isEmpty()) {
            return 0;
        }

        $live = \App\Booklet\Facades\Booklet::entries()->all()
            ->filter(fn ($e) => $e->status() === 'published' && $e->date() && $e->date()->gt($since) && $e->date()->lte(now())
                && $e->lastModified() && \Illuminate\Support\Carbon::parse($e->lastModified())->lt($e->date())); // saved before its time: it was scheduled
        $live->each(fn ($e) => $this->fire('entry.published', $e));

        return $live->count();
    }

    /** Jobs inserts want run on their own ('every' => ['daily' => fn ($insert) => …]). */
    public function schedule(Schedule $schedule): void
    {
        foreach ($this->active() as $insert) {
            foreach ((array) $insert->manifest('every', []) as $frequency => $job) {
                if (! in_array($frequency, self::FREQUENCIES, true) || ! is_callable($job)) {
                    continue;
                }
                $schedule->call(function () use ($insert, $job) {
                    try {
                        $job($insert);
                    } catch (Throwable $e) {
                        report($e);
                        $this->noteError($insert->key, $e);
                    }
                })->{$frequency}()->name('insert-'.$insert->key.'-'.$frequency)->withoutOverlapping();
            }
        }
    }

    // Cards on the Contents page ----------------------------------------------------------

    /**
     * What switched-on inserts have to say on this person's Contents page.
     * A card with nothing to say (show() gives null or nothing) is left out,
     * and one that fails is left out too (and noted on the shelf).
     *
     * @return array<int, array{insert: Insert, key: string, title: string, count: ?int, text: ?string, items: array, link: ?array}>
     */
    public function cards(\App\Models\User $user): array
    {
        $safe = function (mixed $href): ?string {
            $href = is_string($href) ? trim($href) : '';

            return $href !== '' && (preg_match('#^https?://#i', $href) || (str_starts_with($href, '/') && ! str_starts_with($href, '//'))) ? $href : null;
        };

        $out = [];
        foreach ($this->active() as $insert) {
            $cards = rescue(fn () => $insert->cards(), [], false);
            foreach ($cards as $card) {
                // Each card on its own: one that fails is left out, the others still show.
                try {
                    if (! in_array($user->level(), $card['who'], true)) {
                        continue;
                    }
                    $said = ($card['show'])($insert, $user);
                    if (! is_array($said)) {
                        continue;
                    }
                    $items = [];
                    foreach (array_slice(array_values((array) ($said['items'] ?? [])), 0, 5) as $item) {
                        if (is_array($item) && trim((string) ($item['label'] ?? '')) !== '') {
                            $items[] = ['label' => Str::limit(trim((string) $item['label']), 90), 'meta' => Str::limit(trim((string) ($item['meta'] ?? '')), 90), 'href' => $safe($item['href'] ?? null)];
                        }
                    }
                    $text = trim((string) ($said['text'] ?? ''));
                    if ($items === [] && $text === '') {
                        continue; // nothing to say today
                    }
                    $link = is_array($said['link'] ?? null) && ($href = $safe($said['link']['href'] ?? null))
                        ? ['label' => Str::limit((string) ($said['link']['label'] ?? 'Open'), 40), 'href' => $href] : null;

                    $out[] = [
                        'insert' => $insert, 'key' => $insert->key.'-'.$card['name'], 'title' => Str::limit($card['title'], 60),
                        'count' => isset($said['count']) && is_numeric($said['count']) ? (int) $said['count'] : null,
                        'text' => $text !== '' ? Str::limit($text, 300) : null, 'items' => $items, 'link' => $link,
                    ];
                } catch (Throwable $e) {
                    report($e);
                    $this->noteError($insert->key, $e);
                }
            }
        }

        return $out;
    }

    // [[slips]] in writing ---------------------------------------------------------------

    /** @return array<string, array{insert: Insert, slip: callable}> */
    public function slips(): array
    {
        $slips = [];
        foreach ($this->active() as $insert) {
            foreach ($insert->slips() as $name => $slip) {
                $slips[$name] ??= ['insert' => $insert, 'slip' => $slip];
            }
        }

        return $slips;
    }

    /** Names used by inserts that are switched off, so their slips quietly disappear. */
    protected function silentSlips(): array
    {
        return $this->all()->reject(fn (Insert $i) => $this->active()->has($i->key))
            ->flatMap(fn (Insert $i) => $i->manifest('broken')
                ? (array) rescue(fn () => \Illuminate\Support\Facades\Cache::get('booklet.insert-slips.'.$i->key, []), [], false)
                : array_keys($i->slips()))
            ->all();
    }

    /**
     * Swap [[name: words]] in rendered text for what the insert makes. A slip
     * alone in its paragraph replaces the paragraph; unknown names are left
     * as they are (so a typo shows), and an insert's problem shows nothing.
     */
    public function renderSlips(string $html, array $context = []): string
    {
        if (! str_contains($html, '[[')) {
            return $html;
        }
        $slips = $this->slips();
        $silent = $this->silentSlips();

        $swap = function (array $m) use ($slips, $silent, $context) {
            $name = strtolower($m[1]);
            $arg = trim(html_entity_decode((string) ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5));
            if ($name === 'builder') {
                // A built page, in a post or page: Booklet's own slip.
                return (string) rescue(fn () => ViewState::protect(fn () => app(\App\PageBuilder\PageBuilder::class)->slip($arg)), '', true);
            }
            if (! isset($slips[$name])) {
                return in_array($name, $silent, true) ? '' : $m[0];
            }
            try {
                // If it fails half-way, the page around it keeps its place (see ViewState).
                $out = ViewState::protect(fn () => ($slips[$name]['slip'])($arg, $slips[$name]['insert'], $context));
                if ($out instanceof \Illuminate\Contracts\Support\Htmlable) {
                    $out = ViewState::protect(fn () => $out->toHtml());
                }

                return $out instanceof \Illuminate\Contracts\Support\Htmlable ? $out->toHtml() : (string) $out;
            } catch (Throwable $e) {
                report($e);
                $this->noteError($slips[$name]['insert']->key, $e);

                return '';
            }
        };

        $pattern = '\[\[\s*([a-z][a-z0-9_-]{0,40})\s*(?::\s*([^\]<]*?))?\s*\]\]';
        $html = preg_replace_callback('#<p>\s*'.$pattern.'\s*</p>#i', $swap, $html);

        return (string) preg_replace_callback('#'.$pattern.'#i', $swap, $html);
    }

    /** Plain text without slips (for excerpts, feeds' summaries and word counts). */
    public static function stripSlips(string $text): string
    {
        return (string) preg_replace('/\[\[\s*[a-z][a-z0-9_-]{0,40}\s*(?::[^\]]*)?\]\]/i', '', $text);
    }

    // On and off ---------------------------------------------------------------------------

    public function enable(string $key): void
    {
        $insert = $this->find($key) ?? throw new \InvalidArgumentException("No insert called {$key}.");
        if ($insert->manifest('broken')) {
            throw new \RuntimeException('This insert couldn’t be read, so it can’t be switched on. '.$insert->error());
        }
        if (! $insert->fits()) {
            throw new \RuntimeException($insert->name().' needs Booklet '.$insert->needs().' or newer.');
        }
        if ($insert->builtIn()) {
            throw new \RuntimeException($insert->name().' is part of Booklet now: '.self::BUILT_IN[$insert->key].'. This copy can go.');
        }

        // Its own tables, if it brings any.
        if (is_dir($insert->path.'/migrations')) {
            Artisan::call('migrate', ['--path' => $insert->path.'/migrations', '--realpath' => true, '--force' => true]);
        }

        $this->write($key, ['enabled' => true, 'version' => $insert->version(), 'torn_out_at' => null, 'error' => null, 'error_at' => null]);

        if (is_callable($hook = $insert->manifest('on'))) {
            $hook($insert);
        }
        $this->changed();
    }

    public function disable(string $key): void
    {
        $this->write($key, ['enabled' => false]);
        $this->changed();
    }

    /** Take it off the shelf's records; with $data, everything it kept goes too. */
    public function forgetInsert(string $key, bool $data): void
    {
        $insert = $this->find($key);
        if ($data) {
            if ($insert && is_callable($hook = $insert->manifest('remove'))) {
                rescue(fn () => $hook($insert), null, true);
            }
            DB::table('insert_records')->where('insert', $key)->delete();
        }
        DB::table('inserts')->where('key', $key)->delete();
        $this->changed();
    }

    public function saveSettings(string $key, array $values): void
    {
        // Secret settings are kept encrypted (with APP_KEY), never as they were typed.
        $insert = $this->find($key);
        foreach ($insert?->settingsSchema() ?? [] as $handle => $field) {
            if ($field['type'] === 'secret' && isset($values[$handle])) {
                $values[$handle] = $values[$handle] === '' ? '' : Insert::SECRET.\Illuminate\Support\Facades\Crypt::encryptString((string) $values[$handle]);
            }
        }
        $this->write($key, ['settings' => json_encode($values)]);
        $this->changed();
    }

    /** It broke while loading: keep it off until someone tries again. */
    public function tearOut(string $key, Throwable $e, bool $onlyIfOn = false): void
    {
        report($e);
        rescue(function () use ($key, $e, $onlyIfOn) {
            $row = DB::table('inserts')->where('key', $key)->first();
            if ($onlyIfOn && ! ($row->enabled ?? false)) {
                $this->write($key, ['error' => $this->describe($e), 'error_at' => now()]);

                return;
            }
            $this->write($key, ['torn_out_at' => now(), 'error' => $this->describe($e), 'error_at' => now()]);
        }, null, false);
    }

    public function noteError(string $key, Throwable $e): void
    {
        rescue(fn () => $this->write($key, ['error' => $this->describe($e), 'error_at' => now()]), null, false);
    }

    protected function describe(Throwable $e): string
    {
        $where = str_starts_with($e->getFile(), $this->path()) ? ' ('.Str::after($e->getFile(), $this->path().'/').' line '.$e->getLine().')' : '';

        return Str::limit(class_basename($e).': '.$e->getMessage().$where, 600);
    }

    /** Note something about an insert on the shelf's records (where it came from, say). */
    public function record(string $key, array $values): void
    {
        $this->write($key, $values);
        $this->changed();
    }

    protected function write(string $key, array $values): void
    {
        $exists = DB::table('inserts')->where('key', $key)->exists();
        $values['updated_at'] = now();
        if ($exists) {
            DB::table('inserts')->where('key', $key)->update($values);
        } else {
            DB::table('inserts')->insert($values + ['key' => $key, 'created_at' => now()]);
        }
        // Not find(): this can run while the shelf is still being read (an insert tearing out).
        $this->inserts?->get($key)?->forgetState();
    }

    protected function changed(): void
    {
        $this->inserts?->each->forgetState();
        \App\Support\PageCache::flush();
    }

    // For Stacy --------------------------------------------------------------------------------

    /**
     * Skills that switched-on inserts teach Stacy, by tool name ({insert}__{skill}).
     *
     * @return array<string, array{insert: Insert, skill: array}>
     */
    public function skills(): array
    {
        $skills = [];
        foreach ($this->active() as $insert) {
            try {
                foreach ($insert->skills() as $skill) {
                    $skills[$skill['tool']] = ['insert' => $insert, 'skill' => $skill];
                }
            } catch (Throwable $e) {
                $this->noteError($insert->key, $e);
            }
        }

        return $skills;
    }

    public function stacyNote(): string
    {
        $lines = $this->active()->map(function (Insert $i) {
            $slips = $i->slipHelp();

            return $i->name().': '.$i->about().($slips ? ' Writers can put '.implode(' or ', $slips).' in a post or page.' : '');
        })->values()->all();

        return $lines ? ' Inserts in this book (extras tucked in): '.implode(' ', $lines) : '';
    }
}
