<?php

namespace App\Http\Controllers;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;

/**
 * Machine-readable views of the site: an RSS feed of the latest posts and a
 * sitemap of everything published, for feed readers and search engines.
 */
class FeedController extends Controller
{
    /** The site as it is for this request (never a copy kept from an earlier one). */
    protected function site(): Site
    {
        return app(Site::class);
    }

    public function feed()
    {
        $posts = $this->site()->posts()->take(20);

        return response()
            ->view('site.feed', [
                'site' => $this->site(),
                'posts' => $posts,
                'updated' => $posts->first()?->date() ?? now(),
            ])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    public function sitemap()
    {
        $entries = $this->site()->published()
            ->filter(fn (Entry $e) => $e->uri() !== null)
            ->map(fn (Entry $e) => [
                'loc' => $this->site()->url($e),
                'lastmod' => $e->attribute('updated_at') ?? $e->date(),
                // Each language version lists all the others (and itself), as search engines ask.
                'alternates' => $this->site()->languages($e)->map(fn ($v) => ['code' => $v['code'], 'url' => $v['url'], 'original' => $v['original']])->all(),
            ]);

        $terms = Booklet::terms()->all()
            ->filter(fn (Term $t) => $t->attribute('uri'))
            ->map(fn (Term $t) => ['loc' => url($t->attribute('uri')), 'lastmod' => null]);

        // Pages inserts make (Events' /events, the page builder's pages…).
        $inserts = collect(rescue(fn () => app(\App\Inserts\InsertManager::class)->publicPages(), [], false))
            ->map(fn ($p) => ['loc' => url($p['path']), 'lastmod' => $p['updated']]);

        $urls = collect([['loc' => url('/'), 'lastmod' => $this->site()->posts()->first()?->date()]])
            ->concat($entries)
            ->concat($terms)
            ->concat($inserts)
            ->unique('loc')
            ->map(fn ($url) => ['loc' => $this->site()->absolute($url['loc']), 'lastmod' => $url['lastmod'], 'alternates' => $url['alternates'] ?? []])
            ->values();

        return response()
            ->view('site.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
