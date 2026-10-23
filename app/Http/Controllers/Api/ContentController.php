<?php

namespace App\Http\Controllers\Api;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Support\ContentApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The read-only content API: /api/v1/… Published content only, as JSON.
 * The gate (keys, origins, pace) is ContentApiAccess.
 */
class ContentController
{
    protected function site(): Site
    {
        return app(Site::class);
    }

    protected function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function missing(string $what): JsonResponse
    {
        return $this->json(['error' => $what], 404);
    }

    /** What's here, so someone exploring can follow the links. */
    public function index(): JsonResponse
    {
        $base = ContentApi::base();

        return $this->json([
            'name' => $this->site()->name(),
            'version' => 1,
            'endpoints' => [
                'site' => $base.'/site',
                'collections' => $base.'/collections',
                'entries' => $base.'/collections/{collection}/entries',
                'entry' => $base.'/entries/{id}',
                'find' => $base.'/find?uri=/about',
                'menus' => $base.'/menus/{handle}',
                'terms' => $base.'/taxonomies/{taxonomy}/terms',
            ],
        ]);
    }

    public function about(): JsonResponse
    {
        $site = $this->site();
        $home = $site->homepage();

        return $this->json(['data' => [
            'name' => $site->name(),
            'tagline' => $site->tagline(),
            'url' => $site->absolute(url('/')),
            'blog_url' => $site->absolute($site->blogUrl()),
            'language' => $site->language(),
            'timezone' => config('app.timezone'),
            'accent' => $site->accent(),
            'logo' => ($logo = $site->logoUrl()) ? ContentApi::full($logo) : null,
            'homepage' => $home ? (string) $home->id() : null,
            'menus' => array_keys(Menus::all()),
        ]]);
    }

    public function collections(): JsonResponse
    {
        $published = $this->site()->published()->countBy(fn (Entry $e) => $e->collectionHandle());

        return $this->json(['data' => Booklet::collections()->all()->map(fn ($c) => [
            'handle' => $c->handle(),
            'title' => $c->title(),
            'dated' => $c->dated(),
            'structured' => $c->structured(),
            'taxonomies' => array_values((array) $c->taxonomies()),
            'entries' => (int) ($published[$c->handle()] ?? 0),
            'url' => ContentApi::base().'/collections/'.$c->handle().'/entries',
        ])->values()->all()]);
    }

    /**
     * A collection's published entries, newest first (posts) or in the
     * page tree's order (pages). ?search=, ?{taxonomy}=slug (?tag=, ?category=),
     * ?language=, ?sort=date|-date|title|order, ?limit= (up to 100), ?page=,
     * ?content=1 for the whole text of each.
     */
    public function entries(Request $request, string $collection): JsonResponse
    {
        $handle = $collection;
        $collection = Booklet::collections()->find($handle);
        if (! $collection) {
            return $this->missing('There’s no collection called “'.$handle.'”.');
        }
        $site = $this->site();
        $entries = $site->published()->filter(fn (Entry $e) => $e->collectionHandle() === $handle);

        $language = (string) $request->query('language', '');
        $entries = $language !== ''
            ? $entries->filter(fn (Entry $e) => $site->languageOf($e) === strtolower($language))
            : $entries->reject(fn (Entry $e) => $site->isTranslation($e)); // one of each: translations come by asking

        foreach ((array) $collection->taxonomies() as $taxonomy) {
            $want = $request->query($taxonomy) ?? $request->query(match ($taxonomy) {
                'tags' => 'tag', 'categories' => 'category', default => $taxonomy,
            });
            if (is_string($want) && $want !== '') {
                $entries = $entries->filter(fn (Entry $e) => in_array($want, (array) $e->get($taxonomy, []), true));
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $entries = $entries->filter(fn (Entry $e) => ContentApi::matches($e, $search));
        }

        $sort = (string) $request->query('sort', $collection->dated() ? '-date' : ($collection->structured() ? 'order' : 'title'));
        $entries = $this->sorted($entries, $sort, $handle)->values();

        $limit = max(1, min(ContentApi::MAX_LIMIT, (int) $request->query('limit', 20) ?: 20));
        $page = max(1, (int) $request->query('page', 1));
        $total = $entries->count();
        $last = max(1, (int) ceil($total / $limit));
        $full = $request->boolean('content');

        $pageUrl = fn (int $n) => $n < 1 || $n > $last ? null : $request->fullUrlWithQuery(['page' => $n, 'key' => null]);

        return $this->json([
            'data' => $entries->slice(($page - 1) * $limit, $limit)->map(fn (Entry $e) => ContentApi::entry($e, $full))->values()->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $limit, 'last_page' => $last, 'sort' => $sort],
            'links' => ['prev' => $pageUrl($page - 1), 'next' => $pageUrl($page + 1)],
        ]);
    }

    /** @param  Collection<int, Entry>  $entries */
    protected function sorted(Collection $entries, string $sort, string $handle): Collection
    {
        $desc = str_starts_with($sort, '-');

        $sorted = match (ltrim($sort, '-')) {
            'title' => $entries->sortBy(fn (Entry $e) => mb_strtolower($e->title())),
            'order' => (function () use ($entries, $handle) {
                $order = array_flip(array_column(PageTree::for($handle)->flatten(), 'id'));

                return $entries->sortBy(fn (Entry $e) => [$order[(string) $e->id()] ?? PHP_INT_MAX, mb_strtolower($e->title())]);
            })(),
            default => $entries->sortBy(fn (Entry $e) => (string) $e->attribute('date')),
        };

        return $desc ? $sorted->reverse() : $sorted;
    }

    public function entry(string $id): JsonResponse
    {
        $entry = $this->site()->published()->first(fn (Entry $e) => (string) $e->id() === $id);

        return $entry
            ? $this->json(['data' => ContentApi::entry($entry) + ['translations' => $this->translations($entry)]])
            : $this->missing('No published entry with that id.');
    }

    /** The entry at an address on the site: ?uri=/about (or the whole address). */
    public function find(Request $request): JsonResponse
    {
        $uri = (string) parse_url((string) $request->query('uri', ''), PHP_URL_PATH);
        $site = $this->site();
        $entry = trim($uri, '/') === '' ? $site->homepage() : $site->findByUri($uri);

        return $entry
            ? $this->json(['data' => ContentApi::entry($entry) + ['translations' => $this->translations($entry)]])
            : $this->missing('Nothing published at '.($uri ?: '/').'.');
    }

    protected function translations(Entry $entry): array
    {
        return $this->site()->languages($entry)
            ->reject(fn ($v) => $v['current'])
            ->map(fn ($v) => ['language' => $v['code'], 'url' => $v['url']])
            ->values()->all();
    }

    public function menu(string $handle): JsonResponse
    {
        if (! array_key_exists($handle, Menus::all())) {
            return $this->missing('There’s no menu called “'.$handle.'”.');
        }
        $tidy = function (array $items) use (&$tidy): array {
            return array_map(fn ($item) => [
                'label' => $item['label'],
                'url' => ContentApi::full((string) $item['url']),
                'children' => $tidy((array) ($item['children'] ?? [])),
            ], $items);
        };

        return $this->json(['data' => [
            'handle' => $handle,
            'title' => Menus::all()[$handle],
            'items' => $tidy($this->site()->menu($handle)), // a seasonal version, when one is on
        ]]);
    }

    public function terms(string $taxonomy): JsonResponse
    {
        if (! Booklet::taxonomies()->find($taxonomy)) {
            return $this->missing('There’s no taxonomy called “'.$taxonomy.'”.');
        }
        $counts = $this->site()->published()->flatMap(fn (Entry $e) => (array) $e->get($taxonomy, []))->countBy();

        return $this->json(['data' => Booklet::terms()->whereTaxonomy($taxonomy)
            ->sortBy(fn (Term $t) => mb_strtolower($t->title()))
            ->map(fn (Term $t) => ContentApi::term($t, (int) ($counts[$t->slug()] ?? 0)))
            ->values()->all()]);
    }
}
