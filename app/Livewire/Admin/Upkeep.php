<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Support\Upkeep as Jobs;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings › Upkeep: the same looking-after as `php booklet …`, for people
 * who never open a terminal. Clear what's safe to clear, make it quick,
 * remake pictures, tidy how posts and pages are written (with a look first),
 * keep readers away for a while, and start again (the parts you choose, or
 * everything) after a backup. Admins only.
 */
#[Layout('components.layouts.admin')]
#[Title('Upkeep')]
class Upkeep extends Component
{
    use Stamps;

    /** Remaking pictures runs after the page answers; this says it's still going. */
    public const PICTURES_BUSY = 'booklet.upkeep.pictures-busy';

    public bool $tidying = false;

    /** Which of the tidy changes to make (entry ids); all of them by default. */
    public array $tidyPick = [];

    public string $awayMessage = '';

    public string $awayFor = '';

    /** Starting again: what goes, and the two things typed to confirm it. */
    public array $resetParts = [];

    public bool $resetEverything = false;

    public string $resetName = '';

    public string $resetPassword = '';

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
    }

    protected function admin(): void
    {
        abort_unless(request()->user()?->isAdmin(), 403);
    }

    public function clearCaches(): void
    {
        $this->admin();
        $done = Jobs::clearCaches();
        $this->stamp(__('Cleared. :what', ['what' => $done[0] ?? '']));
    }

    public function optimize(): void
    {
        $this->admin();
        $result = Jobs::optimize();
        $this->stamp($result['failed'] === []
            ? __('Booklet is prepared for a live server.')
            : __('Mostly prepared. Couldn’t: :what', ['what' => implode('; ', $result['failed'])]));
    }

    public function rebuildPictures(): void
    {
        $this->admin();
        if (Cache::get(self::PICTURES_BUSY)) {
            return;
        }
        Cache::put(self::PICTURES_BUSY, now()->toIso8601String(), now()->addHour());
        dispatch(function () {
            try {
                Jobs::rebuildPictures();
            } finally {
                Cache::forget(self::PICTURES_BUSY);
            }
        })->afterResponse();
        $this->stamp(__('Remaking the pictures. You can carry on; this page says when it’s done.'));
    }

    public function lookTidy(): void
    {
        $this->admin();
        $this->tidying = true;
        $this->tidyPick = array_map(fn ($c) => $c['entry']->id(), Jobs::tidyPreview());
    }

    public function closeTidy(): void
    {
        $this->tidying = false;
        $this->tidyPick = [];
    }

    public function applyTidy(): void
    {
        $this->admin();
        $ids = array_values(array_filter($this->tidyPick, 'is_string'));
        $done = $ids === [] ? 0 : Jobs::tidyApply($ids, null, request()->user()?->id);
        $this->tidying = false;
        $this->tidyPick = [];
        $this->stamp(trans_choice('Tidied :count. Each earlier version is in its History.|Tidied :count. Each earlier version is in its History.', $done));
    }

    public function sendAway(): void
    {
        $this->admin();
        $this->validate(['awayMessage' => 'nullable|string|max:300', 'awayFor' => 'nullable|integer|min:1|max:10080']);
        Jobs::sendReadersAway($this->awayMessage, request()->user()?->name, $this->awayFor !== '' ? (int) $this->awayFor : null);
        $this->reset('awayMessage', 'awayFor');
        $this->stamp(__('Readers now see “back soon”. You can still use the book and see the site.'));
    }

    public function welcomeBack(): void
    {
        $this->admin();
        Jobs::welcomeReadersBack();
        $this->stamp(__('Readers are welcome again.'));
    }

    /** Everything ticks every part (and unticking it clears them again). */
    public function updatedResetEverything(bool $on): void
    {
        $this->resetParts = $on ? array_keys(Jobs::PARTS) : [];
    }

    public function startAgain()
    {
        $this->admin();
        $this->resetErrorBag();
        $you = request()->user();
        $parts = Jobs::resetParts(array_values(array_filter($this->resetParts, 'is_string')));
        $siteName = (string) (rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false) ?: 'booklet');

        if (! $this->resetEverything && $parts === []) {
            $this->addError('resetParts', __('Choose what should go.'));

            return null;
        }
        if (mb_strtolower(trim($this->resetName)) !== mb_strtolower(trim($siteName))) {
            $this->addError('resetName', __('That isn’t the site’s name.'));

            return null;
        }
        $key = 'upkeep-reset:'.$you->getKey();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('resetPassword', __('Too many tries. Wait a minute and try again.'));

            return null;
        }
        if (! \Illuminate\Support\Facades\Hash::check($this->resetPassword, (string) $you->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            $this->addError('resetPassword', __('That isn’t your password.'));

            return null;
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);

        try {
            $done = Jobs::reset($parts, $you, $this->resetEverything, $you);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('resetParts', __('Booklet couldn’t start again: :why Nothing was cleared unless a backup was made first.', ['why' => $e->getMessage()]));

            return null;
        }

        if ($done['everything']) {
            // There's no one left to be: off to First setup, as a new install.
            \Illuminate\Support\Facades\Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return $this->redirect(route('setup'));
        }

        $this->reset('resetParts', 'resetEverything', 'resetName', 'resetPassword');
        $this->stamp(__('Started again. The book as it was is backed up as :name, in Backups.', ['name' => $done['backup']]), afterRedirect: true);

        return $this->redirectRoute('admin.settings.upkeep', navigate: true);
    }

    public function render()
    {
        $preview = $this->tidying ? Jobs::tidyPreview() : [];

        return view('livewire.admin.upkeep', [
            'last' => Jobs::last(),
            'away' => Jobs::readersAway(),
            'picturesBusy' => (bool) Cache::get(self::PICTURES_BUSY),
            'picturesAvailable' => \App\Support\Pictures::available(),
            'preview' => $preview,
            'rules' => Jobs::TIDY_RULES,
            'cached' => app()->configurationIsCached(),
            'parts' => Jobs::PARTS,
            'siteName' => (string) (rescue(fn () => \App\Booklet\Facades\Booklet::globals('site')?->get('site_name'), null, false) ?: 'booklet'),
        ]);
    }
}
