<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Support\WordPressImport;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Settings › Move in from WordPress: upload the export, look inside, choose
 * what comes across, bring it in.
 */
#[Layout('components.layouts.admin')]
#[Title('Move in from WordPress')]
class ImportWordPress extends Component
{
    use Stamps;

    /** The uploaded export being looked at. */
    #[Url(except: '')]
    public string $file = '';

    public bool $pictures = true;

    public bool $drafts = true;

    public bool $comments = true;

    public bool $redirects = true;

    /** match · me · create */
    public string $authors = 'match';

    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
    }

    public function bringIn(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->validate(['authors' => ['required', 'in:match,me,create']]);

        try {
            $this->result = WordPressImport::run($this->file, request()->user(), [
                'pictures' => $this->pictures,
                'drafts' => $this->drafts,
                'comments' => $this->comments,
                'redirects' => $this->redirects,
                'authors' => $this->authors,
            ]);
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        WordPressImport::forget($this->file);
        $this->file = '';
        $this->stamp('Moved in. Have a look through your posts.');
    }

    public function cancel(): void
    {
        WordPressImport::forget($this->file);
        $this->reset('file', 'result');
    }

    public function render()
    {
        $summary = null;
        $problem = null;

        if ($this->file !== '') {
            try {
                $summary = WordPressImport::summary($this->file);
            } catch (RuntimeException $e) {
                $problem = $e->getMessage();
            }
        }

        return view('livewire.admin.import-wordpress', [
            'summary' => $summary,
            'problem' => $problem,
        ]);
    }
}
