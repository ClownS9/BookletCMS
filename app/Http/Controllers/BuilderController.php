<?php

namespace App\Http\Controllers;

use App\Booklet\Facades\Booklet;
use App\Models\PageBuild;
use App\PageBuilder\PageBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The editor on the page (public/booklet/builder-edit.js) talks to this:
 * what to draw, and doing things. A target is a page's or post's id, or
 * "blog". Only people who can build it get anywhere.
 */
class BuilderController extends Controller
{
    /** What only looks, or changes nothing on the page: never too late. */
    protected const LOOKING = ['select', 'tab', 'adding', 'pick', 'unpick', 'proof', 'proof-stop', 'proof-note', 'save-layout', 'forget-layout', 'forget-shared', 'alt', 'focus'];

    /** How long someone counts as still here after their last word from the page (seconds). */
    protected const HERE_FOR = 60;
    public function __construct(protected PageBuilder $pages) {}

    /** "Build this page": start building it (from its own words), then open it to build. */
    public function start(Request $request, string $target): JsonResponse
    {
        $entry = Booklet::entries()->find($target);
        abort_unless($entry && $this->pages->canBuild($request->user(), $entry), 404);
        $this->pages->start($entry);

        return response()->json(['go' => $this->pages->editUrl($entry)]);
    }

    public function edit(Request $request, string $target): JsonResponse
    {
        $page = $this->find($request, $target);
        $state = $this->pages->stateFrom((array) $request->query());
        $state['section'] ??= $this->pages->sectionsOf($page)[0]['id'] ?? null;

        return $this->respond($page, $state);
    }

    public function act(Request $request, string $target): JsonResponse
    {
        $page = $this->find($request, $target);
        $action = (string) $request->input('action');
        abort_unless($this->pages->can($action), 404);

        $state = $this->pages->stateFrom((array) $request->input('state', []));

        // Changed since this editor last saw it (by someone else, or in another window): not
        // done over it; the page is brought up to date instead, to make the change again.
        if ($request->filled('rev') && (int) $request->input('rev') !== (int) $page->rev && ! in_array($action, self::LOOKING, true)) {
            $by = $page->edited_by === $request->user()?->id ? 'You changed it in another window'
                : ((string) (\App\Models\User::query()->whereKey($page->edited_by)->value('name') ?: 'Someone else')).' changed this page';

            return $this->respond($page, $state, $by.' a moment ago, so it’s brought up to date here. Make your change again.', false, [], ['stale' => true])->setStatusCode(409);
        }

        try {
            $result = $this->pages->act($page, $action, $state, $request->input('payload'));
        } catch (ValidationException $e) {
            $errors = collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_replace('form.', '', $k) => $m[0] ?? ''])->all();

            return $this->respond($page, $state, null, true, $errors)->setStatusCode(422);
        } catch (Throwable $e) {
            report($e);

            return $this->respond($page, $state, 'That didn’t work. Try again, or reload the page.', true)->setStatusCode(500);
        }

        if (is_array($result) && ! empty($result['gone'])) {
            // Done here (drawn as before): off to where it is now.
            return response()->json(['gone' => $result['gone'], 'stamp' => $result['stamp'] ?? null]);
        }
        $stamp = is_string($result) ? $result : (is_array($result) ? ($result['stamp'] ?? null) : null);
        if (is_array($result) && is_array($result['form'] ?? null)) {
            $state = $this->pages->stateFrom($result['form']);
        }
        $extra = is_array($result) && ! empty($result['moved']) ? ['moved' => url($result['moved'])] : [];
        if (is_array($result) && ! empty($result['copy'])) {
            $extra['copy'] = $result['copy']; // a link just made: offered to copy
        }

        return $this->respond($page, $state, $stamp, $request->boolean('quiet'), [], $extra);
    }

    /**
     * Still here (every 20 seconds while building): who else is building
     * this page, and which change it's at. Leaving says so too.
     */
    public function ping(Request $request, string $target): JsonResponse
    {
        $page = $this->find($request, $target);
        if ($request->boolean('leaving')) {
            $this->here($page, false);

            return response()->json(['ok' => true]);
        }

        return response()->json(['rev' => (int) $page->rev, 'others' => $this->here($page)])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Note that this person is building the page (or has left it), and
     * say who else is.
     *
     * @return list<string>
     */
    protected function here(PageBuild $page, bool $here = true): array
    {
        $user = request()->user();
        $key = 'booklet.builder.here.'.$page->target;
        $now = now()->timestamp;
        $all = array_filter((array) \Illuminate\Support\Facades\Cache::get($key, []), fn ($p) => is_array($p) && ($p['at'] ?? 0) > $now - self::HERE_FOR);
        if ($user) {
            if ($here) {
                $all[$user->id] = ['name' => (string) $user->name, 'at' => $now];
            } else {
                unset($all[$user->id]);
            }
        }
        \Illuminate\Support\Facades\Cache::put($key, $all, self::HERE_FOR * 2);

        return array_values(array_map(fn ($p) => (string) $p['name'], array_filter($all, fn ($p, $id) => $id !== $user?->id, ARRAY_FILTER_USE_BOTH)));
    }

    /** The draft as a phone shows it (the editor's Phone view): the page itself, with ?preview=1. */
    public function preview(Request $request, string $target)
    {
        $page = $this->find($request, $target);
        $query = http_build_query(['preview' => 1] + array_filter($request->only('category', 'page', 'version')));
        if ($page->isBlog()) {
            return redirect()->to(app(\App\Booklet\Site::class)->blogUrl().'?'.$query);
        }

        return redirect()->to(route('preview', $page->target).'?'.$query);
    }

    protected function find(Request $request, string $target): PageBuild
    {
        $this->pages->settle();
        if ($target === PageBuild::BLOG) {
            abort_unless($this->pages->canBuild($request->user(), null), 404);

            return $this->pages->blogForEditing();
        }
        $entry = Booklet::entries()->find($target);
        abort_unless($entry && $this->pages->canBuild($request->user(), $entry), 404);
        $page = PageBuild::forEntry($entry);
        abort_unless($page !== null, 404);

        return $page;
    }

    /** Everything the editor on the page needs to draw itself again. */
    protected function respond(PageBuild $page, array $state, ?string $stamp = null, bool $quiet = false, array $errors = [], array $extra = []): JsonResponse
    {
        $pages = $this->pages;
        $page->refresh();
        $sections = $pages->sectionsOf($page);
        if ($state['section'] !== null && ! collect($sections)->contains('id', $state['section'])) {
            $state['section'] = null;
        }
        $selected = collect($sections)->firstWhere('id', $state['section']);
        [$where, $words] = $pages->status($page);
        $entry = $page->entry();
        $live = $pages->onSite($page);
        $types = $pages->builder->types();

        // The page's sections as they are now, ready to type into (left out while typing: they're on the page already).
        $html = null;
        if (! $quiet) {
            $context = [];
            if ($page->isBlog()) {
                // The blog: the same posts, and page of them, the editor is looking at.
                parse_str((string) request()->input('query', request()->query('query', '')), $q);
                $context = $pages->blogContext(Request::create('/', 'GET', array_intersect_key($q, ['category' => 1, 'page' => 1])), $sections, true);
            }
            $html = view('builder.sections', $context + ['sections' => $sections, 'types' => $types, 'live' => true, 'embedded' => false]
                + ($pages->isPost($page) ? ['h1' => false] : []))->render();
        }

        return response()->json([
            'stamp' => $stamp,
            'errors' => $errors,
            'state' => $state,
            'rev' => (int) $page->rev,
            'bar' => [
                'others' => $this->here($page),
                'name' => $page->isBlog() ? 'The blog page' : (string) $entry?->title(),
                'status' => $where,
                'words' => $words,
                'undoable' => (array) $page->history !== [],
                'address' => $pages->addressOf($page),
                'blog' => $page->isBlog(),
                'live' => $live,
            ],
            'drawer' => view('builder.drawer', [
                'page' => $page, 'entry' => $entry, 'sections' => $sections, 'selected' => $selected, 'state' => $state,
                'types' => $pages->typesFor($page), 'looks' => $pages->builder->looks(), 'builder' => $pages->builder, 'errors' => $errors,
                'status' => [$where, $words], 'address' => $pages->addressOf($page), 'isBlog' => $page->isBlog(),
                'live' => $live, 'home' => $pages->isHome($entry),
                'changed' => $live && $page->published !== null && $sections !== $pages->sectionsOf($page, false),
                'scheduled' => $page->scheduled_at ? [
                    'at' => $page->scheduled_at->toIso8601String(), 'when' => $pages->when($page->scheduled_at),
                    'stale' => (array) ($page->scheduled['sections'] ?? []) !== $sections,
                ] : null,
                'categories' => $selected && $selected['type'] === 'posts' ? $pages->categories() : [],
                'history' => $state['tab'] === 'page' ? $pages->history($page) : [],
                'checks' => $state['tab'] === 'page' ? $pages->checks($page) : [],
                'proofing' => $state['tab'] === 'page' ? $pages->proofing($page) : null,
                'layouts' => $state['tab'] === 'page' ? $pages->layouts($page) : null,
                'shared' => $state['adding'] ? $pages->sharedFor($page) : [],
                'sharedNow' => $selected && isset($selected['shared']) ? [
                    'name' => (string) ($pages->sharedAll()[$selected['shared']]->name ?? ''), 'uses' => $pages->usesOf($selected['shared']),
                ] : null,
                'canShare' => $selected && ! isset($selected['shared']) && $pages->shareable($selected['type']),
                'stacy' => $selected && request()->user() && \App\Stacy\Writer::available(request()->user()),
                'canMedia' => (bool) request()->user()?->canChangeMedia(),
                'canHome' => (bool) request()->user()?->isAdmin() && $entry && ! $entry->collection()?->dated(),
                'pictures' => $state['picking'] ? Booklet::assets()->inContainer('media')->filter(fn ($a) => $a->isImage())
                    ->mapWithKeys(fn ($a) => [$a->path() => [
                        'thumb' => \App\Support\Pictures::srcset($a->path()) !== '' ? \App\Support\Pictures::url($a->path(), 320) : \App\Support\Disk::of('public')->url($a->path()),
                        'alt' => (string) $a->get('alt', ''),
                    ]])->all() : [],
            ])->render(),
            'page' => $html,
        ] + $extra)->header('Cache-Control', 'no-store, private');
    }
}
