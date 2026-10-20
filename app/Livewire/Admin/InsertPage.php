<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Inserts\Insert;
use App\Inserts\InsertManager;
use App\Livewire\Concerns\Stamps;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * An insert's own page in the book. The insert brings a view (and, if it
 * likes, one for the left page) and named actions; this page does the rest:
 * the spread, the folio, the form state, errors and stamps.
 *
 * In an insert's view: wire:model="form.title", wire:click="act('save')",
 * wire:click="act('delete', {{ $album->id }})".
 */
#[Layout('components.layouts.admin')]
class InsertPage extends Component
{
    use PageTitle;

    use Stamps;

    #[Locked]
    public string $key = '';

    /** The insert's form fields. */
    public array $form = [];

    /** Which record is open, if the insert keeps track of one. */
    public int|string|null $editing = null;

    public function mount(InsertManager $inserts, string $insert): void
    {
        $found = $inserts->active()->get($insert);
        abort_unless($found && $found->page(), 404);
        abort_unless($found->canOpen(request()->user()), 403);

        $this->key = $insert;
        $this->form = $found->page()['form'];

        // ?open=12 opens one of its records straight away (links from Stacy, say), if it has an "open" action.
        $open = request()->query('open');
        if (is_scalar($open) && $open !== '' && is_callable($found->page()['actions']['open'] ?? null)) {
            $this->act('open', (string) $open);
        }
    }

    protected function insert(): Insert
    {
        $insert = app(InsertManager::class)->active()->get($this->key);
        abort_unless($insert && $insert->canOpen(request()->user()), 403);

        return $insert;
    }

    /** Run one of the insert's actions. */
    public function act(string $action, mixed $payload = null): void
    {
        $insert = $this->insert();
        $handler = $insert->page()['actions'][$action] ?? null;
        abort_unless(is_callable($handler), 404);
        $this->resetErrorBag();

        try {
            $result = $handler($insert, $this->form, $payload, $this->editing);
        } catch (ValidationException $e) {
            throw $e; // shown under the fields
        } catch (Throwable $e) {
            report($e);
            app(InsertManager::class)->noteError($this->key, $e);
            $this->stamp('That didn’t work. The problem is noted on the Inserts shelf.');

            return;
        }

        if (is_string($result)) {
            $this->stamp($result);
        } elseif (is_array($result)) {
            if (array_key_exists('form', $result)) {
                $this->form = (array) $result['form'];
            }
            if (array_key_exists('editing', $result)) {
                $this->editing = $result['editing'];
            }
            if (! empty($result['stamp'])) {
                $this->stamp((string) $result['stamp']);
            }
        }
    }

    public function render()
    {
        $insert = $this->insert();
        $page = $insert->page();
        $data = [];
        if (is_callable($page['data'])) {
            try {
                $data = (array) ($page['data'])($insert, $this->form, $this->editing);
            } catch (Throwable $e) {
                report($e);
                app(InsertManager::class)->noteError($this->key, $e);
            }
        }

        return $this->titled(view('livewire.admin.insert-page', [
            'insert' => $insert,
            'page' => $page,
            'data' => $data + ['form' => $this->form, 'editing' => $this->editing],
        ]), $page['title']);
    }
}
