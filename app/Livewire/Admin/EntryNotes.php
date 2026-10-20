<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\EntryNote;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Sticky notes for the team, stuck to an entry: "check this quote",
 * "needs a photo". Only people signed in to the admin see them. Tick one
 * off when it's done; anyone on the team can.
 */
class EntryNotes extends Component
{
    #[Locked]
    public string $entryId;

    public string $body = '';

    public string $tone = 'sun';

    public bool $showDone = false;

    public function mount(string $entryId): void
    {
        abort_unless(Booklet::entries()->find($entryId) !== null, 404);
        $this->entryId = $entryId;
    }

    public function add(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:1000'],
            'tone' => ['required', 'in:'.implode(',', EntryNote::TONES)],
        ], [], ['body' => 'note']);

        EntryNote::create([
            'entry_id' => $this->entryId,
            'user_id' => request()->user()?->id,
            'body' => trim($this->body),
            'tone' => $this->tone,
        ]);

        ActivityLog::record('left a note on', 'edits', 'entry', $this->entryId, Booklet::entries()->find($this->entryId)?->title());
        $this->body = '';
    }

    /** A note added from elsewhere on the page (an editor sending a draft back). */
    #[On('entry-saved')]
    public function refresh(): void {}

    public function toggleDone(int $id): void
    {
        $note = EntryNote::where('entry_id', $this->entryId)->findOrFail($id);
        $note->update($note->done_at
            ? ['done_at' => null, 'done_by' => null]
            : ['done_at' => now(), 'done_by' => request()->user()?->id]);
    }

    public function remove(int $id): void
    {
        $note = EntryNote::where('entry_id', $this->entryId)->findOrFail($id);
        // Your own notes, or anyone's if you're an editor or admin.
        abort_unless($note->user_id === request()->user()?->id || in_array(request()->user()->level(), ['admin', 'editor'], true), 403);
        $note->delete();
    }

    public function render()
    {
        $notes = EntryNote::with('user', 'doneBy')->where('entry_id', $this->entryId)->latest()->get();

        return view('livewire.admin.entry-notes', [
            'open' => $notes->whereNull('done_at')->values(),
            'done' => $notes->whereNotNull('done_at')->values(),
            'tones' => EntryNote::TONES,
        ]);
    }
}
