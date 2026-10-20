<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Contents')]
class Contents extends Component
{
    use Stamps;

    #[Validate('required|string|max:200', as: 'title')]
    public string $quickTitle = '';

    public string $quickBody = '';

    /** A sticker earned since you last looked, shown once with a little fanfare. */
    #[\Livewire\Attributes\Locked]
    public ?array $celebrate = null;

    #[\Livewire\Attributes\Locked]
    public int $alsoEarned = 0;

    public function mount(): void
    {
        $user = request()->user();
        $fresh = rescue(fn () => \App\Support\Milestones::fresh($user), collect(), false);
        if ($fresh->isNotEmpty()) {
            $first = $fresh->first();
            $this->celebrate = ['at' => $first['at']->format('j M Y')] + $first;
            $this->alsoEarned = $fresh->count() - 1;
            \App\Support\Milestones::markSeen($user, $fresh->pluck('key'));
        }
    }

    /**
     * "A fresh page": save a quick draft into Posts.
     */
    public function saveQuickDraft(): void
    {
        $this->validate();

        $entry = Booklet::entries()->make(
            ['collection' => 'posts', 'published' => false],
            ['title' => $this->quickTitle, 'content' => $this->quickBody, 'author' => request()->user()?->id],
        )->save();

        ActivityLog::record('started a draft', 'edits', 'entry', $entry->id(), $entry->title());

        $this->reset('quickTitle', 'quickBody');
        $this->stamp('Draft saved to Posts.', afterRedirect: true);
        $this->redirectRoute('admin.entries.edit', ['collection' => 'posts', 'entry' => $entry->id()], navigate: true);
    }

    /**
     * Night reading: a darker book. The whole page changes, so reload it.
     */
    public function toggleNight(): void
    {
        $user = request()->user();
        $preferences = (array) $user->preferences;
        $preferences['night'] = ! ($preferences['night'] ?? false);
        $user->update(['preferences' => $preferences]);

        $this->redirectRoute('admin.contents', navigate: true);
    }

    /**
     * Page views for the last seven days, oldest first.
     *
     * @return array<int, array{day: string, views: int}>
     */
    protected function visits(): array
    {
        $days = collect(range(6, 0))->map(fn ($ago) => now()->subDays($ago)->toDateString());

        try {
            $counts = DB::table('site_visits')->whereIn('day', $days)->pluck('views', 'day');
        } catch (Throwable) {
            $counts = collect();
        }

        return $days->map(fn ($day) => [
            'day' => Carbon::parse($day)->format('D'),
            'views' => (int) ($counts[$day] ?? 0),
        ])->all();
    }

    /** Unfold a dog-eared page from the list. */
    public function unfold(int $id): void
    {
        \App\Models\DogEar::where('user_id', request()->user()?->id)->whereKey($id)->delete();
    }

    /** Put the First steps list away (it can come back from Your account). */
    public function hideChecklist(): void
    {
        $user = request()->user();
        $user->forceFill(['preferences' => array_merge((array) $user->preferences, ['checklist' => false])])->save();
        $this->stamp('Put away. Bring it back any time from Your account.');
    }

    /** "Not now" on one of Stacy's suggestions: hidden for a week. */
    public function notNow(string $key): void
    {
        \App\Stacy\Nudges::hide(request()->user(), mb_substr($key, 0, 120));
    }

    public function render()
    {
        $user = request()->user();
        $entries = Booklet::entries()->all();
        $collections = Booklet::collections()->all();

        $counts = $entries->countBy(fn (Entry $e) => $e->collectionHandle());
        $drafts = $entries->filter(fn (Entry $e) => ! $e->published());

        // Contributors this month: how many entries each person wrote or edited, then who's online.
        $touched = ActivityLog::query()
            ->where('subject_type', 'entry')
            ->whereIn('kind', ['edits', 'publishing'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNull('actor')
            ->get(['user_id', 'subject_key'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('subject_key')->unique()->count());
        $contributors = User::query()->get()
            ->map(fn (User $u) => ['user' => $u, 'entries' => $touched[$u->id] ?? 0])
            ->sortByDesc(fn ($c) => [$c['entries'], $c['user']->isOnline()])
            ->take(3)
            ->values();

        $hour = now()->hour;

        return view('livewire.admin.contents', [
            'user' => $user,
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'),
            'collections' => $collections->map(fn ($c) => [
                'model' => $c,
                'count' => $counts[$c->handle()] ?? 0,
            ]),
            'glance' => [
                ['n' => $entries->count(), 'label' => 'Entries'],
                ['n' => $drafts->count(), 'label' => 'Drafts'],
                ['n' => Booklet::assets()->count(), 'label' => 'Files in Media'],
                ['n' => array_sum(array_column($visits = $this->visits(), 'views')), 'label' => 'Visits this week', 'spark' => $visits],
            ],
            'drafts' => $drafts->count(),
            'checklist' => \App\Support\Checklist::wanted($user) ? \App\Support\Checklist::for($user) : [],
            'forReview' => \App\Support\Reviews::reviews($user) ? \App\Support\Reviews::waiting()->filter(fn ($e) => $user->canPublishIn($e->collectionHandle()))->values() : collect(),
            'sentBack' => \App\Support\Reviews::reviews($user) ? collect() : \App\Support\Reviews::returnedTo($user),
            'peopleCount' => User::count(),
            'dogEars' => \App\Models\DogEar::where('user_id', $user->id)->latest()->take(8)->get(),
            'openNotes' => rescue(fn () => \App\Models\EntryNote::whereNull('done_at')->count(), 0, false),
            'nudges' => rescue(fn () => \App\Stacy\Nudges::for($user), [], false),
            'insertCards' => rescue(fn () => app(\App\Inserts\InsertManager::class)->cards($user), [], true),
            'stickers' => \App\Support\Milestones::wanted($user) ? rescue(fn () => \App\Support\Milestones::for($user), collect(), false) : null,
            'waitingComments' => Comment::where('status', 'waiting')->count(),
            'contributors' => $contributors,
            'recent' => $entries->sortByDesc(fn (Entry $e) => $e->lastModified())->take(4)->values(),
        ]);
    }
}
