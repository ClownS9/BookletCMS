<?php

namespace App\Console\Commands;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\Comment;
use Illuminate\Console\Command;

/**
 * Removes the doubled starter pages and posts ("Home 2", "About 2", a second
 * "Hello from Booklet") left behind when the starter book was seeded twice.
 *
 * Only a copy is removed, and only when it is untouched: same collection, same
 * title, same text as the one it copies, and the slug is the original's with a
 * number on the end. Anything someone has edited is kept.
 */
class BookletTidyDuplicates extends Command
{
    protected $signature = 'booklet:tidy-duplicates
        {--force : Remove without asking}';

    protected $description = 'Remove untouched duplicate pages and posts left by seeding the starter book twice';

    public function handle(): int
    {
        $entries = Booklet::entries()->all();
        $copies = collect();

        foreach ($entries->groupBy(fn (Entry $e) => $e->collectionHandle().'|'.$e->site()) as $group) {
            $bySlug = $group->keyBy(fn (Entry $e) => (string) $e->slug());

            foreach ($group as $entry) {
                if (! preg_match('/^(.+)-(\d+)$/', (string) $entry->slug(), $m)) {
                    continue;
                }

                $original = $bySlug->get($m[1]);
                if ($original
                    && $original->id() !== $entry->id()
                    && trim($original->title()) === trim($entry->title())
                    && trim((string) $original->get('content')) === trim((string) $entry->get('content'))) {
                    $copies->push([$entry, $original]);
                }
            }
        }

        if ($copies->isEmpty()) {
            $this->info('No duplicates found. Nothing to tidy.');

            return self::SUCCESS;
        }

        $this->table(['Collection', 'Title', 'Copy (removed)', 'Original (kept)'], $copies->map(fn ($pair) => [
            $pair[0]->collectionHandle(), $pair[0]->title(), $pair[0]->slug(), $pair[1]->slug(),
        ])->all());

        if (! $this->option('force') && ! $this->confirm('Remove these '.$copies->count().' copies?', true)) {
            return self::SUCCESS;
        }

        $removed = $copies->map(fn ($pair) => $pair[0]->id())->all();
        $originalFor = $copies->mapWithKeys(fn ($pair) => [$pair[0]->id() => $pair[1]->id()])->all();

        // Take the copies out of page trees and menus first, pointing menu links at the original.
        foreach (Booklet::trees()->all() as $tree) {
            $before = $tree->tree();
            $after = $this->prune($before, $removed, $tree->type() === 'navigation' ? $originalFor : []);
            if ($after !== $before) {
                $tree->setTree($after)->save();
            }
        }

        foreach ($copies as [$copy]) {
            Comment::query()->where('entry_id', $copy->id())->delete();
            Booklet::entries()->delete($copy);
        }

        $this->info('Removed '.count($removed).' '.str('copy')->plural(count($removed)).'.');

        return self::SUCCESS;
    }

    /**
     * Drop branches for removed entries (keeping their children), and in menus
     * swap a copy for its original unless the original is already there.
     */
    protected function prune(array $branches, array $removed, array $swap, array &$seen = []): array
    {
        $out = [];

        foreach ($branches as $branch) {
            $children = $this->prune((array) ($branch['children'] ?? []), $removed, $swap, $seen);
            $id = $branch['entry'] ?? null;

            if ($id && in_array($id, $removed, true)) {
                $replacement = $swap[$id] ?? null;
                if ($replacement && ! in_array($replacement, $seen, true)) {
                    $branch['entry'] = $replacement;
                } else {
                    array_push($out, ...$children);

                    continue;
                }
            }

            if (isset($branch['entry'])) {
                $seen[] = $branch['entry'];
            }
            if (array_key_exists('children', $branch)) {
                $branch['children'] = $children;
            }
            $out[] = $branch;
        }

        return $out;
    }
}
