<?php

namespace App\Http\Controllers;

use App\Booklet\Data\Entry;
use App\Booklet\Site;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The public site. Like Statamic, entries and terms are found by their
 * address (uri), so any collection route works without new Laravel routes.
 */
class SiteController extends Controller
{
    /** The site as it is for this request (never a copy kept from an earlier one). */
    protected function site(): Site
    {
        return app(Site::class);
    }

    public function home(Request $request)
    {
        // A home page an insert makes (a page from the page builder, say).
        if ($page = app(\App\Inserts\InsertManager::class)->home($request)) {
            return $page;
        }
        if ($page = $this->site()->homepage()) {
            return $this->page($page);
        }

        return $this->blog($request);
    }

    public function blog(Request $request)
    {
        // The blog page as an insert arranges it (the page builder, say).
        if ($page = app(\App\Inserts\InsertManager::class)->blog($request)) {
            return $page;
        }
        $category = (string) $request->query('category', '');
        $posts = $this->site()->posts($category !== '' ? 'categories' : null, $category ?: null);

        $paginator = $this->paginate($posts, $request);
        // The cover's home layout: one big featured story, or every post the same.
        $featured = $paginator->currentPage() === 1 && $category === '' && \App\Support\Look::current()['home'] === 'feature' ? $posts->first() : null;

        return view('site.blog', [
            'site' => $this->site(),
            'featured' => $featured,
            'posts' => $featured ? $paginator->getCollection()->reject(fn (Entry $e) => $e->id() === $featured->id())->values() : $paginator->getCollection(),
            'paginator' => $paginator,
            'category' => $category,
            'categories' => \App\Booklet\Facades\Booklet::terms()->whereTaxonomy('categories'),
        ]);
    }

    public function search(Request $request)
    {
        // Published as plain files: search runs in the reader's browser instead.
        if ($request->attributes->get('booklet_static')) {
            return view('site.static-search', ['site' => $this->site()]);
        }

        $q = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', 'all');
        $needle = Str::lower($q);

        $results = $q === '' ? collect() : $this->site()->published()
            ->filter(fn (Entry $e) => $type === 'all'
                || ($type === 'posts' && $e->collection()?->dated())
                || ($type === 'pages' && ! $e->collection()?->dated()))
            ->filter(fn (Entry $e) => Str::contains(Str::lower($e->title().' '.strip_tags((string) $e->get('content', '')).' '.\App\Support\Blocks::textOf($e)), $needle))
            ->sortByDesc(fn (Entry $e) => Str::contains(Str::lower($e->title()), $needle))
            ->values();

        return view('site.search', [
            'site' => $this->site(),
            'q' => $q,
            'type' => $type,
            'results' => $results->take(50),
            'needle' => $needle,
        ]);
    }

    /**
     * Everything else: an entry or a term found by its address, or a 404.
     */
    public function show(Request $request)
    {
        $uri = '/'.trim($request->path(), '/');

        if ($entry = $this->site()->findByUri($uri)) {
            // The homepage entry lives at "/", not at its own address.
            if ($this->site()->homepage()?->id() === $entry->id()) {
                return redirect('/', 301);
            }

            return $entry->collection()?->dated() ? $this->post($entry) : $this->page($entry);
        }

        if ($term = $this->site()->findTermByUri($uri)) {
            $posts = $this->site()->posts($term->taxonomy(), $term->slug());

            return view('site.archive', [
                'site' => $this->site(),
                'term' => $term,
                'taxonomy' => \App\Booklet\Facades\Booklet::taxonomies()->find($term->taxonomy()),
                'paginator' => $this->paginate($posts, $request),
                'total' => $posts->count(),
            ]);
        }

        // The blog index lives where the dated collection's addresses start, e.g. /blog.
        $blogPath = $this->site()->blogPath();

        if ($uri !== '/' && $uri === $blogPath) {
            return $this->blog($request);
        }

        // An old address that moved: forward readers (and search engines) to the new one.
        if ($redirect = \App\Support\Redirects::for($request)) {
            return redirect()->to(\App\Support\Redirects::target($redirect, $request), $redirect->status === 302 ? 302 : 301);
        }

        abort(404);
    }

    /**
     * Signed-in people can see any entry, drafts included, before it's published.
     */
    public function preview(Request $request, string $entry)
    {
        $model = \App\Booklet\Facades\Booklet::entries()->find($entry);
        abort_unless($model !== null, 404);

        $request->attributes->set('booklet_preview', $model->status());

        return $model->collection()?->dated() ? $this->post($model) : $this->page($model);
    }

    /** An entry shown as the site would show it (a post or a page), for proof copies. */
    public function entry(Entry $entry)
    {
        return $entry->collection()?->dated() ? $this->post($entry) : $this->page($entry);
    }

    /** Which language this page is in, and where its other languages are (for the page's head). */
    protected function languages(Entry $entry): void
    {
        request()->attributes->set('booklet_lang', $this->site()->languageOf($entry));
        request()->attributes->set('booklet_alternates', $this->site()->languages($entry)->all());
    }

    protected function post(Entry $entry)
    {
        request()->attributes->set('booklet_entry', $entry->id()); // for the visit stats
        $this->languages($entry);
        // A translation sits where its original sits in the list of posts.
        $original = $this->site()->isTranslation($entry)
            ? (\App\Booklet\Facades\Booklet::entries()->find((string) $entry->get('translation_of')) ?? $entry)
            : $entry;
        $posts = $this->site()->posts();
        $index = $posts->search(fn (Entry $e) => $e->id() === $original->id());

        $category = $this->site()->terms($entry, 'categories')->first();
        $related = $posts
            ->reject(fn (Entry $e) => $e->id() === $original->id())
            ->sortByDesc(fn (Entry $e) => $category && in_array($category->slug(), (array) $e->get('categories', []), true))
            ->take(3)
            ->values();

        return view('site.post', [
            'site' => $this->site(),
            'entry' => $entry,
            'author' => $this->site()->author($entry),
            'category' => $category,
            'tags' => $this->site()->terms($entry, 'tags'),
            'newer' => $index !== false && $index > 0 ? $posts[$index - 1] : null,
            'older' => $index !== false ? $posts->get($index + 1) : null,
            'related' => $related,
        ]);
    }

    protected function page(Entry $entry)
    {
        request()->attributes->set('booklet_entry', $entry->id()); // for the visit stats
        $this->languages($entry);
        return view('site.page', [
            'site' => $this->site(),
            'entry' => $entry,
            'template' => (string) $entry->get('template', 'default'),
        ]);
    }

    protected function paginate(Collection $items, Request $request): LengthAwarePaginator
    {
        $perPage = $this->site()->perPage();
        $page = max(1, (int) $request->query('page', '1'));

        return (new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(), 'query' => $request->query()],
        ));
    }
}
