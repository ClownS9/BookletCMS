<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A group of people, like Statamic's: readers and people from the book,
 * gathered under a name ("Members", "Kuih Club") so posts, pages and page
 * sections can be opened to them. How someone gets in is the group's own:
 * open, approval, invite (by its link) or closed (the book adds them).
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string|null $about
 * @property string $joining open, approval, invite or closed
 * @property string|null $invite_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Group extends Model
{
    public const JOINING = [
        'open' => ['Anyone signed in can join', 'From their own page on the site, at once.'],
        'approval' => ['They ask, you say yes', 'They ask from their own page; you let them in here.'],
        'invite' => ['With the invitation link', 'Only people who have the link below can join.'],
        'closed' => ['Only you add people', 'Nobody can ask; you add them here.'],
    ];

    protected $fillable = ['handle', 'name', 'about', 'joining', 'invite_token'];

    protected static function booted(): void
    {
        static::creating(function (Group $g) {
            $g->handle = $g->handle ?: self::handleFor($g->name);
        });
    }

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** A handle nobody else has: "Kuih Club" → kuih-club, kuih-club-2… */
    public static function handleFor(string $name, ?int $except = null): string
    {
        $base = Str::slug($name) ?: 'group';
        $base = Str::limit($base, 50, '');
        $handle = $base;
        for ($n = 2; self::query()->where('handle', $handle)->when($except, fn ($q) => $q->whereKeyNot($except))->exists(); $n++) {
            $handle = $base.'-'.$n;
        }

        return $handle;
    }

    /** The link that lets people join (made when first asked for). */
    public function inviteToken(): string
    {
        if (! $this->invite_token) {
            $this->forceFill(['invite_token' => Str::random(40)])->save();
        }

        return (string) $this->invite_token;
    }

    /** @return array<string, string> handle => name, for pickers */
    public static function choices(): array
    {
        return rescue(fn () => self::query()->orderBy('name')->pluck('name', 'handle')->all(), [], false);
    }
}
