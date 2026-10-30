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

    protected function pageResetsOn(): array
    {
        return ['kind', 'person'];
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

        $logs = $this->pageOf($query);
        $counts = ActivityLog::query()
            ->when($this->person !== '', fn ($q) => $q->where('user_id', (int) $this->person))
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return view('livewire.admin.activity', [
            'logs' => $logs,
            'links' => $logs->getCollection()->mapWithKeys(fn (ActivityLog $log) => [$log->id => $this->linkFor($log)]),
            'kinds' => self::KINDS,
            'counts' => $counts,
            'people' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
