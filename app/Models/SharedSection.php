<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A section used on more than one page: its words and look live here, and
 * each page holds a link to it ('shared' => its id).
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property array{fields?: array<string, mixed>, look?: array<string, mixed>} $draft
 * @property array{fields?: array<string, mixed>, look?: array<string, mixed>}|null $published
 */
class SharedSection extends Model
{
    protected $fillable = ['name', 'type', 'draft', 'published'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array'];
    }

    protected static function booted(): void
    {
        // What readers see changed: pages kept for readers are put together again.
        static::saved(function (SharedSection $s): void {
            if ($s->wasChanged('published')) {
                \App\Support\PageCache::flush();
                rescue(fn () => \App\Support\StaticSite::changed(), null, false);
            }
        });
    }
}
