<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Asset;
use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Booklet\Support\MenuOutline;
use App\Booklet\Support\MenuWords;
use App\Booklet\Support\Menus as MenuStore;
use App\Booklet\Support\PageTree;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\Disk;
use App\Support\MenuHealth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Site menus: what's in the header and footer, in which order, and what
 * drops down from what. One list: drag a row by its grip (further right
 * to tuck it under the row above), or use the grip's arrow keys. "Edit"
 * opens a row's drawer: its label and link, following the page tree, and
 * for a top item, a wide panel (mega menu). A live preview shows the real
 * site wearing the unsaved menu.
 *
 * Also: what's wrong with the menu (MenuHealth), arranging it in plain
 * words (MenuWords, no AI needed), and seasonal versions that stand in for
 * a menu between two dates (a Raya menu).
 */
#[Layout('components.layouts.admin')]
#[Title('Site menus')]
class Menus extends Component
{
    use Stamps;

    /** How many steps "Undo" can take back. */
    public const UNDO_STEPS = 20;

    #[Url(as: 'menu', except: 'header')]
    public string $menu = 'header';

    /** The menu as an outline: [['key', 'depth', 'entry' | 'url', 'title'?, 'follow'?], …] (see MenuOutline). */
    public array $rows = [];

    /** Earlier outlines, newest last. */
    public array $history = [];

    public string $linkUrl = '';

    public string $linkLabel = '';

    public bool $dirty = false;

    /** The row whose drawer is open (its key). */
    public ?string $open = null;

    /** "Put Shop first and tuck Prints under it." */
    public string $words = '';

    // A new seasonal version
    public bool $addingSeason = false;

    public string $seasonName = '';

    public string $seasonFrom = '';

    public string $seasonUntil = '';

    public function mount(): void
    {
        abort_unless(request()->user()->arranges(), 403);

        if (! array_key_exists($this->menu, MenuStore::allWithSeasons())) {
            $this->menu = 'header';
        }

        $this->load();
    }

    protected function load(): void
    {
        $this->rows = MenuOutline::fromTree(MenuStore::items($this->menu));
        $this->history = [];
        $this->dirty = false;
        Cache::forget($this->previewKey());
    }

    public function switchTo(string $menu): void
    {
        abort_unless(array_key_exists($menu, MenuStore::allWithSeasons()), 404);

        $this->menu = $menu;
        $this->open = null;
        $this->addingSeason = false;
        $this->load();
        $this->dispatch('menu-preview', foot: $this->forHandle() !== 'header');
    }

    /** The menu this one is (or stands in for): "header" for a Raya version of the header. */
    protected function forHandle(): string
    {
        return MenuStore::season($this->menu)['for'] ?? $this->menu;
    }

    // In plain words -----------------------------------------------------------

    public function arrange(): void
    {
        $this->resetErrorBag('words');
        if (trim($this->words) === '') {
            return;
        }
        $result = MenuWords::apply($this->rows, mb_substr($this->words, 0, 500), fn (array $row) => MenuHealth::label($row), fn (string $title) => $this->pageCalled($title));

        if ($result['done'] !== []) {
            $this->change($result['rows']);
            $this->stamp(implode('. ', $result['done']).'.'.($result['problems'] === [] ? ' (Undo takes it back.)' : ''));
            if ($result['problems'] === []) {
                $this->words = '';
            }
        }
        if ($result['problems'] !== []) {
            $this->addError('words', implode(' ', $result['problems']));
        }
    }

    /** A page (in a structured collection) by its title, for "add the Our team page". */
    protected function pageCalled(string $title): ?string
    {
        $title = mb_strtolower(trim($title));

        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => (bool) Booklet::collections()->find($e->collectionHandle())?->structured())
            ->first(fn (Entry $e) => mb_strtolower((string) $e->title()) === $title)?->id();
    }

    // Seasonal versions ----------------------------------------------------------

    public function addSeason(): void
    {
        abort_if(MenuStore::season($this->menu) !== null, 403); // a season of a season: no
        $this->validate([
            'seasonName' => ['required', 'string', 'max:60'],
            'seasonFrom' => ['required', 'date_format:Y-m-d'],
            'seasonUntil' => ['required', 'date_format:Y-m-d', 'after_or_equal:seasonFrom'],
        ], [
            'seasonUntil.after_or_equal' => 'It has to end on or after the day it starts.',
        ], ['seasonName' => 'name', 'seasonFrom' => 'first day', 'seasonUntil' => 'last day']);

        if ($this->dirty) {
            $this->save(); // the seasonal copy starts from what's here now
        }
        $handle = MenuStore::addSeason($this->menu, $this->seasonName, $this->seasonFrom, $this->seasonUntil);
        ActivityLog::record('made a seasonal menu', 'settings', 'navigation', $handle, trim($this->seasonName));
        $this->reset('seasonName', 'seasonFrom', 'seasonUntil', 'addingSeason');
        $this->switchTo($handle);
        $this->stamp('Made a copy to change for the season. It takes over by itself on its first day, and steps back after its last.');
    }

    public function saveSeasonDates(string $from, string $until): void
    {
        $season = MenuStore::season($this->menu);
        abort_unless($season !== null, 404);
        validator(['from' => $from, 'until' => $until], [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['until.after_or_equal' => 'It has to end on or after the day it starts.'])->validate();

        MenuStore::setSeasonDates($this->menu, $from, $until);
        \App\Support\PageCache::flush();
        $this->stamp('Dates saved.');
    }

    public function deleteSeason(): void
    {
        $season = MenuStore::season($this->menu);
        abort_unless($season !== null, 404);
        MenuStore::deleteSeason($this->menu);
        ActivityLog::record('threw away the seasonal menu', 'settings', 'navigation', $this->menu, $season['title']);
        \App\Support\PageCache::flush();
        $this->switchTo($season['for']);
        $this->stamp('Thrown away. “'.$season['title'].'” won’t show again.');
    }

    // Adding -----------------------------------------------------------------

    public function addEntry(string $id): void
    {
        abort_unless(Booklet::entries()->find($id) !== null, 404);

        $this->change([...$this->rows, ['depth' => 0, 'entry' => $id]]);
    }

    public function addBlog(): void
    {
        $this->change([...$this->rows, ['depth' => 0, 'url' => $this->blogPath(), 'title' => 'Blog']]);
    }

    public function addLink(): void
    {
        $this->validate([
            'linkUrl' => ['required', 'string', 'max:500', 'regex:/^(https?:\/\/|\/|mailto:|tel:)/i'],
            'linkLabel' => ['required', 'string', 'max:80'],
        ], [
            'linkUrl.regex' => 'Start with https://, / for a page on this site, mailto: or tel:.',
        ], ['linkUrl' => 'link', 'linkLabel' => 'label']);

        $this->change([...$this->rows, ['depth' => 0, 'url' => trim($this->linkUrl), 'title' => trim($this->linkLabel)]]);
        $this->reset('linkUrl', 'linkLabel');
    }

    // Arranging --------------------------------------------------------------

    /** A drop: put row $from (and whatever hangs under it) before row $before, at $depth. */
    public function drop(int $from, int $before, int $depth): void
    {
        $this->change(MenuOutline::place($this->rows, $from, $before, $depth));
    }

    public function up(int $i): void
    {
        $this->change(MenuOutline::up($this->rows, $i));
    }

    public function down(int $i): void
    {
        $this->change(MenuOutline::down($this->rows, $i));
    }

    /** Hang it under the item before it. */
    public function nest(int $i): void
    {
        $this->change(MenuOutline::indent($this->rows, $i));
    }

    /** Bring it up a level. */
    public function lift(int $i): void
    {
        $this->change(MenuOutline::outdent($this->rows, $i));
    }

    public function remove(int $i): void
    {
        if (($this->rows[$i]['key'] ?? null) === $this->open) {
            $this->open = null;
        }
        $this->change(MenuOutline::remove($this->rows, $i));
    }

    public function rename(int $i, string $title): void
    {
        if (! isset($this->rows[$i])) {
            return;
        }

        $rows = $this->rows;
        $title = trim(mb_substr($title, 0, 80));
        if ($title === '' && isset($rows[$i]['entry'])) {
            unset($rows[$i]['title']);
        } elseif ($title !== '') {
            $rows[$i]['title'] = $title;
        }
        $this->change($rows);
    }

    /** "Follow the page tree": the page's sub-pages fill in under it on their own. */
    public function toggleFollow(int $i): void
    {
        if (! isset($this->rows[$i]['entry'])) {
            return;
        }

        $rows = $this->rows;
        if (empty($rows[$i]['follow'])) {
            $rows[$i]['follow'] = true;
        } else {
            unset($rows[$i]['follow']);
        }
        $this->change($rows);
    }

    public function edit(string $key): void
    {
        $this->open = $this->open === $key ? null : $key;
    }

    /** One of a row's options, from its drawer. */
    public function setOption(int $i, string $option, mixed $value): void
    {
        if (! isset($this->rows[$i])) {
            return;
        }
        $rows = $this->rows;
        $row = &$rows[$i];

        switch ($option) {
            case 'url':
                $url = trim((string) $value);
                if (isset($row['entry']) || ! preg_match('/^(https?:\/\/|\/|mailto:|tel:)/i', $url) || mb_strlen($url) > 500) {
                    $this->addError('rows.'.$i.'.url', 'Start with https://, / for a page on this site, mailto: or tel:.');

                    return;
                }
                $row['url'] = $url;
                break;
            case 'follow':
                $row['follow'] = isset($row['entry']) && (bool) $value;
                break;
            case 'mega':
                // Only a top item opens a wide panel.
                $row['mega'] = (int) $row['depth'] === 0 && (bool) $value;
                break;
            case 'blurb':
                $row['blurb'] = trim(mb_substr(strip_tags((string) $value), 0, 240));
                break;
            case 'picture':
                $path = (string) $value;
                $row['picture'] = $path === '' || Booklet::assets()->find(['container' => 'media', 'path' => $path]) ? $path : ($row['picture'] ?? '');
                break;
            case 'latest':
                $row['latest'] = (bool) $value;
                break;
            default:
                return;
        }
        unset($row);

        $this->resetErrorBag('rows.'.$i.'.url');
        $this->change($rows);
    }

    public function undo(): void
    {
        if ($this->history === []) {
            return;
        }

        $this->rows = array_pop($this->history);
        $this->dirty = true;
        $this->preview();
    }

    protected function change(array $rows): void
    {
        $rows = MenuOutline::normalize($rows);
        if ($rows === $this->rows) {
            return;
        }

        $this->history[] = $this->rows;
        $this->history = array_slice($this->history, -self::UNDO_STEPS);
        $this->rows = $rows;
        $this->dirty = true;
        $this->preview();
    }

    // Preview and saving -----------------------------------------------------

    protected function previewKey(): string
    {
        return Site::menuPreviewKey(request()->user()?->getAuthIdentifier() ?? auth()->id() ?? 0);
    }

    /** Keep the unsaved menu where the site's preview can find it, and tell the preview to reload. */
    protected function preview(): void
    {
        // A seasonal version is previewed where it would show: in place of the menu it stands in for.
        Cache::put($this->previewKey(), [$this->forHandle() => MenuOutline::toTree($this->rows)], now()->addHours(2));
        $this->dispatch('menu-preview', foot: $this->forHandle() !== 'header');
    }

    public function save(): void
    {
        MenuStore::save($this->menu, MenuOutline::toTree($this->rows));
        Cache::forget($this->previewKey());

        ActivityLog::record('rearranged the', 'settings', 'navigation', $this->menu, MenuStore::allWithSeasons()[$this->menu] ?? $this->menu);
        $this->dirty = false;
        $this->history = [];
        $season = MenuStore::season($this->menu);
        $warnings = MenuHealth::warnings($this->rows, $this->forHandle());
        $this->stamp(match ($season ? MenuStore::seasonState($season) : 'main') {
            'coming' => 'Saved. It takes over on '.\Illuminate\Support\Carbon::parse($season['from'])->format('j F').'.',
            'over' => 'Saved. Its dates are past, so the site doesn’t show it.',
            default => 'Menu saved. The site shows it now.',
        }.($warnings ? ' ('.$warnings.' '.\Illuminate\Support\Str::plural('thing', $warnings).' to look at, marked in the list.)' : ''));
        $this->dispatch('menu-preview', foot: $this->forHandle() !== 'header');
    }

    /** The newest pictures in Media, for choosing one for a wide panel. */
    protected function pictures(): array
    {
        $disk = Disk::of(Booklet::assetContainers()->find('media')?->attribute('disk') ?? 'public');

        return Booklet::assets()->inContainer('media')
            ->filter(fn (Asset $a) => $a->isImage())
            ->sortByDesc(fn (Asset $a) => $a->get('last_modified'))
            ->take(12)
            ->map(fn (Asset $a) => ['path' => $a->path(), 'url' => $disk->url($a->path()), 'alt' => (string) $a->get('alt', '')])
            ->values()
            ->all();
    }

    protected function blogPath(): string
    {
        return parse_url(app(Site::class)->blogUrl(), PHP_URL_PATH) ?: '/blog';
    }

    public function render()
    {
        $site = app(Site::class);
        $pages = Booklet::entries()->all()
            ->filter(fn (Entry $e) => (bool) Booklet::collections()->find($e->collectionHandle())?->structured())
            ->keyBy(fn (Entry $e) => $e->id());

        $used = collect($this->rows)->pluck('entry')->filter()->all();
        $trees = [];

        $rows = [];
        foreach ($this->rows as $i => $row) {
            $entry = isset($row['entry']) ? ($pages->get($row['entry']) ?? Booklet::entries()->find($row['entry'])) : null;
            $label = $row['title'] ?? ($entry ? ($entry->title() ?: 'Untitled') : (isset($row['entry']) ? 'A missing page' : $row['url']));

            // Pages that have pages under them (in Pages) can follow that tree.
            $subs = [];
            if ($entry && $pages->has($entry->id())) {
                $collection = $entry->collectionHandle();
                $trees[$collection] ??= collect(PageTree::for($collection)->flatten())->groupBy('parent');
                $subs = collect($trees[$collection]->get($entry->id(), []))->pluck('id')
                    ->map(fn ($id) => $pages->get($id))->filter()->values();
            }

            $rows[] = [
                'index' => $i,
                'key' => $row['key'],
                'depth' => (int) $row['depth'],
                'end' => MenuOutline::blockEnd($this->rows, $i),
                'label' => $label,
                'kind' => isset($row['entry']) ? 'Page' : 'Link',
                'detail' => $entry ? $entry->uri() : ($row['url'] ?? ''),
                'hidden' => isset($row['entry']) && $entry?->status() !== 'published',
                'follow' => ! empty($row['follow']),
                'canFollow' => count($subs) > 0 && (int) $row['depth'] < MenuOutline::MAX_DEPTH,
                'followed' => ! empty($row['follow']) ? collect($subs)->map(fn (Entry $e) => [
                    'label' => $e->title() ?: 'Untitled',
                    'hidden' => $e->status() !== 'published',
                ])->all() : [],
                'mega' => ! empty($row['mega']) && (int) $row['depth'] === 0,
                'blurb' => (string) ($row['blurb'] ?? ''),
                'picture' => (string) ($row['picture'] ?? ''),
                'latest' => ! empty($row['latest']),
                'url' => $row['url'] ?? null,
                'children' => MenuOutline::blockEnd($this->rows, $i) - $i - 1,
                'first' => MenuOutline::isFirst($this->rows, $i),
                'last' => MenuOutline::isLast($this->rows, $i),
            ];
        }

        $previewUrl = route('site.home', ['menu_preview' => 1]);

        // Pictures for a wide panel, only while one's drawer is open.
        $openRow = collect($this->rows)->firstWhere('key', $this->open);
        $pictures = $openRow && ! empty($openRow['mega']) ? $this->pictures() : [];

        // What's wrong with it, row by row.
        $health = MenuHealth::check($this->rows, $this->forHandle());
        foreach ($rows as $n => $row) {
            $rows[$n]['problems'] = $health['rows'][$row['index']] ?? [];
        }

        $season = MenuStore::season($this->menu);

        return view('livewire.admin.menus', [
            'menus' => MenuStore::all(),
            'items' => $rows,
            'menuProblems' => $health['menu'],
            'warnCount' => collect($health['menu'])->where('level', 'warn')->count() + collect($rows)->sum(fn ($r) => collect($r['problems'])->where('level', 'warn')->count()),
            'season' => $season ? $season + ['state' => MenuStore::seasonState($season)] : null,
            'seasons' => collect(MenuStore::seasons($this->forHandle()))->map(fn ($s) => $s + ['state' => MenuStore::seasonState($s)])->all(),
            'forTitle' => MenuStore::all()[$this->forHandle()] ?? $this->forHandle(),
            'addable' => $pages->reject(fn (Entry $e) => in_array($e->id(), $used, true))->values(),
            'hasBlog' => collect($this->rows)->contains(fn ($row) => ($row['url'] ?? null) === $this->blogPath()),
            'previewUrl' => $previewUrl,
            // Menus other than the header show at the foot of the page: the preview scrolls there.
            'previewFoot' => $this->forHandle() !== 'header',
            'maxDepth' => MenuOutline::MAX_DEPTH,
            'tones' => ['var(--coral)', 'var(--sun)', 'var(--mint)', 'var(--sky)', 'var(--lilac)', 'var(--rose)'],
            'pictures' => $pictures,
        ]);
    }
}
