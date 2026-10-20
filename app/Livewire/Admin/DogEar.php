<?php

namespace App\Livewire\Admin;

use App\Models\DogEar as Fold;
use App\Support\AdminNav;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The corner of the page: fold it to bookmark this page of the admin.
 * Folded pages are listed on the Contents page and in the search window.
 */
class DogEar extends Component
{
    public const MAX = 30;

    #[Locked]
    public string $url = '';

    #[Locked]
    public string $chapter = '';

    public bool $folded = false;

    public function mount(): void
    {
        $this->url = '/'.ltrim(request()->getRequestUri(), '/');
        $this->chapter = (string) (AdminNav::spread()['chapterTitle'] ?? AdminNav::current());
        $this->folded = Fold::where('user_id', request()->user()?->id)->where('url', $this->url)->exists();
    }

    /** Fold or unfold. The page's own title comes from the browser tab. */
    public function toggle(string $title = ''): void
    {
        $mine = Fold::where('user_id', request()->user()?->id)->where('url', $this->url);

        if ($mine->exists()) {
            $mine->delete();
            $this->folded = false;

            return;
        }

        $title = trim(preg_replace('/\s*·\s*Booklet\s*$/u', '', $title)) ?: $this->chapter ?: 'A page';

        Fold::create([
            'user_id' => request()->user()?->id,
            'url' => $this->url,
            'title' => mb_substr($title, 0, 200),
            'chapter' => mb_substr($this->chapter, 0, 120) ?: null,
        ]);

        // Keep the list short: the oldest folds unfold themselves.
        $keep = Fold::where('user_id', request()->user()?->id)->latest()->take(self::MAX)->pluck('id');
        Fold::where('user_id', request()->user()?->id)->whereNotIn('id', $keep)->delete();

        $this->folded = true;
    }

    public function render()
    {
        return <<<'BLADE'
        <button type="button" @class(['dog-ear', 'is-folded' => $folded])
                x-data x-on:click="$wire.toggle(document.title)"
                aria-pressed="{{ $folded ? 'true' : 'false' }}"
                title="{{ $folded ? 'Unfold this page (remove bookmark)' : 'Fold this corner to bookmark the page' }}">
            <span class="dog-ear__fold" aria-hidden="true"></span>
            <span class="sr-only">{{ $folded ? 'Remove bookmark' : 'Bookmark this page' }}</span>
        </button>
        BLADE;
    }
}
