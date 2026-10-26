<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Form;
use App\Models\ActivityLog;
use App\Models\PageBuild;
use App\Support\Forms as Made;
use App\Support\Inbox;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Comments › Forms: forms of your own (a sign-up, an RSVP, an order). Make
 * one, write its questions, and put it on a page with the page builder's
 * "A form" section. Answers arrive in the Inbox. Admins and editors.
 */
#[Layout('components.layouts.admin')]
#[Title('Forms')]
class Forms extends Component
{
    use \App\Livewire\Concerns\Stamps;

    #[Url(as: 'form', except: null)]
    public ?string $chosen = null;

    public string $newName = '';

    // The chosen form, as it's being changed.
    public string $name = '';

    /** @var list<array{key: string, label: string, type: string, required: bool, options: string, help: string}> */
    public array $fields = [];

    public string $thanks = '';

    public string $button = 'Send';

    public bool $open = true;

    public bool $notify = true;

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
        if ($this->chosen && ! $this->load($this->chosen)) {
            $this->chosen = null;
        }
        if (! $this->chosen && ($first = Made::all()->first())) {
            $this->load($first->handle());
        }
    }

    protected function allowed(): bool
    {
        return in_array(request()->user()?->level(), ['admin', 'editor'], true);
    }

    protected function load(string $handle): bool
    {
        $form = Made::find($handle);
        if (! $form) {
            return false;
        }
        $this->chosen = $form->handle();
        $this->name = $form->title();
        $this->fields = array_map(fn ($f) => [
            'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'],
            'options' => implode("\n", $f['options']), 'help' => $f['help'],
        ], Made::fields($form));
        $this->thanks = (string) $form->get('thanks', '');
        $this->button = (string) $form->get('button', 'Send');
        $this->open = (bool) $form->get('open', true);
        $this->notify = (bool) $form->get('notify', true);
        $this->resetErrorBag();

        return true;
    }

    protected function form(): ?Form
    {
        return Made::find($this->chosen);
    }

    public function pick(string $handle): void
    {
        abort_unless($this->allowed(), 403);
        $this->load($handle);
    }

    public function create(): void
    {
        abort_unless($this->allowed(), 403);
        $this->validate(['newName' => ['required', 'string', 'max:80']], ['newName.required' => 'Give it a name, like “Bake sale sign-up”.'], ['newName' => 'name']);
        try {
            $form = Made::create($this->newName);
        } catch (\InvalidArgumentException $e) {
            $this->addError('newName', $e->getMessage());

            return;
        }
        ActivityLog::record('made the form', 'comments', 'form', $form->handle(), $form->title());
        $this->newName = '';
        $this->load($form->handle());
        $this->stamp('Made. Change its questions on the right, then save.');
    }

    // Questions ---------------------------------------------------------------------------

    public function addField(string $type): void
    {
        abort_unless($this->allowed() && array_key_exists($type, Made::KINDS), 403);
        if (count($this->fields) >= Made::MAX_FIELDS) {
            $this->addError('fields', 'That’s as many questions as a form can have.');

            return;
        }
        $this->fields[] = [
            'key' => '', 'label' => ['email' => 'Email', 'phone' => 'Phone number', 'date' => 'Which day?', 'tick' => 'I agree', 'number' => 'How many?'][$type] ?? '',
            'type' => $type, 'required' => false, 'options' => in_array($type, ['choice', 'choices'], true) ? "First choice\nSecond choice" : '', 'help' => '',
        ];
    }

    public function moveField(int $at, int $step): void
    {
        $to = $at + $step;
        if (! isset($this->fields[$at], $this->fields[$to])) {
            return;
        }
        [$this->fields[$at], $this->fields[$to]] = [$this->fields[$to], $this->fields[$at]];
    }

    public function removeField(int $at): void
    {
        unset($this->fields[$at]);
        $this->fields = array_values($this->fields);
    }

    public function save(): void
    {
        abort_unless($this->allowed(), 403);
        $form = $this->form();
        if (! $form) {
            return;
        }
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'fields' => ['array', 'min:1', 'max:'.Made::MAX_FIELDS],
            'fields.*.label' => ['required', 'string', 'max:160'],
            'fields.*.type' => ['required', 'in:'.implode(',', array_keys(Made::KINDS))],
            'fields.*.help' => ['nullable', 'string', 'max:200'],
            'thanks' => ['nullable', 'string', 'max:600'],
            'button' => ['nullable', 'string', 'max:40'],
        ];
        $this->validate($rules, [
            'fields.min' => 'A form needs at least one question.',
            'fields.*.label.required' => 'Write the question.',
        ], ['name' => 'name', 'fields.*.label' => 'question', 'fields.*.help' => 'hint']);
        foreach ($this->fields as $i => $f) {
            if (in_array($f['type'], ['choice', 'choices'], true) && count(Made::options($f['options'])) < 2) {
                $this->addError('fields.'.$i.'.options', 'Give it at least two choices, one a line.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        Made::save($form, $this->name, $this->fields, $this->thanks, $this->button, $this->open, $this->notify);
        ActivityLog::record('changed the form', 'comments', 'form', $form->handle(), $this->name);
        $this->load($form->handle());
        $this->stamp('Saved. Pages with it show the new questions now.');
    }

    public function delete(): void
    {
        abort_unless($this->allowed(), 403);
        $form = $this->form();
        if (! $form) {
            return;
        }
        $name = $form->title();
        Made::delete($form);
        ActivityLog::record('deleted the form', 'comments', 'form', $form->handle(), $name);
        $this->chosen = null;
        if ($first = Made::all()->first()) {
            $this->load($first->handle());
        }
        $this->stamp('“'.$name.'” is gone, with its answers.');
    }

    /** Pages with this form on them (built with the page builder): [title, address]. */
    protected function usedOn(string $handle): array
    {
        $out = [];
        foreach (rescue(fn () => PageBuild::all(), collect(), false) as $page) {
            $uses = collect([...(array) $page->draft, ...(array) $page->published])
                ->contains(fn ($s) => is_array($s) && ($s['type'] ?? '') === 'form' && (string) ($s['fields']['form'] ?? '') === $handle);
            if ($uses) {
                $entry = $page->entry();
                $out[] = [$page->isBlog() ? 'The blog page' : ($entry?->title() ?: 'A page'), $page->isBlog() ? null : $entry?->uri()];
            }
        }

        return $out;
    }

    public function render()
    {
        $forms = Made::all();
        $form = $this->form();

        return view('livewire.admin.forms', [
            'forms' => $forms->map(fn (Form $f) => [
                'handle' => $f->handle(), 'name' => $f->title(), 'answers' => Made::count($f), 'open' => (bool) $f->get('open', true),
            ]),
            'form' => $form,
            'answers' => $form ? Made::count($form) : 0,
            'unread' => $form ? Inbox::all($form->handle())->filter(fn ($s) => Inbox::status($s) === 'new')->count() : 0,
            'usedOn' => $form ? $this->usedOn($form->handle()) : [],
            'kinds' => Made::KINDS,
            'max' => count($this->fields) >= Made::MAX_FIELDS,
        ]);
    }
}
