<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use App\Support\Look;
use App\Themes\Theme;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings › The cover: the site's look, from a theme (Booklet's own, a
 * cover pack, or a theme with its own templates) and one of its presets,
 * tweaked with a live preview (fonts, accent, paper, header and home
 * layouts, corners, stickers, and the theme's own options). Nothing changes
 * on the site until "Use this cover". Admins only.
 *
 * Themes are added here too: a .zip, a link or a GitHub repository,
 * unpacked to one side and looked at first. One with templates or scripts
 * is code, so it asks for the person's password, as inserts do.
 */
#[Layout('components.layouts.admin')]
class CoverDesigner extends Component
{
    use \App\Livewire\Concerns\BrowsesCatalog;
    use PageTitle;
    use Stamps;

    public array $look = [];

    public bool $dirty = false;

    /** Adding a theme: the form for a link or a file. */
    #[Url(as: 'add', except: false)]
    public bool $adding = false;

    /** A theme unpacked to one side, waiting for a yes. */
    #[Url(as: 'staged', except: '')]
    public string $staged = '';

    public string $source = '';

    public string $password = '';

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        Cache::forget($this->key());
        $this->look = Look::current();
        if ($this->staged !== '' && ! $this->installer()->staged($this->staged)) {
            $this->staged = '';
        }
    }

    protected function key(): string
    {
        return Look::previewKey(request()->user()?->getAuthIdentifier() ?? auth()->id() ?? 0);
    }

    protected function installer(): ThemeInstaller
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return app(ThemeInstaller::class);
    }

    protected function theme(): ?Theme
    {
        return Look::theme($this->look);
    }

    // Choosing --------------------------------------------------------------------------

    /** Wear a theme (in the preview): its first preset. */
    public function wear(string $key): void
    {
        abort_unless($key === 'booklet' || ThemeManager::find($key) !== null, 404);
        $theme = ThemeManager::find($key);
        if ($theme && ! $theme->fits()) {
            // Said, rather than quietly showing Booklet's own instead.
            $this->stamp($theme->name().' needs Booklet '.$theme->needs().' or newer; this is '.config('booklet.version').'. An update of Booklet lets the site wear it.');

            return;
        }
        $this->look = Look::clean(['theme' => $key]);
        $this->changed();
    }

    public function preset(string $preset): void
    {
        abort_unless(array_key_exists($preset, Look::presets($this->theme())), 404);
        $this->look = Look::clean(['theme' => $this->look['theme'] ?? 'booklet', 'preset' => $preset]);
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
        ActivityLog::record('changed the cover to', 'settings', 'settings', 'look', Look::presetName($this->look));
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

    // Adding, updating, taking off the shelf ----------------------------------------------------

    public function startAdding(): void
    {
        $this->dropStaged();
        $this->adding = true;
        $this->source = '';
        $this->resetErrorBag();
    }

    public function stopAdding(): void
    {
        $this->adding = false;
        $this->resetErrorBag();
    }

    /** Fetch from a link or GitHub, unpack to one side, and show what it is. */
    public function fetch(): void
    {
        $this->resetErrorBag();
        $this->validate(['source' => ['required', 'string', 'max:500']], ['source.required' => 'Paste a link to a .zip, or a GitHub repository.']);
        if ($why = $this->installer()->blocked()) {
            $this->addError('source', $why);

            return;
        }
        try {
            $this->staged = $this->installer()->stageFrom($this->installer()->parseSource($this->source));
            $this->adding = false;
            $this->password = '';
        } catch (\RuntimeException $e) {
            $this->addError('source', $e->getMessage());
        }
    }

    public function needsPassword(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) > (int) config('auth.password_timeout', 10800);
    }

    /** Yes: move it into themes/, and show it in the preview. */
    public function install(): void
    {
        $meta = $this->installer()->staged($this->staged);
        if (! $meta) {
            $this->staged = '';
            $this->stamp('That isn’t waiting any more. Add it again.');

            return;
        }

        $this->resetErrorBag();
        // Templates and scripts are code that runs with the whole site's keys: the person's password first.
        if ($meta['code'] && $this->needsPassword()) {
            $limit = 'theme-password:'.request()->user()?->id;
            if (RateLimiter::tooManyAttempts($limit, 5)) {
                $this->addError('password', __('Too many tries. Wait a minute, then try again.'));

                return;
            }
            RateLimiter::hit($limit, 60);
            $this->validate(['password' => ['required', 'current_password']], [
                'password.required' => 'Type your password to say yes.',
                'password.current_password' => 'That isn’t your password.',
            ]);
            RateLimiter::clear($limit);
            session()->put('auth.password_confirmed_at', time());
        }
        $this->password = '';

        try {
            $done = $this->installer()->install($this->staged);
        } catch (\RuntimeException $e) {
            $this->addError('install', $e->getMessage());

            return;
        }
        $this->staged = '';

        ActivityLog::record($done['updated'] ? 'updated the theme' : 'added the theme', 'settings', 'settings', 'look',
            $done['name'].($done['updated'] ? ' ('.$done['from'].' → '.$done['version'].')' : ' '.$done['version']));

        if (($this->look['theme'] ?? 'booklet') === $done['key']) {
            // The one being looked at was updated: keep the person's choices (what the update still has of them).
            if ($this->dirty) {
                $this->look = Look::clean($this->look);
                $this->changed();
                $this->stamp(__(':name is updated to :version. Your changes in the preview are kept.', ['name' => $done['name'], 'version' => $done['version']]));
            } else {
                $this->look = Look::current();
                $this->dispatch('look-preview');
                $this->stamp(Look::current()['theme'] === $done['key'] ? __(':name is updated to :version. The site wears it already.', ['name' => $done['name'], 'version' => $done['version']]) : __(':name is updated to :version.', ['name' => $done['name'], 'version' => $done['version']]));
            }

            return;
        }
        $this->wear($done['key']);
        $this->stamp(__(':name is on the shelf, and in the preview. Press Use this cover to put it on the site.', ['name' => $done['name']]));
    }

    /** Not now: back to finding one (where they came from), with the preview a click away. */
    public function cancelStaged(): void
    {
        $this->dropStaged();
        $this->resetErrorBag();
        $this->adding = true;
    }

    protected function dropStaged(): void
    {
        if ($this->staged !== '') {
            $this->installer()->discard($this->staged);
            $this->staged = '';
        }
        $this->password = '';
    }

    /** Fetch the theme again from where it came from (a link or GitHub), to update it. */
    public function lookForUpdate(string $key): void
    {
        $installer = $this->installer();
        $source = $installer->sourceOf($key);
        if (! $source) {
            return;
        }
        // Ask first (cheap): only fetch the whole theme when there's something newer.
        if (! $installer->updateReady($key)) {
            $ready = $installer->check($key);
            if (! isset($ready[$key])) {
                $this->stamp(in_array($key, $installer->unreached, true)
                    ? 'Booklet couldn’t reach where it came from. Try again in a while.'
                    : 'It’s up to date ('.ThemeManager::find($key)?->version().').');

                return;
            }
        }
        try {
            $this->staged = $installer->stageFrom($source);
            $this->password = '';
            $this->resetErrorBag();
        } catch (\RuntimeException $e) {
            $this->stamp(__('It couldn’t be fetched: :error', ['error' => \Illuminate\Support\Str::limit($e->getMessage(), 160)]));
        }
    }

    public function undoUpdate(string $key): void
    {
        $theme = ThemeManager::find($key);
        if (! $theme) {
            return;
        }
        try {
            $version = $this->installer()->undo($key);
        } catch (\RuntimeException $e) {
            $this->stamp($e->getMessage());

            return;
        }
        ActivityLog::record('went back to an earlier copy of the theme', 'settings', 'settings', 'look', $theme->name().' '.$version);
        $this->look = Look::clean($this->look);
        $this->dispatch('look-preview');
        $this->stamp($theme->name().' is back to version '.$version.'.');
    }

    public function remove(string $key): void
    {
        $theme = ThemeManager::find($key);
        if (! $theme) {
            return;
        }
        try {
            $this->installer()->remove($key);
        } catch (\RuntimeException $e) {
            $this->stamp($e->getMessage());

            return;
        }
        ActivityLog::record('took the theme off the shelf', 'settings', 'settings', 'look', $theme->name());
        if (($this->look['theme'] ?? '') === $key) {
            $this->look = Look::current();
            $this->dirty = false;
            $this->dispatch('look-preview');
        }
        $this->stamp($theme->name().' is off the shelf.');
    }

    public function render()
    {
        $theme = $this->theme();
        $fonts = Look::fonts($theme);
        $waiting = $this->staged !== '' ? $this->installer()->staged($this->staged) : null;
        $presets = Look::presets($theme);

        return $this->titled(view('livewire.admin.cover-designer', [
            'theme' => $theme,
            'themes' => ThemeManager::all(),
            'wearing' => (string) (Look::current()['theme'] ?? 'booklet'),
            'presets' => $presets,
            'fonts' => $fonts,
            'titleFonts' => Look::titleFonts($theme),
            'labelFonts' => Look::labelFonts($theme),
            'papers' => Look::papers($theme),
            'headers' => Look::HEADERS,
            'homes' => Look::HOMES,
            'corners' => Look::CORNERS,
            'options' => $theme?->options() ?? [],
            'swatches' => array_values(array_unique(array_merge(array_column($presets, 'accent'), array_keys(Settings::ACCENTS), array_column(Look::PRESETS, 'accent')))),
            'allFonts' => 'https://fonts.googleapis.com/css2?family='.implode('&family=', array_filter(array_unique(array_column($fonts, 1)))).'&display=swap',
            'fontFaces' => $theme ? ThemeManager::fontFaces($theme) : '',
            'previewUrl' => route('site.home', ['look_preview' => 1]),
            // (Not called "staged" or "source": those are the component's own, and would win.)
            'waiting' => $waiting,
            'blocked' => $this->installer()->blocked(),
            'waitingBlocked' => $waiting ? $this->installer()->blocked((bool) $waiting['code']) : null,
            'error' => $theme ? ThemeManager::error($theme) : null,
            'previous' => $theme ? $this->installer()->previousVersion($theme->key) : null,
            'origin' => $theme ? $this->installer()->sourceOf($theme->key) : null,
            'updates' => ThemeManager::all()->keys()->mapWithKeys(fn ($k) => [$k => $this->installer()->updateReady($k)])->filter()->all(),
        ]), 'The cover');
    }
}
