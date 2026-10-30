<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Models\Proof;
use App\Models\ProofNote;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Proof copies, from the editor: a private link to this entry (drafts too)
 * for someone without an account, and the notes they leave in its margin.
 * Anyone who can edit the entry can share it; links close on their own.
 */
class ProofCopies extends Component
{
    use Stamps;

    #[Locked]
    public string $entryId;

    /** Who it's for (optional): "Aminah", "the client". */
    public string $label = '';

    public int $days = 7;

    public bool $notes = true;

    public bool $making = false;

    public bool $showDone = false;

    public function mount(string $entryId): void
    {
        $this->entryId = $entryId;
        abort_unless($this->allowed(), 403);
    }

    protected function entry(): ?Entry
    {
        return Booklet::entries()->find($this->entryId);
    }

    /** The same rule as opening the entry in the editor. */
    protected function allowed(): bool
    {
        $entry = $this->entry();
        $user = request()->user();
        if (! $entry || ! $user || ! $user->canOpen($entry->collectionHandle())) {
            return false;
        }

        return $user->canPublishIn($entry->collectionHandle()) || (string) $entry->get('author') === (string) $user->id;
    }

    public function make(): void
    {
        abort_unless($this->allowed(), 403);
        $this->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'days' => ['required', 'integer', 'in:'.implode(',', array_keys(Proof::LASTS))],
        ], [], ['label' => 'who it’s for']);

        if (Proof::where('entry_id', $this->entryId)->open()->count() >= Proof::MAX_OPEN) {
            $this->addError('label', 'There are '.Proof::MAX_OPEN.' open links already. Stop one you don’t need first.');

            return;
        }

        [$proof] = Proof::issue($this->entryId, request()->user(), $this->days, $this->label, $this->notes);
        ActivityLog::record('made a proof copy of', 'edits', 'entry', $this->entryId, $this->entry()?->title(), $proof->label ? ['for' => $proof->label] : []);

        $this->reset('label', 'making');
        $this->days = 7;
        $this->notes = true;
        $this->dispatch('proof-made', url: $proof->url());
        $this->stamp($proof->label ? __('Proof copy made. Copy the link and send it to :name.', ['name' => $proof->label]) : __('Proof copy made. Copy the link and send it.'));
    }

    public function stop(int $id): void
    {
        abort_unless($this->allowed(), 403);
        $proof = Proof::where('entry_id', $this->entryId)->findOrFail($id);
        $proof->update(['revoked_at' => now()]);
        ActivityLog::record('stopped sharing a proof copy of', 'edits', 'entry', $this->entryId, $this->entry()?->title());
        $this->stamp('That link works no more.');
    }

    public function toggleDone(int $id): void
    {
        abort_unless($this->allowed(), 403);
        $note = ProofNote::where('entry_id', $this->entryId)->findOrFail($id);
        $note->update($note->resolved_at
            ? ['resolved_at' => null, 'resolved_by' => null]
            : ['resolved_at' => now(), 'resolved_by' => request()->user()?->id]);
    }

    public function render()
    {
        $proofs = Proof::with('creator')->withCount('notes')->where('entry_id', $this->entryId)->latest()->get();
        $notes = ProofNote::with('proof')->where('entry_id', $this->entryId)->latest()->limit(200)->get();

        return view('livewire.admin.proof-copies', [
            'open' => $proofs->filter->isOpen()->values(),
            'closed' => $proofs->reject->isOpen()->count(),
            'waiting' => $notes->whereNull('resolved_at')->values(),
            'done' => $notes->whereNotNull('resolved_at')->values(),
            'lasts' => Proof::LASTS,
        ]);
    }
}
