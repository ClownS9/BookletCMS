<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\TalksToStacy;
use App\Stacy\Chat;
use App\Stacy\Greeting;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Stacy everywhere: a small floating orb on every page of the book. Tap it
 * (or press Alt+S) and talk; she answers out loud and in a little card.
 *
 * Worked out when asked (#[Computed], from TalksToStacy):
 *
 * @property-read bool $enabled
 * @property-read array|null $brain
 * @property-read \Illuminate\Support\Collection<int, \App\Models\StacyMessage> $messages
 * @property-read array $voice
 */
class StacyOrb extends Component
{
    use TalksToStacy;

    public bool $open = false;

    /** Her hello, when someone has just arrived (set here, never by the browser). */
    #[Locked]
    public ?array $greeting = null;

    public function mount(): void
    {
        $this->conversationId = Chat::latest(request()->user())?->id;
        $this->context = static::contextFromRequest();
        $this->greeting = $this->arrival();
    }

    /** Signed in, set up the book, or back after a while: a note left for this page. */
    protected function arrival(): ?array
    {
        $kind = session()->pull('stacy.greet');
        $since = session()->pull('stacy.away_since');

        if (! in_array($kind, ['first', 'login', 'back'], true) || ! $this->canGreet()) {
            return null;
        }

        return rescue(fn () => Greeting::for(request()->user(), $kind, $since ? Carbon::parse($since) : null), null, false);
    }

    protected function canGreet(): bool
    {
        return Greeting::wanted(request()->user()) && $this->enabled && $this->brain;
    }

    /** The tab was left open (or the computer slept) for hours: welcome back. */
    public function welcomeBack(int $minutes): void
    {
        if ($this->greeting || $minutes < Greeting::AWAY_HOURS * 60 || ! $this->canGreet()) {
            return;
        }

        $this->greeting = rescue(fn () => Greeting::for(request()->user(), 'back', now()->subMinutes(min($minutes, 60 * 24 * 60))), null, false);
    }

    public function dismissGreeting(): void
    {
        $this->greeting = null;
    }

    /** "Don't greet me": off for this person (back on in Your account). */
    public function stopGreetings(): void
    {
        $user = request()->user();
        $user->update(['preferences' => array_merge((array) $user->preferences, ['stacy_greet' => false])]);
        $this->greeting = null;
        $this->dispatch('stamp', text: 'Stacy won’t greet you. Turn it back on in Your account.');
    }

    /**
     * What page the orb is sitting on: a chapter, or an entry being edited.
     */
    public static function contextFromRequest(): array
    {
        $request = request();
        $context = ['where' => 'the '.\App\Support\AdminNav::current().' chapter'];

        $entryId = $request->route('entry');
        if (is_string($entryId) && ($entry = \App\Booklet\Facades\Booklet::entries()->find($entryId))) {
            $context = [
                'where' => 'the editor for “'.($entry->title() ?: 'Untitled').'”',
                'entry_id' => $entry->id(),
                'entry_title' => $entry->title() ?: 'Untitled',
                'entry_status' => $entry->status(),
            ];
        } elseif ($request->routeIs('admin.entries') && ($picked = $request->query('entry'))) {
            if ($entry = \App\Booklet\Facades\Booklet::entries()->find((string) $picked)) {
                $context += [
                    'entry_id' => $entry->id(),
                    'entry_title' => $entry->title() ?: 'Untitled',
                    'entry_status' => $entry->status(),
                ];
            }
        }

        return $context;
    }

    /**
     * Little starting points that fit the page you're on.
     */
    /**
     * Suggestions under the box: [label, what to send].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function chips(): array
    {
        if (isset($this->context['entry_id'])) {
            $chips = [['Better title?', '/title'], ['Write SEO text', '/seo'], ['Suggest tags', '/tags']];
            if (in_array(request()->user()->level(), ['admin', 'editor'], true)) {
                $chips[] = $this->context['entry_status'] === 'published'
                    ? ['Take this off the site', 'Take this off the site']
                    : ['Publish this now', 'Publish this now'];
            }

            return $chips;
        }

        $where = $this->context['where'] ?? '';

        return match (true) {
            str_contains($where, 'Comments') => [['Help me reply', '/comments'], ['Approve the newest', 'Approve the newest comment']],
            str_contains($where, 'Media') => [['Write alt text', '/alt'], ['Missing alt text?', 'Which pictures have no alt text?']],
            str_contains($where, 'Pages') => [['Pages not in the menu?', 'Which pages aren’t in the site menu?'], ['Write SEO text', '/seo'], ['New page', '/page']],
            default => [['Brief me', '/brief'], ['What needs attention?', '/check'], ['My drafts', 'Show me my drafts']],
        };
    }

    public function render()
    {
        // The last few lines, plus any proposal still waiting for an answer.
        $recent = $this->messages->slice(-4);
        $open = $this->messages->first(fn ($m) => isset($m->meta['pending']) && ! isset($m->meta['resolved']));
        if ($open && ! $recent->contains('id', $open->id)) {
            $recent = $recent->prepend($open);
        }

        return view('livewire.admin.stacy-orb', [
            'recent' => $recent->values(),
            'chips' => $this->enabled && $this->brain ? $this->chips() : [],
        ]);
    }
}
