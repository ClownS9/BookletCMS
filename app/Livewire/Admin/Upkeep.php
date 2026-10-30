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
 * and keep readers away for a while. Admins only.
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
        ]);
    }
}
