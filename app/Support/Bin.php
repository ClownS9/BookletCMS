<?php

namespace App\Support;

use App\Booklet\Data\Asset;
use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\Menus;
use App\Booklet\Support\PageTree;
use App\Models\ActivityLog;
use App\Models\BinItem;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The bin: entries, files and comments that were deleted, kept for 30 days
 * so they can go back exactly as they were (a page back in its place in the
 * tree and in its menus, a file back at its address, a comment with its replies).
 */
class Bin
{
    public const DAYS = 30;

    /** Where deleted files wait (never public). */
    public const DISK = 'local';

    // Into the bin ------------------------------------------------------------

    public static function entry(Entry $entry, ?User $by = null): BinItem
    {
        $collection = $entry->collectionHandle();
        $structured = (bool) Booklet::collections()->find($collection)?->structured();
        $tree = $structured ? PageTree::for($collection, $entry->site()) : null;

        $item = self::keep('entry', $entry->id(), $entry->title() ?: 'Untitled', Str::headline($collection), [
            'collection' => $collection,
            'attributes' => $entry->attributes(),
            'data' => $entry->data(),
            'parent' => $tree?->parentOf($entry->id()),
            'menus' => array_values(array_filter(array_keys(Menus::all()), fn ($h) => Menus::has($h, $entry->id()))),
        ], $by, owner: (string) ($entry->get('author') ?? ''));

        $entry->delete();
        if ($tree) {
            $tree->remove($entry->id())->save();
        }
        Menus::forgetEntry($entry->id());
        ActivityLog::record('moved to the bin', 'edits', 'entry', $entry->id(), $entry->title());

        return $item;
    }

    public static function asset(Asset $asset, ?User $by = null): BinItem
    {
        $disk = self::assetDisk($asset->container());
        $stored = 'bin/'.Str::uuid().'/'.basename($asset->path());

        if ($disk->exists($asset->path())) {
            \App\Support\Disk::of(self::DISK)->writeStream($stored, $disk->readStream($asset->path()));
        }

        $item = self::keep('asset', $asset->path(), (string) ($asset->attribute('basename') ?: basename($asset->path())), 'Media', [
            'container' => $asset->container(),
            'attributes' => $asset->attributes(),
            'data' => $asset->data(),
        ], $by, $stored);

        $disk->delete($asset->path());
        \App\Support\Pictures::forget($asset->path()); // its sized copies go too
        Booklet::assets()->delete($asset);
        ActivityLog::record('moved to the bin', 'uploads', 'asset', $asset->path(), $asset->attribute('basename'));

        return $item;
    }

    public static function comment(Comment $comment, ?User $by = null): BinItem
    {
        $comment->loadMissing('replies');
        $title = $comment->entry()?->title();

        $item = self::keep('comment', (string) $comment->id, Str::limit($comment->author_name.': '.$comment->body, 120), $title ? 'on “'.Str::limit($title, 60).'”' : 'Comment', [
            'comment' => $comment->getAttributes(),
            'replies' => $comment->replies->map->getAttributes()->all(),
        ], $by);

        $comment->delete(); // its replies go with it, and come back with it
        ActivityLog::record('moved a comment to the bin on', 'comments', 'comment', (string) $comment->id, $title);

        return $item;
    }

    // Out again ----------------------------------------------------------------

    /**
     * Put it back. Returns where to find it.
     *
     * @return array{label: string, href: string}
     */
    public static function restore(BinItem $item): array
    {
        $back = DB::transaction(fn () => match ($item->kind) {
            'entry' => self::restoreEntry($item),
            'asset' => self::restoreAsset($item),
            'comment' => self::restoreComment($item),
            default => throw new RuntimeException('Nothing to put back.'),
        });

        self::forget($item);

        return $back;
    }

    /** Gone for good (its stored file too). */
    public static function forget(BinItem $item): void
    {
        if ($item->stored_file) {
            \App\Support\Disk::of(self::DISK)->deleteDirectory(dirname($item->stored_file));
        }
        $item->delete();
    }

    /** Clear out anything that's been in the bin for more than 30 days. */
    public static function tidy(): int
    {
        $old = BinItem::where('expires_at', '<', now())->get();
        $old->each(fn (BinItem $item) => self::forget($item));

        return $old->count();
    }

    /** Can this person see, restore or delete this? Writers: only their own. */
    public static function canHandle(User $user, BinItem $item): bool
    {
        if (in_array($user->level(), ['admin', 'editor'], true)) {
            return true;
        }

        return $item->kind !== 'comment'
            && ((string) $item->deleted_by === (string) $user->id || ($item->owner_id && $item->owner_id === (string) $user->id));
    }

    public static function visibleTo(User $user)
    {
        $query = BinItem::query()->latest();

        if (! in_array($user->level(), ['admin', 'editor'], true)) {
            $query->where('kind', '!=', 'comment')
                ->where(fn ($q) => $q->where('deleted_by', $user->id)->orWhere('owner_id', (string) $user->id));
        }

        return $query;
    }

    // Behind the scenes ----------------------------------------------------------

    protected static function keep(string $kind, string $key, string $title, ?string $meta, array $payload, ?User $by, ?string $stored = null, ?string $owner = null): BinItem
    {
        return BinItem::create([
            'kind' => $kind,
            'item_key' => mb_substr($key, 0, 500),
            'title' => mb_substr($title, 0, 300),
            'meta' => $meta ? mb_substr($meta, 0, 200) : null,
            'payload' => $payload,
            'stored_file' => $stored,
            'deleted_by' => ($by ?? request()->user())?->id,
            'owner_id' => $owner ?: null,
            'expires_at' => now()->addDays(self::DAYS),
        ]);
    }

    protected static function restoreEntry(BinItem $item): array
    {
        $p = $item->payload;
        if (Booklet::entries()->find((string) $item->item_key)) {
            throw new RuntimeException('It’s already back in the book.');
        }
        if (! Booklet::collections()->find($p['collection'])) {
            throw new RuntimeException('Its chapter (“'.$p['collection'].'”) isn’t in the book any more.');
        }

        // Saving works the address out again (and picks a free one if it's been taken since).
        $entry = Booklet::entries()->make($p['attributes'], $p['data'])->save();

        if (Booklet::collections()->find($p['collection'])?->structured()) {
            $tree = PageTree::for($p['collection'], $entry->site());
            $parent = $p['parent'] && $tree->contains($p['parent']) ? $p['parent'] : null;
            $tree->place($entry->id(), $parent)->save();
            Booklet::entries()->refreshUris($p['collection'], $entry->site());
        }
        foreach ($p['menus'] ?? [] as $handle) {
            if (! Menus::has($handle, $entry->id())) {
                Menus::add($handle, $entry->id());
            }
        }

        ActivityLog::record('put back from the bin', 'edits', 'entry', $entry->id(), $entry->title());

        return ['label' => $entry->title() ?: 'Untitled', 'href' => route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()])];
    }

    protected static function restoreAsset(BinItem $item): array
    {
        $p = $item->payload;
        $disk = self::assetDisk($p['container']);
        $path = (string) $p['attributes']['path'];

        // Someone uploaded a file with the same name since: this one comes back alongside it.
        if ($disk->exists($path) || Booklet::assets()->all()->contains(fn (Asset $a) => $a->container() === $p['container'] && $a->path() === $path)) {
            $info = pathinfo($path);
            $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
            $ext = isset($info['extension']) ? '.'.$info['extension'] : '';
            for ($n = 2; $disk->exists($path = $dir.$info['filename'].'-'.$n.$ext); $n++);
            $p['attributes']['path'] = $path;
            $p['attributes']['basename'] = basename($path);
        }

        if ($item->stored_file && \App\Support\Disk::of(self::DISK)->exists($item->stored_file)) {
            $disk->writeStream($path, \App\Support\Disk::of(self::DISK)->readStream($item->stored_file));
        }

        Booklet::assets()->make($p['attributes'], $p['data'])->save();
        ActivityLog::record('put back from the bin', 'uploads', 'asset', $path, basename($path));

        return ['label' => basename($path), 'href' => route('admin.media', ['in' => $p['container'], 'file' => $path])];
    }

    protected static function restoreComment(BinItem $item): array
    {
        $p = $item->payload;
        $c = $p['comment'];

        if (Comment::whereKey($c['id'])->exists()) {
            throw new RuntimeException('It’s already back.');
        }

        Comment::unguarded(function () use ($p, $c) {
            (new Comment)->forceFill($c)->saveQuietly();
            foreach ($p['replies'] ?? [] as $reply) {
                if (! Comment::whereKey($reply['id'])->exists()) {
                    (new Comment)->forceFill($reply)->saveQuietly();
                }
            }
        });

        ActivityLog::record('put a comment back on', 'comments', 'comment', (string) $c['id'], Comment::find($c['id'])?->entry()?->title());

        return ['label' => 'Comments', 'href' => route('admin.comments')];
    }

    protected static function assetDisk(string $container)
    {
        return \App\Support\Disk::of(Booklet::assetContainers()->find($container)?->attribute('disk') ?? 'public');
    }
}
