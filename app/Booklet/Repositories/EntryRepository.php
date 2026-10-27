<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Item;
use App\Booklet\Facades\Booklet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Repository<Entry>
 */
class EntryRepository extends Repository
{
    /**
     * Entries in a collection, sorted the way the collection says:
     * dated collections newest first, others by order then title.
     *
     * @return Collection<int, Entry>
     */
    public function whereCollection(string $collection, ?string $site = null): Collection
    {
        $filters = ['collection' => $collection];
        if ($site !== null) {
            $filters['site'] = $site;
        }

        $entries = $this->where($filters);
        $model = Booklet::collections()->find($collection);

        if ($model?->dated()) {
            return $entries->sortByDesc(fn (Entry $e) => $e->attribute('date'))->values();
        }

        return $entries->sortBy([
            fn (Entry $a, Entry $b) => ($a->attribute('order') ?? PHP_INT_MAX) <=> ($b->attribute('order') ?? PHP_INT_MAX),
            fn (Entry $a, Entry $b) => strcasecmp($a->title(), $b->title()),
        ])->values();
    }

    public function findByUri(string $uri, ?string $site = null): ?Entry
    {
        return $this->where(['uri' => '/'.ltrim($uri, '/'), 'site' => $site ?? config('booklet.default_site')])->first();
    }

    public function findBySlug(string $collection, string $slug, ?string $site = null): ?Entry
    {
        return $this->where([
            'collection' => $collection,
            'slug' => $slug,
            'site' => $site ?? config('booklet.default_site'),
        ])->first();
    }

    /**
     * Saving can change a live entry's address (a new slug, parent or date):
     * the old one then forwards to the new one (see App\Support\Redirects).
     *
     * @param  Entry  $item
     */
    public function save(Item $item): Item
    {
        $before = $item->id() ? $this->find($item->id()) : null;
        $wasLive = $before && $before->status() === 'published' && $before->uri();
        $oldUri = $before?->uri();
        // Where it was, for a webhook saying it came down (worked out only when one is listening).
        $oldUrl = $before && $before->status() === 'published' && \App\Support\Webhooks::wants('entry.unpublished') ? rescue(fn () => app(\App\Booklet\Site::class)->absolute(app(\App\Booklet\Site::class)->url($before)), null, false) : null;

        $saved = parent::save($item);

        if ($wasLive && $saved->uri() && $saved->uri() !== $oldUri) {
            rescue(fn () => \App\Support\Redirects::moved($oldUri, $saved->uri(), $saved->id()), null, false);
        }

        // Webhooks: went live, changed while live, or came down.
        rescue(fn () => \App\Support\Webhooks::entrySaved($saved, $before?->status() === 'published', $oldUrl), null, true);

        return $saved;
    }

    /**
     * @param  Entry|string|array  $item
     */
    public function delete(Item|string|array $item): void
    {
        $entry = $item instanceof Entry ? $item : (is_string($item) ? $this->find($item) : null);

        parent::delete($item);

        if ($entry) {
            rescue(fn () => \App\Support\Webhooks::entryDeleted($entry), null, true);
        }
    }

    /**
     * @param  Entry  $item
     */
    protected function prepare(Item $item): void
    {
        $item->setAttribute('site', $item->attribute('site') ?: config('booklet.default_site'));
        $item->setAttribute('published', $item->attribute('published') ?? true);

        if (! $item->attribute('slug') && $item->get('title')) {
            $item->setAttribute('slug', Str::slug($item->get('title')));
        }

        if ($item->attribute('slug')) {
            $item->setAttribute('slug', $this->uniqueSlug($item));
        }

        $collection = Booklet::collections()->find($item->collectionHandle());

        if ($collection?->dated() && ! $item->attribute('date')) {
            $item->setAttribute('date', now());
        }

        $item->setAttribute('uri', $this->uriFor($item, $collection?->route($item->site())));
    }

    /**
     * Fill a route like "/blog/{year}/{slug}" from the entry's attributes and data.
     */
    public function uriFor(Entry $entry, ?string $route): ?string
    {
        if (! $route || ! $entry->slug()) {
            return null;
        }

        $date = $entry->date();
        $values = array_merge($entry->data(), array_filter($entry->attributes(), 'is_scalar'), [
            'year' => $date?->format('Y'),
            'month' => $date?->format('m'),
            'day' => $date?->format('d'),
            'parent_uri' => $this->parentUri($entry),
        ]);

        $uri = preg_replace_callback('/\{\s*([a-z0-9_]+)\s*\}/i', function ($match) use ($values) {
            $value = $values[$match[1]] ?? '';

            return is_scalar($value) ? (string) $value : '';
        }, $route);

        $uri = '/'.trim(preg_replace('#/+#', '/', $uri), '/');

        return $uri;
    }

    /**
     * Two entries in one collection can't share an address: "hello" becomes "hello-2".
     */
    protected function uniqueSlug(Entry $entry): string
    {
        $base = (string) $entry->attribute('slug');
        $taken = $this->whereCollection($entry->collectionHandle(), $entry->site())
            ->reject(fn (Entry $other) => $other->id() === $entry->id())
            ->map(fn (Entry $other) => $other->slug())
            ->flip();

        $slug = $base;
        for ($n = 2; $taken->has($slug); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /**
     * For structured collections (Pages): the address of the entry's parent in the tree.
     */
    protected function parentUri(Entry $entry): string
    {
        $tree = Booklet::trees()->collection($entry->collectionHandle(), $entry->site());
        $parentId = $tree ? (new \App\Booklet\Support\PageTree($tree))->parentOf($entry->id()) : null;

        if (! $parentId) {
            return '';
        }

        return rtrim((string) $this->find($parentId)?->uri(), '/');
    }

    /**
     * Work out every address in a collection again, parents first (after the tree changes).
     */
    public function refreshUris(string $collection, ?string $site = null): void
    {
        $site ??= config('booklet.default_site');
        $tree = Booklet::trees()->collection($collection, $site);
        $order = $tree ? (new \App\Booklet\Support\PageTree($tree))->ids() : [];
        $entries = $this->whereCollection($collection, $site)->keyBy(fn (Entry $e) => $e->id());

        foreach (array_merge($order, $entries->keys()->diff($order)->all()) as $id) {
            if ($entry = $entries->get($id)) {
                $before = $entry->uri();
                $this->prepare($entry);
                if ($entry->uri() !== $before) {
                    $this->save($entry);
                }
            }
        }
    }
}
