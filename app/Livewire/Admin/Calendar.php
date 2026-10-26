<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Calendar: a month of dated entries, published and scheduled (and what comes down when), with the
 * drafts still waiting for a day.
 */
#[Layout('components.layouts.admin')]
#[Title('Calendar')]
class Calendar extends Component
{
    use Stamps;

    public const TONES = ['draft' => 'var(--sun)', 'scheduled' => 'var(--sky)', 'published' => 'var(--mint)', 'down' => 'var(--coral)'];

    /** "2026-10" */
    #[Url(except: '')]
    public string $month = '';

    /** "2026-10-14" */
    #[Url(except: '')]
    public string $day = '';

    public function mount(): void
    {
        $this->month = $this->validMonth($this->month) ?? now()->format('Y-m');
        $this->day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->day) ? $this->day : now()->format('Y-m-d');
    }

    protected function validMonth(string $month): ?string
    {
        return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : null;
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->addMonth()->format('Y-m');
    }

    public function today(): void
    {
        $this->month = now()->format('Y-m');
        $this->day = now()->format('Y-m-d');
    }

    public function pickDay(string $date): void
    {
        $this->day = $date;
    }

    /**
     * A draft dropped onto a day: give it that date (it stays a draft).
     */
    public function planDraft(string $id, string $date): void
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422);

        $entry = Booklet::entries()->find($id);
        abort_unless($entry && ! $entry->published(), 404);
        $user = request()->user();
        abort_unless($user->canPublishIn($entry->collectionHandle()) || ($user->canOpen($entry->collectionHandle()) && (string) $entry->get('author') === (string) $user->id), 403);

        $time = $entry->date()?->format('H:i') ?? '09:00';
        $entry->setAttribute('date', Carbon::parse($date.' '.$time))->save();

        ActivityLog::record('planned', 'edits', 'entry', $entry->id(), $entry->title());
        $this->day = $date;
        $this->stamp('Planned for '.Carbon::parse($date)->format('j F').'. It’s still a draft.');
    }

    public function render()
    {
        $first = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
        $start = $first->copy()->startOfWeek(Carbon::MONDAY);
        $end = $first->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $collections = Booklet::collections()->all()->filter->dated()
            ->filter(fn ($c) => request()->user()->canOpen($c->handle())) // chapters their role doesn't open stay closed here too
            ->keyBy(fn ($c) => $c->handle());
        $entries = Booklet::entries()->all()
            ->filter(fn (Entry $e) => $collections->has($e->collectionHandle()));

        $byDay = $entries
            ->filter(fn (Entry $e) => $e->date() && $e->date()->betweenIncluded($start, $end->copy()->endOfDay()))
            ->sortBy(fn (Entry $e) => $e->date()->timestamp)
            ->groupBy(fn (Entry $e) => $e->date()->format('Y-m-d'));

        // Posts and pages (any chapter they open) planned to come down, on their day.
        $downs = Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->published() && request()->user()->canOpen($e->collectionHandle()))
            ->map(fn (Entry $e) => [$e, \App\Support\Takedowns::at($e)])
            ->filter(fn ($pair) => $pair[1] && $pair[1]->betweenIncluded($start, $end->copy()->endOfDay()))
            ->sortBy(fn ($pair) => $pair[1]->timestamp)
            ->groupBy(fn ($pair) => $pair[1]->format('Y-m-d'));

        $days = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'n' => $date->day,
                'inMonth' => $date->month === $first->month,
                'today' => $date->isToday(),
                'label' => $date->format('j F'),
                'entries' => [...$byDay->get($key, collect())->map(fn (Entry $e) => [
                    'id' => $e->id(),
                    'time' => $e->date()->format('g:i a'),
                    'title' => $e->title() ?: 'Untitled',
                    'status' => $e->status(),
                    'label' => ucfirst($e->status()),
                    'href' => route('admin.entries.edit', [$e->collectionHandle(), $e->id()]),
                ])->values()->all(), ...$downs->get($key, collect())->map(fn ($pair) => [
                    'id' => $pair[0]->id().'-down',
                    'time' => $pair[1]->format('g:i a'),
                    'title' => '↓ '.($pair[0]->title() ?: 'Untitled'),
                    'status' => 'down',
                    'label' => 'Comes down',
                    'href' => route('admin.entries.edit', [$pair[0]->collectionHandle(), $pair[0]->id()]),
                ])->values()->all()],
            ];
        }

        $chosen = collect($days)->firstWhere('date', $this->day);
        if (! $chosen) {
            $date = Carbon::parse($this->day);
            $chosen = ['date' => $this->day, 'label' => $date->format('j F'), 'today' => $date->isToday(), 'entries' => []];
        }

        return view('livewire.admin.calendar', [
            'first' => $first,
            'days' => $days,
            'chosen' => $chosen,
            'drafts' => $entries->filter(fn (Entry $e) => ! $e->published())
                ->sortByDesc(fn (Entry $e) => $e->lastModified())->take(6)->values(),
            'tones' => self::TONES,
        ]);
    }
}
