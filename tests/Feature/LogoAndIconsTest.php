<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Settings;
use App\Models\User;
use App\Support\SiteIcons;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings › Logo & icons: the logo in the header (with or without the name,
 * at a chosen size, with a dark-mode version), and the icons for tabs and
 * phones, drawn in every size from an icon, a square logo, the initials or
 * Booklet's own book. The admin always wears Booklet's.
 */
class LogoAndIconsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->cleanIcons();
        Livewire::actingAs($this->admin)->test(Settings::class)->set('siteName', 'Kopi & Kuih')->call('save')->assertHasNoErrors();
        $this->fresh();
    }

    protected function tearDown(): void
    {
        $this->cleanIcons();
        parent::tearDown();
    }

    protected function cleanIcons(): void
    {
        foreach (glob(storage_path('app/booklet-icons/*/*')) ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob(storage_path('app/booklet-icons/*'), GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
    }

    protected function site(): \App\Booklet\Data\GlobalVariables
    {
        $this->fresh();

        return Booklet::globals('site');
    }

    /** As a new request would: the site read again. */
    protected function fresh(): void
    {
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        \App\Support\PageCache::flush();
    }

    protected function home(): string
    {
        $this->fresh();

        return $this->get('/')->assertOk()->getContent();
    }

    public function test_initials_come_from_the_name_unless_letters_are_given(): void
    {
        $this->assertSame('KK', SiteIcons::initials('Kopi & Kuih'));
        $this->assertSame('B', SiteIcons::initials('Booklet'));
        $this->assertSame('MD', SiteIcons::initials('mak dara’s kitchen notes'));
        $this->assertSame('AB', SiteIcons::initials('Anything', 'ab c'));
        $this->assertSame('', SiteIcons::initials('  & — '));
    }

    public function test_with_nothing_uploaded_the_site_wears_its_initials_and_the_admin_wears_booklets(): void
    {
        $this->assertSame('initials', SiteIcons::source());

        $home = $this->home();
        $this->assertStringContainsString('rel="icon" href="'.route('icons', ['set' => 'site', 'file' => 'favicon.svg']), $home);
        $this->assertStringContainsString('rel="apple-touch-icon"', $home);
        $this->assertStringContainsString('rel="manifest"', $home);

        $admin = $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()->getContent();
        $this->assertStringContainsString(route('icons', ['set' => 'booklet', 'file' => 'favicon.svg']), $admin);
        $this->assertStringNotContainsString('/icons/site/', $admin);

        $svg = $this->get(route('icons', ['set' => 'site', 'file' => 'favicon.svg']))->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')->streamedContent();
        $this->assertStringContainsString('>KK<', $svg);

        foreach (['icon-32.png' => 32, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
            $png = $this->get(route('icons', ['set' => 'site', 'file' => $file]))->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent();
            $this->assertSame([$size, $size], array_slice(getimagesizefromstring($png), 0, 2), $file);
        }
        $ico = $this->get(route('icons', ['set' => 'booklet', 'file' => 'favicon.ico']))->assertOk()->streamedContent();
        $this->assertSame("\0\0\1\0\3\0", substr($ico, 0, 6), 'an .ico with three sizes');

        $manifest = $this->get(route('site.manifest'))->assertOk()->json();
        $this->assertSame('Kopi & Kuih', $manifest['name'] ?? Booklet::globals('site')->get('site_name'));
        $this->assertCount(2, $manifest['icons']);

        $this->get('/icons/site/nope.png')->assertNotFound();
        $this->get('/icons/other/icon-32.png')->assertNotFound();
    }

    public function test_a_wide_logo_shows_in_the_header_but_doesnt_become_the_icon(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('logoUpload', UploadedFile::fake()->image('wordmark.png', 600, 150))
            ->call('showLogo', 'logo')->set('logoHeight', 40)
            ->call('save')->assertHasNoErrors();

        $site = $this->site();
        $this->assertSame('logo', $site->get('logo_show'));
        $this->assertSame(40, (int) $site->get('logo_height'));
        $this->assertSame('initials', SiteIcons::source(), 'a wide logo would be squashed');

        $home = $this->home();
        $this->assertMatchesRegularExpression('/<a href="[^"]*" class="brand brand--logo"\s+style="--logo-h: 40px"\s*>/', $home);
        $this->assertStringContainsString('alt="'.e($site->get('site_name')).'" class="brand__logo"', $home, 'the logo alone carries the name');
        $this->assertStringNotContainsString('class="brand__name"', $home);
    }

    public function test_a_square_logo_becomes_the_icon_and_an_own_icon_wins_over_it(): void
    {
        $settings = Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('logoUpload', UploadedFile::fake()->image('mark.png', 400, 400))
            ->call('save')->assertHasNoErrors();
        $this->assertSame('logo', SiteIcons::source());
        $this->assertFalse(SiteIcons::hasSvg('site'), 'a PNG has no SVG to offer the tab');
        $this->get(route('icons', ['set' => 'site', 'file' => 'favicon.svg']))->assertNotFound();
        $this->get(route('icons', ['set' => 'site', 'file' => 'icon-192.png']))->assertOk();

        $settings->set('iconUpload', UploadedFile::fake()->image('icon.png', 512, 512))->assertSet('iconFrom', 'upload')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('upload', SiteIcons::source());
        $icon = $this->site()->get('icon');
        Storage::disk('public')->assertExists($icon);

        // Choosing Booklet's, then removing the icon: the file goes, the choice stays.
        $settings->call('chooseIcon', 'booklet')->call('save');
        $this->assertSame('booklet', SiteIcons::source());
        $settings->call('removeIcon')->call('save');
        Storage::disk('public')->assertMissing($icon);
        $this->assertSame('booklet', $this->site()->get('icon_from'));
    }

    public function test_a_dark_logo_is_used_in_dark_mode_and_goes_with_the_logo(): void
    {
        $settings = Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('logoUpload', UploadedFile::fake()->image('light.png', 300, 100))
            ->set('logoDarkUpload', UploadedFile::fake()->image('dark.png', 300, 100))
            ->call('save')->assertHasNoErrors();

        $home = $this->home();
        $this->assertStringContainsString('brand__logo brand__logo--light', $home);
        $this->assertStringContainsString('brand__logo brand__logo--dark', $home);

        $dark = $this->site()->get('logo_dark');
        $settings->call('removeLogo')->call('save');
        $this->assertNull($this->site()->get('logo_dark'));
        Storage::disk('public')->assertMissing($dark);
        $this->assertStringContainsString('class="brand__mark"', $this->home());
    }

    public function test_letters_change_the_icon_and_a_new_drawing_gets_a_new_address(): void
    {
        $before = SiteIcons::url('site', 'favicon.svg');
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->call('chooseIcon', 'initials')->set('iconLetters', 'mk')->call('save')->assertHasNoErrors();

        $after = SiteIcons::url('site', 'favicon.svg');
        $this->assertNotSame($before, $after);
        $this->assertStringContainsString('>MK<', $this->get($after)->streamedContent());

        Livewire::actingAs($this->admin)->test(Settings::class)->set('iconLetters', 'abc')->call('save')->assertHasErrors('iconLetters');
    }

    public function test_only_admins_change_them(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        Livewire::actingAs($editor)->test(Settings::class)
            ->set('iconUpload', UploadedFile::fake()->image('icon.png', 512, 512))->assertForbidden();
    }
}
