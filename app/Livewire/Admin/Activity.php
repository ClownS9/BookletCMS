<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Activity: every change, date-stamped like a library card.
 */
#[Layout('components.layouts.admin')]
#[Title('Activity')]
class Activity extends Component
{
    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'activity';
    }


    public const KINDS = [
        'edits' => ['Edits', 'var(--sun)'],
        'publishing' => ['Publishing', 'var(--mint)'],
        'uploads' => ['Uploads', 'var(--sky)'],
        'comments' => ['Comments', 'var(--rose)'],
        'people' => ['People', 'var(--lilac)'],
        'settings' => ['Settings', 'var(--sand)'],
    ];

    #[Url(except: '')]
    public string $kind = '';

    #[Url(as: 'by', except: '')]
    public string $person = '';

    /** Find a stamp by what it was about. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** One stamp open in the Dashboard's drawer. */
    public ?int $opened = null;

    protected function pageResetsOn(): array
    {
        return ['kind', 'person', 'search'];
    }

    public function closeDrawer(): void
    {
        $this->opened = null;
    }

    /** "Everything by them", from a stamp. */
    public function byPerson(int $id): void
    {
        $this->person = (string) $id;
        $this->opened = null;
        $this->page = 1;
    }

    public function updatedKind(): void
    {
        $this->resetPage();
    }

    public function updatedPerson(): void
    {
        $this->resetPage();
    }

    /**
     * Where a stamp should link to, if its subject still exists.
     */
    protected function linkFor(ActivityLog $log): ?string
    {
        return match ($log->subject_type) {
            'entry' => ($entry = $log->subject_key ? Booklet::entries()->find($log->subject_key) : null)
                ? route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()])
                : null,
            'comment' => route('admin.comments'),
            'message' => $log->subject_key && \App\Support\Inbox::find($log->subject_key) ? route('admin.inbox', ['message' => $log->subject_key]) : route('admin.inbox'),
            'asset' => route('admin.media'),
            'user' => $log->subject_key ? route('admin.people', ['person' => $log->subject_key]) : null,
            'collection' => $log->subject_key ? route('admin.collections', ['chapter' => $log->subject_key]) : null,
            'settings' => route('admin.settings'),
            'insert' => ($insert = $log->subject_key ? app(\App\Inserts\InsertManager::class)->active()->get($log->subject_key) : null) && $insert->canOpen(request()->user())
                ? $insert->pageUrl()
                : (request()->user()?->isAdmin() ? route('admin.settings.inserts', ['insert' => $log->subject_key]) : null),
            default => null,
        };
    }

    public function render()
    {
        $query = ActivityLog::query()->with('user')->latest('created_at')->latest('id');

        if ($this->kind !== '') {
            $query->where('kind', $this->kind);
        }

        if ($this->person !== '') {
            $query->where('user_id', (int) $this->person);
        }

        if (trim($this->search) !== '') {
            $words = '%'.trim($this->search).'%';
            $query->where(fn ($q) => $q->where('subject_label', 'like', $words)->orWhere('action', 'like', $words));
        }

        $logs = $this->pageOf($query);
        $counts = ActivityLog::query()
            ->when($this->person !== '', fn ($q) => $q->where('user_id', (int) $this->person))
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        $opened = $this->opened ? ActivityLog::with('user')->find($this->opened) : null;

        return view(\App\Support\YourBook::desk() ? 'livewire.admin.activity-table' : 'livewire.admin.activity', [
            'openedLog' => $opened,
            'openedLink' => $opened ? $this->linkFor($opened) : null,
            'logs' => $logs,
            'links' => $logs->getCollection()->mapWithKeys(fn (ActivityLog $log) => [$log->id => $this->linkFor($log)]),
            'kinds' => self::KINDS,
            'counts' => $counts,
            'people' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
