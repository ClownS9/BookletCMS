<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Entry;
use App\Booklet\Data\GlobalVariables;
use App\Booklet\Facades\Booklet;
use App\Booklet\Schema\Definitions;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Settings: the site's basics, kept in the "site" global set like Statamic
 * globals, so they follow whichever driver "globals" uses.
 */
#[Layout('components.layouts.admin')]
#[Title('Settings')]
class Settings extends Component
{
    use WithFileUploads;

    public const ACCENTS = [
        '#F4876A' => 'Coral',
        '#F3C24F' => 'Sun',
        '#86CDB9' => 'Mint',
        '#93B4F2' => 'Sky',
        '#C5AAF0' => 'Lilac',
        '#F2A7C3' => 'Rose',
    ];

    public const LANGUAGES = ['en' => 'English', 'ms' => 'Bahasa Melayu', 'id' => 'Bahasa Indonesia', 'zh' => '中文'];

    public string $siteName = '';

    public string $tagline = '';

    public string $siteUrl = '';

    public string $timezone = '';

    public string $language = 'en';

    public string $accent = '#F4876A';

    /** "posts" for the latest posts, or a page entry id. */
    public string $homepage = 'posts';

    public int $perPage = 10;

    public bool $comments = true;

    public bool $moderation = true;

    public bool $stacy = true;

    public bool $pageCache = true;

    public ?string $logo = null;

    public $logoUpload = null;

    public bool $saved = false;

    public function mount(): void
    {
        $site = $this->site();

        $this->siteName = (string) $site->get('site_name', config('app.name'));
        $this->tagline = (string) $site->get('tagline', '');
        $this->siteUrl = (string) $site->get('site_url', '');
        $this->timezone = (string) ($site->get('timezone') ?: (config('booklet.timezone') ?: config('app.timezone')));
        $this->language = (string) $site->get('language', 'en');
        $this->accent = (string) $site->get('accent', '#F4876A');
        $this->homepage = (string) $site->get('homepage', 'posts');
        $this->perPage = (int) $site->get('per_page', 10);
        $this->comments = (bool) $site->get('comments', true);
        $this->moderation = (bool) $site->get('moderation', true);
        $this->stacy = (bool) $site->get('stacy', true);
        $this->pageCache = (bool) $site->get('page_cache', true);
        $this->logo = $site->get('logo');
    }

    protected function canEdit(): bool
    {
        return (bool) request()->user()?->isAdmin();
    }

    protected function site(): GlobalVariables
    {
        if (! Booklet::globalSets()->find('site')) {
            Booklet::globalSets()->make(['handle' => 'site', 'title' => 'Site'])->save();
        }

        return Booklet::globals('site')
            ?? Booklet::globalVariables()->make(['handle' => 'site', 'locale' => config('booklet.default_site')], []);
    }

    public function updated(string $property): void
    {
        $this->saved = false;
    }

    public function toggle(string $setting): void
    {
        abort_unless(in_array($setting, ['comments', 'moderation', 'stacy', 'pageCache'], true), 422);

        $this->{$setting} = ! $this->{$setting};
        $this->saved = false;
    }

    /** Forget every kept page now (they're made again as readers arrive). */
    public function clearPages(): void
    {
        abort_unless($this->canEdit(), 403);
        \App\Support\PageCache::flush();
        $this->dispatch('stamp', text: 'Done. Pages are put together fresh for the next readers.');
    }

    public function updatedLogoUpload(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate(['logoUpload' => ['file', 'mimes:svg,png,jpg,jpeg,webp,gif', 'max:4096']], [], ['logoUpload' => 'logo']);

        // A real picture extension, and an SVG with nothing in it that could run.
        $extension = \App\Support\SafeUpload::extension($this->logoUpload, ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif']);
        if (! in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)
            || ($extension === 'svg' && ! \App\Support\SafeUpload::cleanSvgFile($this->logoUpload->getRealPath()))) {
            $this->addError('logoUpload', __('That file doesn’t look like a picture. Try an SVG or PNG.'));
            $this->logoUpload = null;

            return;
        }

        $name = 'logo-'.Str::lower(Str::random(6)).'.'.$extension;
        $this->logo = $this->logoUpload->storeAs('site', $name, 'public');
        $this->logoUpload = null;
        $this->saved = false;
    }

    public function removeLogo(): void
    {
        $this->logo = null;
        $this->saved = false;
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([
            'siteName' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'siteUrl' => ['nullable', 'url:http,https', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'language' => ['required', Rule::in(array_keys(self::LANGUAGES))],
            'homepage' => ['required', 'string'],
            'perPage' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['siteName' => 'site name', 'perPage' => 'posts per page', 'siteUrl' => 'site address']);

        $site = $this->site();
        $previousLogo = $site->get('logo');

        $site->merge([
            'site_name' => $this->siteName,
            'tagline' => $this->tagline !== '' ? $this->tagline : null,
            'site_url' => $this->siteUrl !== '' ? rtrim($this->siteUrl, '/') : null,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'homepage' => $this->homepage,
            'per_page' => $this->perPage,
            'comments' => $this->comments,
            'moderation' => $this->moderation,
            'stacy' => $this->stacy,
            'page_cache' => $this->pageCache,
            'logo' => $this->logo,
        ]);

        if ($this->logo === null) {
            $site->remove('logo');
        }
        if ($this->siteUrl === '') {
            $site->remove('site_url');
        }

        Booklet::globalVariables()->save($site);

        if ($previousLogo && $previousLogo !== $this->logo) {
            \App\Support\Disk::of('public')->delete($previousLogo);
        }

        ActivityLog::record('changed', 'settings', 'settings', 'site', 'Settings');
        $this->saved = true;
    }

    public function render()
    {
        $pages = Booklet::entries()->whereCollection('pages')
            ->map(fn (Entry $e) => ['id' => $e->id(), 'title' => $e->title()]);

        $drivers = collect(Definitions::groups())
            ->mapWithKeys(fn ($group) => [$group => Booklet::driver($group)]);

        return view('livewire.admin.settings', [
            'canEdit' => $this->canEdit(),
            'accents' => self::ACCENTS,
            'languages' => self::LANGUAGES,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'pages' => $pages,
            'drivers' => $drivers,
            'logoUrl' => $this->logoUpload
                ? null
                : ($this->logo ? \App\Support\Disk::of('public')->url($this->logo) : null),
        ]);
    }
}
