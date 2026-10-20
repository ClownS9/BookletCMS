<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * First steps, on Contents: the few things that turn a new book into
 * yours. Each one ticks itself off when it's really done (nothing to tick
 * by hand), and the list can be put away at any time.
 *
 * Admins set the book up; editors and writers get their own short list.
 */
class Checklist
{
    /** The starter words, so we can tell when they've been replaced. */
    public const STARTER_ABOUT = 'A few lines about who writes here.';

    public const STARTER_POST = 'hello-from-booklet';

    public static function wanted(User $user): bool
    {
        return (bool) (((array) $user->preferences)['checklist'] ?? true);
    }

    /**
     * @return array<int, array{key: string, label: string, hint: string, done: bool, href: ?string}>
     */
    public static function for(User $user): array
    {
        return rescue(fn () => $user->isAdmin() ? self::book($user) : self::person($user), [], true);
    }

    /** @return array{done: int, total: int} */
    public static function progress(array $items): array
    {
        return ['done' => count(array_filter($items, fn ($i) => $i['done'])), 'total' => count($items)];
    }

    /** Setting up the book (admins). */
    protected static function book(User $user): array
    {
        $site = app(Site::class);
        $entries = Booklet::entries()->all();
        $about = $entries->first(fn (Entry $e) => $e->slug() === 'about' && $e->collection()?->structured());
        $sample = $entries->first(fn (Entry $e) => $e->collectionHandle() === 'posts' && $e->slug() === self::STARTER_POST);
        $posts = $entries->filter(fn (Entry $e) => $e->collection()?->dated() && $e->published() && $e->slug() !== self::STARTER_POST && ! $e->get('translation_of'));

        return [
            self::item('tagline', 'Give it a tagline', 'A line under your site’s name, on every page.',
                trim((string) $site->setting('tagline', '')) !== '', route('admin.settings').'#tagline'),
            self::item('address', 'Set your site’s address', 'So links in emails, the feed and shared posts point to the right place.',
                trim((string) $site->setting('site_url', '')) !== '', route('admin.settings').'#site-url'),
            self::item('logo', 'Add your logo', 'It sits beside your name in the header and on share cards.',
                (bool) $site->setting('logo'), route('admin.settings').'#logo'),
            self::item('about', 'Write your About page', 'Replace the starter line with who writes here and why.',
                $about === null || trim((string) $about->get('content')) !== self::STARTER_ABOUT,
                $about ? route('admin.entries.edit', [$about->collectionHandle(), $about->id()]) : null),
            self::item('post', 'Publish your first post', 'Your own, not the sample.',
                $posts->isNotEmpty(), route('admin.entries.create', 'posts')),
            self::item('sample', 'Put the sample post away', 'Delete “Hello from Booklet”, or make it a draft, once you have your own.',
                $sample === null || ! $sample->published(),
                $sample ? route('admin.entries.edit', [$sample->collectionHandle(), $sample->id()]) : null),
            self::item('email', 'Set up email', 'So password resets, comment alerts and new posts reach people.',
                MailSettings::status()['state'] !== 'off', route('admin.settings.email')),
            self::item('people', 'Invite someone to write with you', 'A writer, an editor, or a second pair of eyes.',
                User::count() > 1, route('admin.people')),
            self::item('stacy', 'Give Stacy a brain', 'Choose an AI service, so she can help you write, find and tidy.',
                AiProvider::where('enabled', true)->get()->contains(fn (AiProvider $p) => $p->isReady()), route('admin.settings.stacy')),
            self::item('backup', 'Make your first backup', 'One file with the whole book. Download it and keep it somewhere safe.',
                Backups::list()->isNotEmpty(), route('admin.backups')),
        ];
    }

    /** Settling in (editors and writers). */
    protected static function person(User $user): array
    {
        $mine = Booklet::entries()->all()->filter(fn (Entry $e) => (string) $e->get('author') === (string) $user->id);
        $editor = $user->level() === 'editor';

        return [
            self::item('picture', 'Add your picture', 'It goes on your posts and the About page.',
                (bool) $user->avatar, route('admin.account')),
            self::item('bio', 'Write a line about you', 'For the author card under your posts.',
                trim((string) $user->bio) !== '', route('admin.account').'#bio'),
            self::item('draft', 'Start your first draft', 'It saves itself as you type.',
                $mine->isNotEmpty(), route('admin.entries.create', 'posts')),
            $editor
                ? self::item('publish', 'Publish something', 'Your own post, or one a writer asked you to review.',
                    $mine->contains(fn (Entry $e) => $e->published()) || self::publishedBy($user), route('admin.entries', 'posts'))
                : self::item('review', 'Ask for a review', 'When a draft is ready, an editor reads it and publishes it.',
                    $mine->contains(fn (Entry $e) => $e->published() || Reviews::state($e) !== null), $mine->first() ? route('admin.entries.edit', [$mine->first()->collectionHandle(), $mine->first()->id()]) : route('admin.entries.create', 'posts')),
        ];
    }

    protected static function publishedBy(User $user): bool
    {
        return \App\Models\ActivityLog::where('user_id', $user->id)->where('kind', 'publishing')->exists();
    }

    protected static function item(string $key, string $label, string $hint, bool $done, ?string $href): array
    {
        return compact('key', 'label', 'hint', 'done', 'href');
    }
}
