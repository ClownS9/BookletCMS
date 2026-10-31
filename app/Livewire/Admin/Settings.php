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

    /** The logo for dark mode (optional), and its upload */
    public ?string $logoDark = null;

    public $logoDarkUpload = null;

    /** The header: 'both', 'logo' or 'name'; and the logo's height */
    public string $logoShow = 'both';

    public int $logoHeight = 32;

    /** The tab and home-screen icon: its own picture, where it comes from ('' = choose for me), the letters */
    public ?string $icon = null;

    public $iconUpload = null;

    public string $iconFrom = '';

    public string $iconLetters = '';

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
        $this->logoDark = $site->get('logo_dark');
        $this->logoShow = in_array($site->get('logo_show'), ['both', 'logo', 'name'], true) ? $site->get('logo_show') : 'both';
        $this->logoHeight = max(20, min(80, (int) $site->get('logo_height', 32)));
        $this->icon = $site->get('icon');
        $this->iconFrom = in_array($site->get('icon_from'), \App\Support\SiteIcons::SOURCES, true) ? $site->get('icon_from') : '';
        $this->iconLetters = (string) $site->get('icon_letters', '');
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
        $this->logo = $this->keepPicture('logoUpload', 'logo') ?? $this->logo;
    }

    public function updatedLogoDarkUpload(): void
    {
        $this->logoDark = $this->keepPicture('logoDarkUpload', 'logo-dark') ?? $this->logoDark;
    }

    public function updatedIconUpload(): void
    {
        $icon = $this->keepPicture('iconUpload', 'icon');
        if ($icon) {
            $this->icon = $icon;
            $this->iconFrom = 'upload';
        }
    }

    /** An uploaded picture, checked and kept on the public disk (null when it isn't one). */
    protected function keepPicture(string $field, string $prefix): ?string
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([$field => ['file', 'mimes:svg,png,jpg,jpeg,webp,gif', 'max:4096']], [], [$field => $prefix === 'icon' ? 'icon' : 'logo']);

        // A real picture extension, and an SVG with nothing in it that could run.
        $extension = \App\Support\SafeUpload::extension($this->{$field}, ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif']);
        if (! in_array($extension, ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)
            || ($extension === 'svg' && ! \App\Support\SafeUpload::cleanSvgFile($this->{$field}->getRealPath()))) {
            $this->addError($field, __('That file doesn’t look like a picture. Try an SVG or PNG.'));
            $this->{$field} = null;

            return null;
        }

        $path = $this->{$field}->storeAs('site', $prefix.'-'.Str::lower(Str::random(6)).'.'.$extension, 'public');
        $this->{$field} = null;
        $this->saved = false;

        return $path;
    }

    public function removeLogo(): void
    {
        $this->logo = null;
        $this->logoDark = null;
        if ($this->iconFrom === 'logo') {
            $this->iconFrom = '';
        }
        $this->saved = false;
    }

    public function removeLogoDark(): void
    {
        $this->logoDark = null;
        $this->saved = false;
    }

    public function removeIcon(): void
    {
        $this->icon = null;
        if ($this->iconFrom === 'upload') {
            $this->iconFrom = '';
        }
        $this->saved = false;
    }

    /** Where the icon comes from: '' (choose for me), upload, logo, initials or booklet. */
    public function chooseIcon(string $from): void
    {
        abort_unless($from === '' || in_array($from, \App\Support\SiteIcons::SOURCES, true), 422);
        $this->iconFrom = $from;
        $this->saved = false;
    }

    public function showLogo(string $show): void
    {
        abort_unless(in_array($show, ['both', 'logo', 'name'], true), 422);
        $this->logoShow = $show;
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
            'logoHeight' => ['required', 'integer', 'min:20', 'max:80'],
            'logoShow' => ['required', Rule::in(['both', 'logo', 'name'])],
            'iconFrom' => ['nullable', Rule::in(['', ...\App\Support\SiteIcons::SOURCES])],
            'iconLetters' => ['nullable', 'string', 'max:2'],
        ], [], ['iconLetters' => 'letters', 'siteName' => 'site name', 'perPage' => 'posts per page', 'siteUrl' => 'site address']);

        $site = $this->site();
        $previous = ['logo' => $site->get('logo'), 'logo_dark' => $site->get('logo_dark'), 'icon' => $site->get('icon')];

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
            'logo_dark' => $this->logo ? $this->logoDark : null,
            'logo_show' => $this->logoShow,
            'logo_height' => $this->logoHeight,
            'icon' => $this->icon,
            'icon_from' => $this->iconFrom !== '' ? $this->iconFrom : null,
            'icon_letters' => trim($this->iconLetters) !== '' ? trim($this->iconLetters) : null,
        ]);

        foreach (['logo', 'logo_dark', 'icon', 'icon_from', 'icon_letters'] as $key) {
            if ($site->get($key) === null) {
                $site->remove($key);
            }
        }
        if ($this->siteUrl === '') {
            $site->remove('site_url');
        }

        Booklet::globalVariables()->save($site);

        foreach ($previous as $key => $was) {
            if ($was && $was !== $site->get($key)) {
                \App\Support\Disk::of('public')->delete($was);
            }
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
            'logoDarkUrl' => $this->logoDark ? \App\Support\Disk::of('public')->url($this->logoDark) : null,
            'icons' => $this->iconPreview(),
        ]);
    }

    /**
     * What the icon looks like with the choices on this page (saved or not):
     * where it comes from, what it shows, and what each choice can offer.
     */
    protected function iconPreview(): array
    {
        $over = ['icon' => $this->icon, 'icon_from' => $this->iconFrom !== '' ? $this->iconFrom : null,
            'icon_letters' => $this->iconLetters, 'logo' => $this->logo, 'name' => $this->siteName];
        $s = \App\Support\SiteIcons::settings($over);
        $source = \App\Support\SiteIcons::source($s);
        $url = fn (?string $path) => $path ? \App\Support\Disk::of('public')->url($path) : null;

        return [
            'source' => $source,
            'auto' => \App\Support\SiteIcons::source(['icon_from' => null] + $s),
            'letters' => \App\Support\SiteIcons::initials($this->siteName, $this->iconLetters),
            'suggested' => \App\Support\SiteIcons::initials($this->siteName),
            'accent' => $s['accent'],
            'on' => \App\Support\Look::onAccent($s['accent']),
            'iconUrl' => $url($this->icon),
            'picture' => $source === 'upload' ? $url($this->icon) : ($source === 'logo' ? $url($this->logo) : null),
            'logoSquare' => $this->logo ? \App\Support\SiteIcons::isSquare($this->logo) : false,
            'pictureIsSvg' => $source === 'upload' ? \App\Support\SiteIcons::isSvg((string) $this->icon) : ($source === 'logo' && \App\Support\SiteIcons::isSvg((string) $this->logo)),
            'admin' => \App\Support\SiteIcons::bookSvg($s['accent']),
        ];
    }
}
