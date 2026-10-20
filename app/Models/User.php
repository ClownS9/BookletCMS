<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $role
 * @property string|null $byline
 * @property string|null $bio
 * @property string|null $avatar
 * @property array|null $preferences
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'byline',
        'bio',
        'avatar',
        'preferences',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'last_seen_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->roleNow() === 'admin';
    }

    // Roles and what they open ---------------------------------------------------------------

    /** Session key: the card an admin is trying out ("see the book as a Guest writer"). */
    public const VIEW_AS = 'booklet.view_as';

    /**
     * The role in force right now: their own, or, for an admin trying out a
     * card, that card (only for themselves, in their own browser).
     */
    public function roleNow(): string
    {
        $role = (string) $this->role;
        if ($role === 'admin' && app()->bound('request') && request()->hasSession()
            && request()->user()?->is($this) && is_string($as = request()->session()->get(self::VIEW_AS))
            && $as !== 'admin' && (isset(\App\Models\Role::BUILT_IN[$as]) || \App\Models\Role::card($as))) {
            return $as;
        }

        return $role;
    }

    /** Trying out another role (an admin seeing the book as someone else)? */
    public function viewingAs(): ?string
    {
        return $this->role === 'admin' && $this->roleNow() !== 'admin' ? $this->roleNow() : null;
    }

    /** Their custom role (library card), if they have one. */
    public function card(): ?\App\Models\Role
    {
        return \App\Models\Role::card($this->roleNow());
    }

    /**
     * How they act everywhere a chapter doesn't decide it: admin, editor or
     * writer. A card acts like the role it's based on; one that's gone, like a writer.
     */
    public function level(): string
    {
        $role = $this->roleNow();
        if (isset(\App\Models\Role::BUILT_IN[$role])) {
            return $role;
        }

        return $this->card()?->base === 'editor' ? 'editor' : 'writer';
    }

    /** Editors and admins (and cards based on an editor). */
    public function arranges(): bool
    {
        return in_array($this->level(), ['admin', 'editor'], true);
    }

    /** The role's name for people: "Admin", "Writer", "Guest writer". */
    public function roleName(): string
    {
        $role = $this->roleNow();

        return isset(\App\Models\Role::BUILT_IN[$role]) ? ucfirst($role) : ($this->card()->name ?? ucfirst(str_replace('_', ' ', $role)));
    }

    /** How far they open a chapter: none, write (their own drafts) or publish (anyone's). */
    public function access(string $collection): string
    {
        return match ($this->level()) {
            'admin' => 'publish',
            default => $this->card()?->access($collection) ?? ($this->level() === 'editor' ? 'publish' : 'write'),
        };
    }

    public function canOpen(string $collection): bool
    {
        return $this->access($collection) !== 'none';
    }

    public function canPublishIn(string $collection): bool
    {
        return $this->access($collection) === 'publish';
    }

    /** May they add, change and remove files in Media? */
    public function canChangeMedia(): bool
    {
        return $this->card()->media ?? true;
    }

    /** May they talk to Stacy (as far as their card goes; Stacy's own settings decide the rest)? */
    public function mayUseStacy(): bool
    {
        return $this->card()->stacy ?? true;
    }

    /**
     * Online if seen in the last five minutes (the green dot in People).
     */
    /**
     * "online" (in the book in the last 5 minutes), "away" (seen today),
     * "offline" (seen before), or "never" (hasn't signed in yet).
     */
    public function presence(): string
    {
        return match (true) {
            $this->last_seen_at === null => 'never',
            $this->isOnline() => 'online',
            $this->last_seen_at->gt(now()->subDay()) => 'away',
            default => 'offline',
        };
    }

    /**
     * The same few words everywhere: "Online", "Away · 2h ago", "Offline · 3 days ago".
     */
    public function presenceLabel(): string
    {
        return match ($this->presence()) {
            'online' => 'Online',
            'away' => 'Away · '.$this->last_seen_at->diffForHumans(short: true),
            'offline' => 'Offline · '.$this->last_seen_at->diffForHumans(short: true),
            default => 'Not signed in yet',
        };
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }
}
