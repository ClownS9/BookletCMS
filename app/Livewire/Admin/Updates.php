<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Models\User;
use App\Support\Updates as Store;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Settings › Updates: which version this is, whether a newer one is out
 * and what changed in it, and updating safely (a backup first, the
 * download checked, the old files kept to go back to). Admins only.
 */
#[Layout('components.layouts.admin')]
#[Title('Updates')]
class Updates extends Component
{
    use Stamps;

    /** Getting ready is under way (the page asks for each step in turn). */
    #[Locked]
    public bool $preparing = false;

    /** Downloaded and checked: the page sends itself on to swap the files. */
    #[Locked]
    public bool $ready = false;

    #[Locked]
    public string $problem = '';

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->ready = (Store::state()['stage'] ?? null) === 'checked' && Store::available() !== null;
    }

    public function checkNow(): void
    {
        try {
            $newer = Store::check();
        } catch (RuntimeException $e) {
            $this->stamp($e->getMessage());

            return;
        }
        $this->stamp($newer ? __('Booklet :version is out.', ['version' => $newer['version']]) : __('This is the newest Booklet.'));
    }

    public function start(): void
    {
        $this->problem = '';
        $this->preparing = Store::available() !== null && Store::blocked(Store::available()) === null;
    }

    /** One step of getting ready. True while there's more to do. */
    public function step(): bool
    {
        $release = Store::available();
        if (! $this->preparing || ! $release) {
            return false;
        }
        try {
            $stage = Store::prepare($release, request()->user());
        } catch (RuntimeException $e) {
            $this->preparing = false;
            $this->problem = $e->getMessage();

            return false;
        }
        if ($stage === 'checked') {
            $this->preparing = false;
            $this->ready = true;

            return false;
        }

        return true;
    }

    public function cancel(): void
    {
        Store::cancel();
        $this->preparing = false;
        $this->ready = false;
    }

    public function render()
    {
        $state = Store::state();
        $available = Store::available();
        $people = User::query()->whereIn('id', collect((array) ($state['history'] ?? []))->pluck('by')->push(Store::undoable()['by'] ?? null)->filter()->unique())->pluck('name', 'id');

        return view('livewire.admin.updates', [
            'current' => Store::current(),
            'latest' => Store::latest(),
            'available' => $available,
            'notes' => Store::notesHtml($available),
            'blocked' => $available ? Store::blocked($available) : Store::blocked(),
            'stage' => $state['stage'] ?? null,
            'undoable' => Store::undoable(),
            'history' => array_slice((array) ($state['history'] ?? []), 0, 8),
            'people' => $people,
            'source' => (string) config('booklet.updates.repo'),
        ]);
    }
}
