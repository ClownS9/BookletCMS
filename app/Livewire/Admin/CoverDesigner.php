<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\Look;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings › The cover: the site's look, from a few presets, tweaked with a
 * live preview (fonts, accent, paper, header and home layouts, corners,
 * stickers). Nothing changes on the site until "Use this cover". Admins only.
 */
#[Layout('components.layouts.admin')]
class CoverDesigner extends Component
{
    use PageTitle;
    use Stamps;

    public array $look = [];

    public bool $dirty = false;

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        Cache::forget($this->key());
        $this->look = Look::current();
    }

    protected function key(): string
    {
        return Look::previewKey(request()->user()?->getAuthIdentifier() ?? auth()->id() ?? 0);
    }

    public function preset(string $preset): void
    {
        abort_unless(array_key_exists($preset, Look::PRESETS), 404);
        $this->look = Look::clean(['preset' => $preset]);
        $this->changed();
    }

    public function updatedLook(): void
    {
        $this->look = Look::clean($this->look);
        $this->changed();
    }

    public function accent(string $hex): void
    {
        $this->look['accent'] = $hex;
        $this->updatedLook();
    }

    protected function changed(): void
    {
        $this->dirty = true;
        Cache::put($this->key(), $this->look, now()->addHours(2));
        $this->dispatch('look-preview');
    }

    public function save(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        Look::save($this->look);
        Cache::forget($this->key());
        $this->dirty = false;
        ActivityLog::record('changed the cover to', 'settings', 'settings', 'look', Look::PRESETS[$this->look['preset']]['name']);
        $this->stamp('The site wears the new cover now.');
        $this->dispatch('look-preview');
    }

    public function undo(): void
    {
        Cache::forget($this->key());
        $this->look = Look::current();
        $this->dirty = false;
        $this->dispatch('look-preview');
    }

    public function render()
    {
        return $this->titled(view('livewire.admin.cover-designer', [
            'presets' => Look::PRESETS,
            'fonts' => Look::FONTS,
            'titleFonts' => Look::TITLE_FONTS,
            'labelFonts' => Look::LABEL_FONTS,
            'papers' => Look::PAPERS,
            'headers' => Look::HEADERS,
            'homes' => Look::HOMES,
            'corners' => Look::CORNERS,
            'swatches' => array_values(array_unique(array_merge(array_keys(Settings::ACCENTS), array_column(Look::PRESETS, 'accent')))),
            'allFonts' => 'https://fonts.googleapis.com/css2?family='.implode('&family=', array_column(Look::FONTS, 1)).'&display=swap',
            'previewUrl' => route('site.home', ['look_preview' => 1]),
        ]), 'The cover');
    }
}
