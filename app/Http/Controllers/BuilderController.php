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

        return $this->respond($page, $state, $stamp, $request->boolean('quiet'), [], $extra);
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
            'bar' => [
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
