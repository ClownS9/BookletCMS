<?php

namespace App\Models;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A page, post or the blog page built of sections (App\PageBuilder): the
 * draft, what's published, Undo and a schedule. The page or post itself is
 * an entry like any other; this is how it's drawn.
 *
 * @property int $id
 * @property string $target an entry's id, or "blog"
 * @property array<int, array<string, mixed>>|null $draft
 * @property array<int, array<string, mixed>>|null $published
 * @property Carbon|null $published_at
 * @property array{at: string, sections: array<int, mixed>}|null $scheduled
 * @property Carbon|null $scheduled_at
 * @property array<int, mixed>|null $history
 * @property array<string, mixed>|null $history_mark
 * @property bool $fresh
 */
class PageBuild extends Model
{
    public const BLOG = 'blog';

    protected $fillable = ['target', 'draft', 'published', 'published_at', 'scheduled', 'scheduled_at', 'history', 'history_mark', 'fresh'];

    protected function casts(): array
    {
        return [
            'draft' => 'array', 'published' => 'array', 'scheduled' => 'array', 'history' => 'array', 'history_mark' => 'array',
            'published_at' => 'datetime', 'scheduled_at' => 'datetime', 'fresh' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // What readers see changed (published, or taken off): pages kept for readers are put together again.
        static::saved(function (PageBuild $build): void {
            if ($build->wasChanged('published') || ($build->wasRecentlyCreated && $build->published !== null)) {
                \App\Support\PageCache::flush();
                rescue(fn () => \App\Support\StaticSite::changed(), null, false);
            }
        });
        static::deleted(fn () => \App\Support\PageCache::flush());
        // A new schedule: pages are put together again when its time comes.
        static::saved(function (PageBuild $build): void {
            if ($build->wasChanged('scheduled_at') && $build->scheduled_at) {
                \App\Support\PageCache::flush();
            }
        });
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<PageBuildVersion, $this> what was published, newest first */
    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PageBuildVersion::class)->orderByDesc('published_at')->orderByDesc('id');
    }

    public static function forEntry(Entry $entry): ?self
    {
        return $entry->id() ? static::query()->where('target', $entry->id())->first() : null;
    }

    public static function blog(): ?self
    {
        return static::query()->where('target', self::BLOG)->first();
    }

    public function isBlog(): bool
    {
        return $this->target === self::BLOG;
    }

    /** The page or post it is (null for the blog page, or one since deleted). */
    public function entry(): ?Entry
    {
        return $this->isBlog() ? null : rescue(fn () => Booklet::entries()->find($this->target), null, false);
    }

    /** Change what it holds; a schedule keeps its time where it can be looked up. */
    public function put(array $changes): static
    {
        if (array_key_exists('scheduled', $changes)) {
            $changes['scheduled_at'] = is_array($changes['scheduled']) ? Carbon::parse((string) $changes['scheduled']['at']) : null;
        }
        $this->fill($changes)->save();

        return $this;
    }
}
