<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Support\Backups as Store;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Backups: the whole book in one file. Make one, download it, keep nightly
 * ones, and restore (with a look at what's inside first). Admins only.
 */
#[Layout('components.layouts.admin')]
#[Title('Backups')]
class Backups extends Component
{
    use Stamps;

    /** The backup being looked at before restoring. */
    #[Url(as: 'restore', except: '')]
    public string $restoring = '';

    public string $confirmWord = '';

    #[\Livewire\Attributes\Locked]
    public ?string $justMade = null;

    public bool $nightly = true;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->nightly = (bool) (Booklet::globals('site')?->get('backups_nightly', true) ?? true);
    }

    public function makeOne(): void
    {
        try {
            $made = Store::create('manual');
        } catch (RuntimeException $e) {
            $this->stamp($e->getMessage());

            return;
        }

        $this->justMade = $made['name'];
        $this->stamp('Backed up. Download it to keep a copy somewhere safe.');
    }

    public function toggleNightly(): void
    {
        $this->nightly = ! $this->nightly;

        if (! Booklet::globalSets()->find('site')) {
            Booklet::globalSets()->make(['handle' => 'site', 'title' => 'Site'])->save();
        }
        $site = Booklet::globals('site') ?? Booklet::globalVariables()->make(['handle' => 'site', 'locale' => config('booklet.default_site')], []);
        $site->set('backups_nightly', $this->nightly)->save();

        $this->stamp($this->nightly ? 'Nightly backups on.' : 'Nightly backups off.');
    }

    public function look(string $name): void
    {
        Store::path($name); // must exist
        $this->restoring = basename($name);
        $this->confirmWord = '';
        $this->resetValidation();
    }

    public function cancelRestore(): void
    {
        $this->restoring = '';
        $this->confirmWord = '';
    }

    public function restore(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        abort_unless(request()->user()->isAdmin(), 403);

        if (mb_strtolower(trim($this->confirmWord)) !== 'restore') {
            $this->addError('confirmWord', __('Type the word restore to go ahead.'));

            return;
        }

        try {
            $done = Store::restore(Store::path($this->restoring));
        } catch (RuntimeException $e) {
            $this->addError('confirmWord', $e->getMessage());

            return;
        }

        $this->stamp(__('Restored. The book as it was is saved too, as “:name”.', ['name' => $done['safety']]), afterRedirect: true);
        $this->redirectRoute('admin.contents');
    }

    public function remove(string $name): void
    {
        Store::delete($name);
        if ($this->restoring === basename($name)) {
            $this->cancelRestore();
        }
        $this->stamp('Backup deleted.');
    }

    public function render()
    {
        $manifest = null;
        $problem = null;
        if ($this->restoring !== '') {
            try {
                $manifest = Store::manifest(Store::path($this->restoring));
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                // That backup isn't there (any more): back to the list. (First: it's a RuntimeException too.)
                $this->restoring = '';
            } catch (RuntimeException $e) {
                $problem = $e->getMessage();
            }
        }

        return view('livewire.admin.backups', [
            'backups' => Store::list(),
            'available' => Store::available(),
            'manifest' => $manifest,
            'problem' => $problem,
            'kinds' => [
                'manual' => ['Made by hand', 'sand'],
                'nightly' => ['Nightly', 'sky'],
                'before-restore' => ['Before a restore', 'coral'],
                'uploaded' => ['Uploaded', 'lilac'],
            ],
        ]);
    }
}
