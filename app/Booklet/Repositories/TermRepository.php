<?php

namespace App\Booklet\Repositories;

use App\Booklet\Data\Item;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Repository<Term>
 */
class TermRepository extends Repository
{
    /**
     * @return Collection<int, Term>
     */
    public function whereTaxonomy(string $taxonomy, ?string $site = null): Collection
    {
        return $this->where(['taxonomy' => $taxonomy, 'site' => $site ?? config('booklet.default_site')])
            ->sortBy(fn (Term $t) => Str::lower($t->title()))
            ->values();
    }

    public function findBySlug(string $taxonomy, string $slug, ?string $site = null): ?Term
    {
        return $this->find(['taxonomy' => $taxonomy, 'slug' => $slug, 'site' => $site ?? config('booklet.default_site')]);
    }

    /**
     * @param  Term  $item
     */
    protected function prepare(Item $item): void
    {
        $item->setAttribute('site', $item->attribute('site') ?: config('booklet.default_site'));

        if (! $item->attribute('slug') && $item->get('title')) {
            $item->setAttribute('slug', Str::slug($item->get('title')));
        }

        $route = Booklet::taxonomies()->find($item->taxonomy())?->get('route') ?? '/{taxonomy}/{slug}';
        $item->setAttribute('uri', '/'.trim(strtr($route, [
            '{taxonomy}' => $item->taxonomy(),
            '{slug}' => (string) $item->attribute('slug'),
        ]), '/'));
    }
}
