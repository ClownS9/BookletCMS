<?php

namespace App\Booklet\Data;

use App\Booklet\Facades\Booklet;

/**
 * A collection: Posts, Pages, Recipes… Settings live in the data bag:
 *   route: '/blog/{slug}' (or one per site), dated: true, structured: false,
 *   blueprints: ['post'], taxonomies: ['categories', 'tags'], sort_by, sort_dir
 */
class Collection extends Item
{
    public static function repositoryName(): string
    {
        return 'collections';
    }

    public function handle(): string
    {
        return $this->attribute('handle');
    }

    public function title(): string
    {
        return $this->attribute('title', ucfirst($this->handle()));
    }

    public function route(?string $site = null): ?string
    {
        $route = $this->get('route');

        if (is_array($route)) {
            return $route[$site ?? config('booklet.default_site')] ?? null;
        }

        return $route;
    }

    public function dated(): bool
    {
        return (bool) $this->get('dated', false);
    }

    public function structured(): bool
    {
        return (bool) $this->get('structured', false);
    }

    /**
     * @return string[]
     */
    public function taxonomies(): array
    {
        return (array) $this->get('taxonomies', []);
    }

    /**
     * Blueprints usable in this collection, from resources/blueprints/collections/{handle}.
     */
    public function blueprints(): \Illuminate\Support\Collection
    {
        return Booklet::blueprints()->inNamespace("collections.{$this->handle()}");
    }

    public function entries(?string $site = null): \Illuminate\Support\Collection
    {
        return Booklet::entries()->whereCollection($this->handle(), $site);
    }
}
