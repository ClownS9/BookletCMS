<?php

namespace App\Support;

use App\Booklet\Facades\Booklet;
use App\Models\Reader;
use Illuminate\Support\Facades\Auth;

/**
 * Readers' accounts on the public site: how people join, what they're
 * asked, what the pages say, and who is signed in right now. The settings
 * live with the site's own (Settings › Readers & groups), so they travel
 * with the content and backups.
 */
class Readers
{
    /** How people join the site. */
    public const JOINING = [
        'open' => ['Anyone can join', 'They confirm their email, then they’re in.'],
        'approval' => ['Anyone can ask, you let them in', 'They confirm their email, then wait for you.'],
        'invite' => ['Only with an invitation', 'An email from you, or a group’s invitation link.'],
        'off' => ['Nobody new', 'Readers already in can still sign in.'],
    ];

    /** Kinds of extra questions on the sign-up page. */
    public const QUESTION_TYPES = ['text' => 'A line of text', 'long' => 'A few lines', 'choice' => 'One of a list', 'tick' => 'A tick box'];

    public const DEFAULTS = [
        'joining' => 'off',
        'link' => true,                 // "Sign in" in the site's top bar
        'after' => '',                  // where readers land after signing in ('' = where they were going, or their page)
        'questions' => [],              // [{key, label, type, options, required}]
        'join_title' => '',
        'join_lede' => '',
        'signin_title' => '',
        'welcome' => '',                // a line added to the welcome email
        'locked_lede' => '',            // what a members-only page says to people who can't read it yet
    ];

    public const MAX_QUESTIONS = 8;

    protected static ?array $settings = null;

    public static function settings(): array
    {
        if (self::$settings === null) {
            $saved = rescue(fn () => Booklet::globals('site')?->get('readers'), null, false);
            self::$settings = array_replace(self::DEFAULTS, is_array($saved) ? array_intersect_key($saved, self::DEFAULTS) : []);
        }

        return self::$settings;
    }

    public static function get(string $key): mixed
    {
        return self::settings()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function save(array $changes): void
    {
        $site = Booklet::globals('site');
        if (! $site) {
            return;
        }
        $site->set('readers', array_replace(self::settings(), array_intersect_key($changes, self::DEFAULTS))); // set, not merge: fewer questions must replace more
        Booklet::globalVariables()->save($site);
        self::$settings = null;
    }

    public static function forget(): void
    {
        self::$settings = null;
    }

    public static function joining(): string
    {
        $j = (string) self::get('joining');

        return isset(self::JOINING[$j]) ? $j : 'off';
    }

    /** Is there anything to sign in to? (Joining is open in some way, or readers exist.) */
    public static function on(): bool
    {
        return self::joining() !== 'off' || rescue(fn () => Reader::query()->exists(), false, false);
    }

    /** "Sign in" in the site's top bar. Never in the plain-file copy (it has no server to sign in to). */
    public static function showLink(): bool
    {
        return ! request()->attributes->get('booklet_static') && (bool) self::get('link') && self::on();
    }

    /** The reader signed in on this request, if they're let in (not waiting, not blocked). */
    public static function current(): ?Reader
    {
        $reader = rescue(fn () => Auth::guard('reader')->user(), null, false);

        return $reader instanceof Reader && $reader->isActive() ? $reader : null;
    }

    /** Signed in at all (a reader waiting to be let in included), for the top bar and their page. */
    public static function signedIn(): ?Reader
    {
        $reader = rescue(fn () => Auth::guard('reader')->user(), null, false);

        return $reader instanceof Reader && $reader->state !== 'blocked' ? $reader : null;
    }

    /** @return list<array{key: string, label: string, type: string, options: list<string>, required: bool}> */
    public static function questions(): array
    {
        $out = [];
        foreach ((array) self::get('questions') as $q) {
            if (! is_array($q) || trim((string) ($q['label'] ?? '')) === '' || ! isset(self::QUESTION_TYPES[$q['type'] ?? ''])) {
                continue;
            }
            $out[] = [
                'key' => (string) ($q['key'] ?? ''),
                'label' => trim((string) $q['label']),
                'type' => (string) $q['type'],
                'options' => array_values(array_filter(array_map('trim', (array) ($q['options'] ?? [])), fn ($o) => $o !== '')),
                'required' => (bool) ($q['required'] ?? false),
            ];
        }

        return array_slice($out, 0, self::MAX_QUESTIONS);
    }

    /** Where the readers' pages live: /members, /members/sign-in… */
    public static function path(string $more = ''): string
    {
        $base = trim((string) config('booklet.readers.path', 'members'), '/') ?: 'members';

        return '/'.$base.($more !== '' ? '/'.ltrim($more, '/') : '');
    }

    /** Where to go after signing in: what they were opening, else the setting, else their page. */
    public static function landing(): string
    {
        $after = trim((string) self::get('after'));

        return $after !== '' && str_starts_with($after, '/') && ! str_starts_with($after, '//') ? url($after) : route('readers.home');
    }

    /** An address on this site only (never somewhere else, never the admin). */
    public static function safeBack(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $host = parse_url($url, PHP_URL_HOST);
        if (($host !== null && strcasecmp($host, request()->getHost()) !== 0) || str_starts_with($url, '//')
            || preg_match('#^/(admin|livewire|builder|preview)(/|$)#', $path) || str_starts_with($path, self::path())) {
            return null;
        }

        return $url;
    }
}
