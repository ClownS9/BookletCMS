<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A custom role, shown as a library card. It always acts like one of the
 * book's own roles (a writer or an editor) and then narrows things down:
 * which chapters it can open and how far (not at all, write its own drafts,
 * or edit and publish anyone's), whether it can change Media, and whether it
 * can talk to Stacy. Admins are never a custom role.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $about
 * @property string $tone
 * @property string $base
 * @property array<string, string>|null $chapters
 * @property bool $media
 * @property bool $stacy
 */
class Role extends Model
{
    /** The book's own roles, which can't be changed or removed. */
    public const BUILT_IN = [
        'admin' => 'Everything, including settings and people',
        'editor' => 'Edit and publish anyone’s entries',
        'writer' => 'Write and edit their own drafts',
    ];

    /** How far a card opens a chapter. */
    public const ACCESS = [
        'none' => 'Can’t open it',
        'write' => 'Writes own drafts',
        'publish' => 'Edits and publishes',
    ];

    public const TONES = ['sky', 'mint', 'sun', 'coral', 'lilac', 'rose', 'sand'];

    protected $fillable = ['key', 'name', 'about', 'tone', 'base', 'chapters', 'media', 'stacy'];

    protected function casts(): array
    {
        return ['chapters' => 'array', 'media' => 'boolean', 'stacy' => 'boolean'];
    }

    protected static function booted(): void
    {
        $forget = fn () => app()->forgetInstance('booklet.roles');
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Every custom role, by key (read once per request).
     *
     * @return Collection<string, Role>
     */
    public static function cards(): Collection
    {
        if (! app()->bound('booklet.roles')) {
            app()->instance('booklet.roles', rescue(fn () => static::query()->orderBy('name')->get()->keyBy('key'), collect(), false));
        }

        return app('booklet.roles');
    }

    public static function card(?string $key): ?Role
    {
        return $key === null || isset(self::BUILT_IN[$key]) ? null : self::cards()->get($key);
    }

    /** Every role a person can be given: [key => [name, about, tone, custom?]]. */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::BUILT_IN as $key => $about) {
            $choices[$key] = ['name' => ucfirst($key), 'about' => $about, 'tone' => ['admin' => 'coral', 'editor' => 'sun', 'writer' => 'mint'][$key], 'custom' => false];
        }
        foreach (self::cards() as $key => $card) {
            $choices[$key] = ['name' => $card->name, 'about' => $card->about ?: $card->summary(), 'tone' => $card->tone, 'custom' => true];
        }

        return $choices;
    }

    /** The role keys that act at one of these levels (for "every editor" lists). */
    public static function keysAtLevel(array $levels): array
    {
        return array_values(array_unique([
            ...array_intersect(array_keys(self::BUILT_IN), $levels),
            ...self::cards()->filter(fn (Role $r) => in_array($r->base, $levels, true))->keys()->all(),
        ]));
    }

    /** A handle for the role from its name ("Guest writer" → guest_writer), never a built-in one. */
    public static function keyFor(string $name, ?int $ignore = null): string
    {
        $base = Str::snake(Str::slug($name, ' ')) ?: 'role';
        $base = isset(self::BUILT_IN[$base]) ? $base.'_card' : $base;
        $key = $base;
        $n = 2;
        while (static::query()->where('key', $key)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    /** How far this card opens a chapter (chapters it doesn't mention: as its base would). */
    public function access(string $collection): string
    {
        $set = $this->chapters[$collection] ?? null;

        return array_key_exists((string) $set, self::ACCESS) ? $set : ($this->base === 'editor' ? 'publish' : 'write');
    }

    /** In a line: "Writes in Posts · can’t open Pages · no Media". */
    public function summary(): string
    {
        $parts = [];
        foreach (\App\Booklet\Facades\Booklet::collections()->all() as $c) {
            $parts[] = match ($this->access($c->handle())) {
                'publish' => 'publishes in '.$c->title(),
                'write' => 'writes in '.$c->title(),
                default => 'can’t open '.$c->title(),
            };
        }
        if (! $this->media) {
            $parts[] = 'no Media changes';
        }
        if (! $this->stacy) {
            $parts[] = 'no Stacy';
        }

        return ucfirst(implode(' · ', $parts));
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<User, $this> */
    public function people(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'role', 'key');
    }
}
