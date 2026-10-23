<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\CoverDesigner;
use App\Models\User;
use App\Support\Look;
use App\Support\StaticSite;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Themes: cover packs (styles, fonts, colours, presets, options; checked
 * so they're safe from anyone) and themes with their own templates (code:
 * a password first, and Booklet's own page when one breaks).
 */
class ThemesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        File::deleteDirectory(ThemeManager::path());
        File::deleteDirectory(StaticSite::privatePath('theme-staging'));
        File::deleteDirectory(StaticSite::privatePath('theme-previous'));
        @unlink(StaticSite::privatePath('theme-errors.json'));
        ThemeManager::forget();
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        File::deleteDirectory(ThemeManager::path());
        File::deleteDirectory(StaticSite::privatePath('theme-staging'));
        File::deleteDirectory(StaticSite::privatePath('theme-previous'));
        @unlink(StaticSite::privatePath('theme-errors.json'));
        @unlink(StaticSite::privatePath('theme-sources.json'));
        parent::tearDown();
    }

    /** A .zip with these files in it (path => contents). */
    protected function zip(array $files, string $folder = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'theme').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($folder.$name, $contents);
        }
        $zip->close();

        return $this->zips[] = $path;
    }

    protected function pack(array $manifest = [], array $files = []): array
    {
        return [
            'theme.json' => json_encode($manifest + [
                'key' => 'tide', 'name' => 'Tide', 'version' => '1.0', 'author' => 'Hana',
                'css' => ['tide.css'],
                'fonts' => ['brine' => ['name' => 'Brine', 'stack' => "'Brine', Georgia, serif", 'use' => ['titles']]],
                'papers' => ['foam' => ['name' => 'Foam', 'paper' => '#F2F7F8', 'paper_2' => '#E6EEEF', 'rule' => '#D6E2E4', 'rule_2' => '#BFD0D3', 'ink' => '#16232A']],
                'options' => [
                    'waves' => ['type' => 'toggle', 'label' => 'Waves', 'default' => true, 'class' => 'tide-waves'],
                    'shape' => ['type' => 'select', 'label' => 'Shape', 'options' => ['round' => 'Round', 'square' => 'Square'], 'default' => 'round'],
                    'link' => ['type' => 'color', 'label' => 'Links', 'default' => '#1F6F8B', 'variable' => 'tide-link'],
                ],
                'presets' => ['calm' => ['name' => 'Calm', 'title_font' => 'brine', 'label_font' => 'inter', 'accent' => '#2B7A99', 'paper' => 'foam', 'header' => 'centered', 'home' => 'list']],
            ]),
            'assets/tide.css' => "@font-face { font-family: 'Brine'; src: url(\"fonts/brine.woff2\") format('woff2'); }\n.theme-tide .masthead { background: url(wave.svg); scroll-behavior: smooth; }",
            'assets/fonts/brine.woff2' => 'wOF2-not-really',
            'assets/wave.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><path d="M0 0h10"/></svg>',
            'README.md' => '# Tide',
            ...$files,
        ];
    }

    protected function installPack(array $manifest = [], array $files = []): void
    {
        $installer = app(ThemeInstaller::class);
        $installer->install($installer->stage($this->zip($this->pack($manifest, $files)), ['type' => 'upload']));
    }

    protected function wear(array $look): void
    {
        Look::save($look);
        ThemeManager::forget();
    }

    public function test_a_cover_pack_is_checked_unpacked_and_worn(): void
    {
        $installer = app(ThemeInstaller::class);
        $token = $installer->stage($this->zip($this->pack(), 'booklet-tide-main/'), ['type' => 'upload']);
        $meta = $installer->staged($token);
        $this->assertSame('tide', $meta['key']);
        $this->assertFalse($meta['code'], 'styles, fonts and pictures only: a cover pack');
        $this->assertSame(['Calm'], $meta['presets']);
        $this->assertSame([], $meta['problems']);
        $this->assertDirectoryDoesNotExist(ThemeManager::path().'/tide', 'nothing changes until yes');

        $installer->install($token);
        $theme = ThemeManager::find('tide');
        $this->assertNotNull($theme);
        $this->assertStringNotContainsString('script', (string) file_get_contents($theme->path.'/assets/wave.svg'), 'pictures are cleaned on the way in');
        $this->assertStringNotContainsString('onload', (string) file_get_contents($theme->path.'/assets/wave.svg'));

        $this->wear(['theme' => 'tide', 'options' => ['shape' => 'square', 'link' => '#aa3300']]);
        $look = Look::current();
        $this->assertSame('calm', $look['preset']);
        $this->assertSame('theme-brine', $look['title_font']);
        $this->assertSame('theme-foam', $look['paper']);

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('themes/tide/assets/tide.css', $home);
        $this->assertStringContainsString('booklet/site.css', $home, 'a cover pack dresses Booklet’s own templates');
        $this->assertMatchesRegularExpression('/<body class="[^"]*theme-tide[^"]*tide-waves[^"]*shape-square/', $home);
        $this->assertStringContainsString('--tide-link: #AA3300', $home);
        $this->assertStringContainsString("--serif: 'Brine', Georgia, serif", $home);
        $this->assertStringContainsString('--paper: #F2F7F8', $home);

        $this->get('/themes/tide/assets/tide.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('/themes/tide/assets/wave.svg')->assertOk()->assertHeader('Content-Security-Policy');
        $this->get('/themes/tide/assets/../theme.json')->assertNotFound();
        $this->get('/themes/nope/assets/tide.css')->assertNotFound();
    }

    public function test_a_cover_pack_can_only_change_how_things_look(): void
    {
        $installer = app(ThemeInstaller::class);
        $refused = [
            '@import url(other.css);' => '@import',
            '.a { background: url(https://tracker.example/p.gif); }' => 'isn’t a file in the pack',
            '.a { background: url(//tracker.example/p.gif); }' => 'isn’t a file in the pack',
            '.a { background: \\75 rl(https://tracker.example/p.gif); }' => 'isn’t a file in the pack',
            '.a { background: url(../../secret.png); }' => 'isn’t a file in the pack',
            '.a { behavior: url(x.htc); }' => 'behavior',
            '.a { width: expression(alert(1)); }' => 'expression',
            '.a { background-image: image-set("https://tracker.example/a.png" 1x); }' => 'another website',
        ];
        foreach ($refused as $css => $why) {
            try {
                $installer->stage($this->zip($this->pack([], ['assets/tide.css' => $css])), ['type' => 'upload']);
                $this->fail('Should have refused: '.$css);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage(), $css);
            }
        }

        // Its own files, a picture written into the CSS, and ../ that stays inside: fine.
        $token = $installer->stage($this->zip($this->pack([], [
            'assets/css/tide.css' => ".a { background: url('../wave.svg'), url(data:image/png;base64,AAAA); } .b::before { content: '// '; }",
        ])), ['type' => 'upload']);
        $this->assertNotNull($installer->staged($token));

        foreach ([
            'install.php' => '<?php echo 1;' ,
            'views/livewire/admin/settings.blade.php' => 'x',
            'views/site/post.blade.php' => '@if (true) <p>never closed',
            'assets/tracker.js' => 'fetch("/admin")',
            'assets/run.php' => '<?php',
        ] as $file => $contents) {
            try {
                $installer->stage($this->zip($this->pack([], [$file => $contents])), ['type' => 'upload']);
                $this->fail('Should have refused '.$file);
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_theme_with_templates_takes_a_password_and_its_pages_win(): void
    {
        $files = [
            'views/components/site/layout.blade.php' => <<<'BLADE'
                @props(['title' => null, 'description' => null, 'image' => null, 'imageAlt' => null, 'type' => 'website', 'robots' => null])
                <x-site.page :title="$title" :description="$description" :image="$image" :type="$type" body-class="tide-layout">
                    <header class="tide-top"><x-site.brand /><x-site.menu /><x-site.tools /></header>
                    <main id="main">{{ $slot }}</main>
                    <x-site.footer />
                </x-site.page>
                BLADE,
            'views/site/post.blade.php' => '<x-site.layout :title="$entry->title()"><article class="tide-post"><h1>{{ $entry->title() }}</h1>{{ $site->markdown($entry->get(\'content\')) }}<x-site.comments :entry="$entry" /></article></x-site.layout>',
        ];
        $installer = app(ThemeInstaller::class);
        $token = $installer->stage($this->zip($this->pack(['key' => 'tide'], $files)), ['type' => 'upload']);
        $meta = $installer->staged($token);
        $this->assertTrue($meta['code']);
        $this->assertSame(['components.site.layout', 'site.post'], $meta['templates']);

        // Code: their password first.
        $page = Livewire::actingAs($this->admin)->withQueryParams(['staged' => $token])->test(CoverDesigner::class)
            ->assertSee('code that runs')
            ->call('install')->assertHasErrors('password')
            ->set('password', 'wrong')->call('install')->assertHasErrors('password');
        $this->assertNull(ThemeManager::find('tide'));
        $page->set('password', 'password')->call('install')->assertHasNoErrors();
        $this->assertNotNull(ThemeManager::find('tide'));
        $this->assertSame('tide', $page->get('look')['theme'], 'shown in the preview straight away');

        $page->call('save');
        ThemeManager::forget();

        $post = app(\App\Booklet\Site::class)->posts()->first();
        $html = $this->get(app(\App\Booklet\Site::class)->url($post))->assertOk()->getContent();
        $this->assertStringContainsString('class="tide-post"', $html, 'the theme’s template');
        $this->assertStringContainsString('tide-top', $html, 'the theme’s layout');
        $this->assertStringContainsString('application/ld+json', $html, 'Booklet’s pieces come along');
        $this->assertStringContainsString('tide-layout', $html);
        // A page it doesn't bring its own template for: Booklet's, in its layout.
        $this->assertStringContainsString('tide-top', $this->get('/about')->assertOk()->getContent());

        // Safe mode: worn as a cover pack.
        config(['booklet.safe_mode' => true]);
        $this->assertStringNotContainsString('tide-post', $this->get(app(\App\Booklet\Site::class)->url($post))->getContent());
    }

    public function test_a_broken_template_falls_back_to_booklets_own_and_is_reported(): void
    {
        $this->installPack(['key' => 'tide'], ['views/site/post.blade.php' => '<x-site.layout>{{ $entry->nothingHere() }}</x-site.layout>']);
        $this->wear(['theme' => 'tide']);

        $post = app(\App\Booklet\Site::class)->posts()->first();
        $response = $this->get(app(\App\Booklet\Site::class)->url($post));
        $response->assertOk()->assertSee($post->title())->assertHeader('X-Booklet-Theme');
        $this->assertSame(1, substr_count((string) $response->getContent(), 'application/ld+json'));

        $error = ThemeManager::error(ThemeManager::find('tide'));
        $this->assertSame('views/site/post.blade.php', $error['file']);
        $this->assertStringContainsString('nothingHere', $error['message']);

        $check = collect(\App\Support\Health::run())->firstWhere('key', 'theme');
        $this->assertSame('warn', $check['state']);
        Livewire::actingAs($this->admin)->test(CoverDesigner::class)->assertSee('Readers were shown Booklet’s own page instead');
    }

    public function test_options_presets_and_falling_back_when_a_theme_goes(): void
    {
        $this->installPack();
        $look = Look::clean(['theme' => 'tide', 'options' => ['waves' => false, 'shape' => 'hexagon', 'link' => 'red']]);
        $this->assertSame(['waves' => false, 'shape' => 'round', 'link' => '#1F6F8B'], $look['options']);
        $this->assertStringNotContainsString('tide-waves', Look::bodyClasses($look));

        $this->assertSame('booklet', Look::clean(['theme' => 'gone', 'preset' => 'calm'])['theme']);
        $this->assertSame('notebook', Look::clean(['theme' => 'gone', 'preset' => 'calm'])['preset']);
        $this->assertSame('Tide · Calm', Look::presetName($look));

        // The one the site wears can't be taken off the shelf; another can.
        $this->wear(['theme' => 'tide']);
        try {
            app(ThemeInstaller::class)->remove('tide');
            $this->fail('Removed the theme the site wears.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Choose another cover first', $e->getMessage());
        }
        $this->wear(['theme' => 'booklet']);
        app(ThemeInstaller::class)->remove('tide');
        $this->assertNull(ThemeManager::find('tide'));
    }

    public function test_an_update_can_be_undone(): void
    {
        $this->installPack(['version' => '1.0']);
        $this->installPack(['version' => '1.1', 'name' => 'Tide Two']);
        $this->assertSame('1.1', ThemeManager::find('tide')->version());
        $this->assertSame('1.0', app(ThemeInstaller::class)->previousVersion('tide'));

        app(ThemeInstaller::class)->undo('tide');
        ThemeManager::forget();
        $this->assertSame('Tide', ThemeManager::find('tide')->name());
    }

    public function test_make_theme_starts_one_that_works(): void
    {
        $this->artisan('booklet:make-theme', ['name' => 'Lighthouse'])->assertSuccessful();
        $theme = ThemeManager::find('lighthouse');
        $this->assertNotNull($theme);
        $this->assertSame([], $theme->problems);
        $this->assertFalse($theme->hasCode());
        $this->assertNull(ThemeInstaller::checkCss((string) file_get_contents($theme->path.'/assets/lighthouse.css'), $theme->path.'/assets', 'lighthouse.css'));

        $this->artisan('booklet:make-theme', ['name' => 'Folio', '--templates' => true])->assertSuccessful();
        ThemeManager::forget();
        $folio = ThemeManager::find('folio');
        $this->assertTrue($folio->hasCode());
        $this->assertContains('site.post', $folio->templates());
        $this->assertSame([], $folio->strayTemplates());

        // Its copies work as they are.
        $this->wear(['theme' => 'folio']);
        $post = app(\App\Booklet\Site::class)->posts()->first();
        $this->get(app(\App\Booklet\Site::class)->url($post))->assertOk()->assertHeaderMissing('X-Booklet-Theme');
    }

    public function test_harbour_the_pack_that_comes_with_booklet_passes_its_own_checks(): void
    {
        File::copyDirectory(base_path('themes/harbour'), ThemeManager::path().'/harbour');
        ThemeManager::forget();
        $harbour = ThemeManager::find('harbour');
        $this->assertNotNull($harbour);
        $this->assertSame([], $harbour->problems);
        $this->assertFalse($harbour->hasCode());
        $this->assertNotNull($harbour->previewUrl());
        $this->assertNull(ThemeInstaller::checkCss((string) file_get_contents($harbour->path.'/assets/harbour.css'), $harbour->path.'/assets', 'harbour.css'));
        $this->assertCount(2, $harbour->presets());

        $this->wear(['theme' => 'harbour', 'preset' => 'low-tide']);
        $this->assertStringContainsString('family=Literata', Look::fontsUrl(Look::current()));
        $this->get('/')->assertOk()->assertSee('themes/harbour/assets/harbour.css', false);
    }

    public function test_share_packs_a_theme_or_insert_that_would_be_accepted(): void
    {
        $this->installPack();
        $to = sys_get_temp_dir().'/booklet-share-'.uniqid();
        try {
            $this->artisan('booklet:share', ['name' => 'tide', '--to' => $to])->assertSuccessful()->expectsOutputToContain('booklet-theme');
            $this->assertFileExists($to.'/booklet-tide-1.0.zip');
            // …and it goes back in as it came out.
            $installer = app(ThemeInstaller::class);
            $meta = $installer->staged($token = $installer->stage($to.'/booklet-tide-1.0.zip', ['type' => 'upload']));
            $this->assertSame('tide', $meta['key']);
            $installer->discard($token);

            config(['booklet.inserts_path' => base_path('inserts')]);
            $this->artisan('booklet:share', ['name' => 'events', '--to' => $to])->assertSuccessful()->expectsOutputToContain('booklet-insert');
            $this->assertFileExists($to.'/booklet-events-1.0.zip');

            // One that wouldn't be accepted isn't packed.
            file_put_contents(ThemeManager::path().'/tide/assets/tide.css', '@import url(https://x.example/a.css);');
            $this->artisan('booklet:share', ['name' => 'tide', '--to' => $to])->assertFailed();
            $this->assertFileDoesNotExist($to.'/booklet-tide-1.0.zip');
            $this->artisan('booklet:share', ['name' => 'nothing'])->assertFailed();
        } finally {
            File::deleteDirectory($to);
        }
    }

    public function test_the_share_guide_in_the_cover_and_on_the_shelf(): void
    {
        $this->installPack();
        Livewire::actingAs($this->admin)->test(CoverDesigner::class)->call('wear', 'tide')
            ->assertSee('Share Tide')->assertSee('booklet-theme')->assertSee('github.com/new?name=booklet-tide', false)
            ->assertSee(route('admin.share.download', ['theme', 'tide']), false);

        $response = $this->actingAs($this->admin)->get(route('admin.share.download', ['theme', 'tide']))->assertOk();
        $this->assertStringContainsString('booklet-tide-1.0.zip', (string) $response->headers->get('Content-Disposition'));

        config(['booklet.inserts_path' => base_path('inserts')]);
        app(\App\Inserts\InsertManager::class)->forget();
        $this->actingAs($this->admin)->get(route('admin.settings.inserts', ['insert' => 'events']))->assertOk()
            ->assertSee('Share Events')->assertSee('booklet-insert');
        $this->actingAs($this->admin)->get(route('admin.share.download', ['insert', 'events']))->assertOk();

        $this->actingAs($this->admin)->from('/admin/settings/cover')->get(route('admin.share.download', ['theme', 'nope']))->assertRedirect('/admin/settings/cover');
        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($writer)->get(route('admin.share.download', ['theme', 'tide']))->assertForbidden();
    }

    public function test_something_sent_is_never_made_twice_when_a_template_breaks(): void
    {
        $this->installPack(['key' => 'tide'], ['views/site/sent.blade.php' => '<p>{{ $nothing->here() }}</p>']);
        $this->wear(['theme' => 'tide']);
        $made = 0;
        \Illuminate\Support\Facades\Route::middleware('web')->match(['GET', 'POST'], '/test-sent', function () use (&$made) {
            $made++;

            $theirs = implode('.', ['site', 'sent']); // only the theme has it

            // @phpstan-ignore method.impossibleType, argument.type (a view only the theme brings, so Larastan can't know it)
            return \App\Themes\ThemeManager::applied() && app('view')->exists($theirs) ? app('view')->make($theirs) : 'Booklet’s own';
        });

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)->post('/test-sent')->assertStatus(500);
        $this->assertSame(1, $made, 'a form sent is never sent again');
        $this->assertNotNull(ThemeManager::error(ThemeManager::find('tide')), 'still noted for The cover');

        $made = 0;
        $this->get('/test-sent')->assertOk()->assertSee('Booklet’s own')->assertHeader('X-Booklet-Theme');
        $this->assertSame(2, $made, 'a page is made again without the theme');
    }

    public function test_odd_looks_and_reserved_names(): void
    {
        // A folder called "booklet" isn't a theme: that's Booklet's own cover.
        File::ensureDirectoryExists(ThemeManager::path().'/booklet');
        file_put_contents(ThemeManager::path().'/booklet/theme.json', json_encode(['name' => 'Not Booklet']));
        ThemeManager::forget();
        $this->assertNull(ThemeManager::find('booklet'));

        // Whatever arrives, only plain values are read.
        $look = Look::clean(['theme' => ['x'], 'preset' => ['y'], 'accent' => ['z'], 'options' => 'nope', 'tilt' => ['a']]);
        $this->assertSame('booklet', $look['theme']);
        $this->assertSame('notebook', $look['preset']);
        $this->installPack();
        $this->assertSame('round', Look::clean(['theme' => 'tide', 'options' => ['shape' => ['round']]])['options']['shape']);
    }

    public function test_a_theme_for_a_newer_booklet_says_so(): void
    {
        // Put in by hand (adding one from The cover refuses it), or Booklet went back a version.
        $this->installPack();
        $json = ThemeManager::path().'/tide/theme.json';
        file_put_contents($json, json_encode(['needs' => '9.0'] + json_decode((string) file_get_contents($json), true)));
        ThemeManager::forget();
        Livewire::actingAs($this->admin)->test(CoverDesigner::class)
            ->assertSee('Needs Booklet 9.0')
            ->call('wear', 'tide')->assertDispatched('stamp', text: 'Tide needs Booklet 9.0 or newer; this is '.config('booklet.version').'. An update of Booklet lets the site wear it.')
            ->assertSet('look.theme', 'booklet')->assertSet('dirty', false);
    }

    public function test_updating_the_theme_in_the_preview_keeps_your_changes(): void
    {
        $this->installPack(['version' => '1.0']);
        $page = Livewire::actingAs($this->admin)->test(CoverDesigner::class)->call('wear', 'tide')
            ->set('look.accent', '#AA3300')->set('look.options.waves', false);

        $installer = app(ThemeInstaller::class);
        $token = $installer->stage($this->zip($this->pack(['version' => '1.1'])), ['type' => 'upload']);
        $page->set('staged', $token)->call('install')->assertHasNoErrors();

        $this->assertSame('#AA3300', $page->get('look')['accent']);
        $this->assertFalse($page->get('look')['options']['waves']);
        $this->assertTrue($page->get('dirty'));
        $this->assertSame('1.1', ThemeManager::find('tide')->version());
    }

    public function test_an_edit_to_the_worn_themes_files_reaches_visitors(): void
    {
        $this->installPack();
        $this->wear(['theme' => 'tide']);
        $before = \App\Support\PageCache::fingerprint();

        touch(ThemeManager::path().'/tide/assets/tide.css', time() + 60);
        request()->attributes->remove('booklet.pagecache.print');
        $this->assertNotSame($before, \App\Support\PageCache::fingerprint(), 'kept pages are made again with the new styles');
    }

    public function test_one_added_from_github_is_shared_by_where_it_came_from(): void
    {
        $this->installPack();
        $sources = StaticSite::privatePath('theme-sources.json');
        file_put_contents($sources, json_encode(['tide' => ['type' => 'github', 'repo' => 'hana/booklet-tide']]));

        Livewire::actingAs($this->admin)->test(CoverDesigner::class)->call('wear', 'tide')
            ->assertSee('This came from GitHub')->assertSee('hana/booklet-tide')->assertDontSee('Open GitHub: new repository');
    }

    public function test_plain_files_carry_the_theme(): void
    {
        $this->installPack();
        $this->wear(['theme' => 'tide']);
        $out = sys_get_temp_dir().'/booklet-theme-static-'.uniqid();
        try {
            StaticSite::build('https://you.github.io/kopi', $out);
            $this->assertFileExists($out.'/themes/tide/assets/tide.css');
            $this->assertFileExists($out.'/themes/tide/assets/wave.svg', 'what its styles use comes along');
            $this->assertFileExists($out.'/themes/tide/assets/fonts/brine.woff2');
            $this->assertStringContainsString('https://you.github.io/kopi/themes/tide/assets/tide.css', (string) file_get_contents($out.'/index.html'));
        } finally {
            File::deleteDirectory($out);
        }
    }
}
