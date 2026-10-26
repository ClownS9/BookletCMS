<?php

namespace App\Console\Commands;

use App\Booklet\Facades\Booklet;
use App\Models\InsertRecord;
use App\Models\PageBuild;
use App\PageBuilder\PageBuilder;
use Illuminate\Console\Command;

/**
 * Brings across what was built with the page-builder insert (before it was
 * part of Booklet): the blog page's arrangement, and each built page. A
 * built page that had taken the place of one of your pages builds that page
 * now (back on the site, at its address); one made from scratch becomes a
 * new page in Pages, at the address it had. Nothing is deleted: the insert's
 * records stay until the insert is removed from the shelf.
 */
class BookletBuilderImport extends Command
{
    protected $signature = 'booklet:builder-import';

    protected $description = 'Bring across the pages built with the page-builder insert';

    public function handle(PageBuilder $pages): int
    {
        $records = InsertRecord::query()->where('insert', 'page-builder')->where('kind', 'pages')->get();
        if ($records->isEmpty()) {
            $this->info('Nothing was built with the page-builder insert. Nothing to do.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($records as $record) {
            if ($record->field('builder')) {
                continue; // brought across already
            }
            $keep = [
                'draft' => (array) $record->field('draft', []),
                'published' => $record->field('published') !== null ? (array) $record->field('published') : null,
                'published_at' => $record->field('published_at'),
                'history' => [],
            ];
            if ($record->field('blog')) {
                if (PageBuild::blog()) {
                    $this->line('  The blog page: arranged here already; left as it is.');

                    continue;
                }
                PageBuild::create(['target' => PageBuild::BLOG] + $keep);
                $this->line('  The blog page: '.($keep['published'] !== null ? 'its arrangement is on the site.' : 'its arrangement is a draft.'));
                $done++;

                continue;
            }

            $title = (string) $record->field('title', 'A built page');
            $replaces = (array) $record->field('replaces', []);
            $entry = ! empty($replaces['done']) ? Booklet::entries()->find((string) ($replaces['entry'] ?? '')) : null;
            if ($entry) {
                // It had taken that page's place: now it builds that page, back on the site.
                $address = '/'.trim((string) ($replaces['address'] ?? $entry->slug()), '/');
                $record->forceFill(['slug' => null, 'data' => ['builder' => (string) $entry->id()] + (array) $record->data])->save(); // its address is the page's again
                if ($keep['published'] !== null && ! $entry->published()) {
                    $entry->setAttribute('published', true)->save();
                }
            } else {
                $collection = rescue(fn () => Booklet::collections()->all()->first(fn ($c) => $c->handle() === 'pages' && ! $c->dated())?->handle(), null, false) ?? 'pages';
                $entry = Booklet::entries()->make(['collection' => $collection, 'slug' => (string) $record->slug, 'published' => $keep['published'] !== null],
                    ['title' => $title, 'content' => $pages->asText((array) ($keep['published'] ?? $keep['draft'])), 'meta_description' => (string) $record->field('description', '') ?: null])->save();
                $record->forceFill(['slug' => null, 'data' => ['builder' => (string) $entry->id()] + (array) $record->data])->save(); // so its old route never answers again
                $address = (string) $entry->uri();
            }
            if (PageBuild::forEntry($entry)) {
                $this->line('  “'.$title.'”: built here already; left as it is.');

                continue;
            }
            PageBuild::create(['target' => (string) $entry->id(), 'fresh' => ! $replaces || empty($replaces['done'])] + $keep);
            if ($record->field('home')) {
                Booklet::globals('site')?->set('homepage', $entry->id())->save();
            }
            $this->line('  “'.$title.'”: '.($keep['published'] !== null ? 'on the site at '.$address : 'a draft, in Pages').($record->field('home') ? ' (your home page)' : '').'.');
            $done++;
        }

        \App\Support\PageCache::flush();
        $this->info($done === 0 ? 'Everything was here already.' : 'Brought across: '.$done.'. You can remove the page-builder insert from the shelf now (Settings › Inserts).');

        return self::SUCCESS;
    }
}
