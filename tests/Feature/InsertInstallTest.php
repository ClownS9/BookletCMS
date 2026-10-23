<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Inserts\InsertInstaller;
use App\Inserts\InsertManager;
use App\Inserts\ManifestReader;
use App\Livewire\Admin\Inserts as Shelf;
use App\Models\User;
use App\Support\SafeFetch;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Adding inserts from the shelf: a .zip, a link, or GitHub; updates and undo.
 */
class InsertInstallTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $dir;

    protected array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'password' => 'correct-horse-battery']);

        $this->dir = storage_path('framework/testing/inserts-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['booklet.inserts_path' => $this->dir, 'booklet.version' => '1.0']);
        $this->app->forgetInstance(InsertManager::class);
        SafeFetch::$resolve = fn () => ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(storage_path('app/private/insert-staging'));
        File::deleteDirectory(storage_path('app/private/insert-previous'));
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        SafeFetch::$resolve = null;
        parent::tearDown();
    }

    protected function installer(): InsertInstaller
    {
        return app(InsertInstaller::class);
    }

    protected function manifest(string $version = '1.0', string $extra = ''): string
    {
        return <<<PHP
        <?php
        \$helper = function () { return ['name' => 'Not this']; };
        return [
            'name' => 'Recipes',
            'about' => 'Recipe cards with ingredients.',
            'version' => '{$version}',
            'author' => 'Hana',
            'sticker' => 'mint',
            'settings' => ['unit' => ['type' => 'select', 'default' => 'metric', 'options' => ['metric' => 'Metric', 'cups' => 'Cups']]],
            {$extra}
        ];
        PHP;
    }

    /** A .zip with these files in it. */
    protected function zip(array $files, ?array $symlink = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'insert-test-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($symlink) {
            $zip->addFromString($symlink[0], $symlink[1]);
            $zip->setExternalAttributesName($symlink[0], ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
        $zip->close();

        return $this->zips[] = $path;
    }

    public function test_what_an_insert_is_read_without_running_it(): void
    {
        $read = ManifestReader::read($this->manifest('1.4', "'homepage' => 'https://example.com/' . 'recipes',"));

        $this->assertSame('Recipes', $read['name']);
        $this->assertSame('1.4', $read['version']);
        $this->assertSame('Hana', $read['author']);
        $this->assertArrayNotHasKey('homepage', $read); // worked out, not written out: left alone

        $this->expectException(\ParseError::class);
        ManifestReader::read("<?php return ['name' => 'Oops' 'version' => '1'];");
    }

    public function test_a_zip_is_looked_inside_before_anything_changes(): void
    {
        // As GitHub makes them: everything in one folder.
        $zip = $this->zip([
            'hana-booklet-recipes-1a2b3c/insert.php' => $this->manifest(),
            'hana-booklet-recipes-1a2b3c/views/card.blade.php' => '<p>{{ $title }}</p>',
            'hana-booklet-recipes-1a2b3c/.git/config' => 'x',
            '__MACOSX/._insert.php' => 'junk',
        ]);

        $token = $this->installer()->stage($zip, ['type' => 'upload', 'file' => 'recipes.zip']);
        $look = $this->installer()->staged($token);

        $this->assertSame('recipes', $look['key']);
        $this->assertSame('Recipes', $look['name']);
        $this->assertSame(2, $look['files']);
        $this->assertNull($look['replaces']);
        $this->assertTrue($look['fits']);
        $this->assertDirectoryDoesNotExist($this->dir.'/recipes');
    }

    public function test_risky_or_broken_zips_are_turned_away(): void
    {
        $cases = [
            'odd name' => [$this->zip(['insert.php' => $this->manifest(), '../evil.php' => '<?php echo 1;']), 'odd name'],
            'mistake' => [$this->zip(['insert.php' => $this->manifest(), 'src/Thing.php' => '<?php class {']), 'mistake in src/Thing.php'],
            'no insert' => [$this->zip(['readme.md' => 'hi']), 'no insert'],
            'symlink' => [$this->zip(['insert.php' => $this->manifest()], ['secrets', '/etc/passwd']), 'symlink'],
        ];

        foreach ($cases as $label => [$zip, $says]) {
            try {
                $this->installer()->stage($zip, ['type' => 'upload']);
                $this->fail("{$label}: should have been turned away");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($says, $e->getMessage(), $label);
            }
        }
        $this->assertSame([], glob(storage_path('app/private/insert-staging/*'), GLOB_ONLYDIR) ?: []);

        // Too new for this Booklet: shown, but can't be added.
        $token = $this->installer()->stage($this->zip(['insert.php' => $this->manifest('1.0', "'needs' => '9.0',")]), ['type' => 'upload']);
        $this->assertFalse($this->installer()->staged($token)['fits']);
        $this->expectExceptionMessage('needs Booklet 9.0');
        $this->installer()->install($token);
    }

    public function test_adding_updating_and_undoing(): void
    {
        $done = $this->installer()->install($this->installer()->stage($this->zip(['insert.php' => $this->manifest('1.0')]), ['type' => 'url', 'url' => 'https://example.com/recipes.zip']));
        $this->assertFalse($done['updated']);
        $this->assertFileExists($this->dir.'/recipes/insert.php');

        $manager = app(InsertManager::class);
        $recipes = $manager->find('recipes');
        $this->assertFalse($recipes->enabled()); // arrives switched off
        $this->assertSame('example.com/recipes.zip', InsertInstaller::describe($recipes->source()));

        $manager->enable('recipes');
        $manager->saveSettings('recipes', ['unit' => 'cups']);
        $recipes->records('cards')->create(['title' => 'Kuih lapis']);

        // A newer one: switch, settings and records stay; the old copy is kept.
        $done = $this->installer()->install($this->installer()->stage($this->zip(['insert.php' => $this->manifest('1.1')]), ['type' => 'url', 'url' => 'https://example.com/recipes.zip']));
        $this->assertTrue($done['updated']);
        $this->assertSame('1.0', $done['from']);
        $manager->forget();
        $recipes = $manager->find('recipes');
        $this->assertSame('1.1', $recipes->version());
        $this->assertTrue($recipes->active());
        $this->assertSame('cups', $recipes->setting('unit'));
        $this->assertSame(1, $recipes->records('cards')->count());
        $this->assertSame('1.0', $this->installer()->previousVersion('recipes'));

        // It broke after the update: undo puts the old copy back and lets it in again.
        DB::table('inserts')->where('key', 'recipes')->update(['torn_out_at' => now(), 'error' => 'Boom']);
        $this->assertSame('1.0', $this->installer()->undo('recipes'));
        $manager->forget();
        $recipes = $manager->find('recipes');
        $this->assertSame('1.0', $recipes->version());
        $this->assertTrue($recipes->active());
        $this->assertNull($this->installer()->previousVersion('recipes'));
    }

    public function test_github_repositories_and_the_daily_check(): void
    {
        $v1 = file_get_contents($this->zip(['hana-booklet-recipes-aaa/insert.php' => $this->manifest('1.0')]));
        Http::fake([
            'api.github.com/repos/hana/booklet-recipes/releases/latest' => Http::sequence()
                ->push(['tag_name' => 'v1.0', 'zipball_url' => 'https://api.github.com/repos/hana/booklet-recipes/zipball/v1.0'])
                ->push(['tag_name' => 'v1.2', 'zipball_url' => 'https://api.github.com/repos/hana/booklet-recipes/zipball/v1.2']),
            'api.github.com/repos/hana/booklet-recipes/zipball/*' => Http::response($v1),
        ]);

        $source = $this->installer()->parseSource('https://github.com/hana/booklet-recipes.git');
        $this->assertSame(['type' => 'github', 'repo' => 'hana/booklet-recipes'], $source);
        $this->assertSame($source, $this->installer()->parseSource('hana/booklet-recipes'));

        $this->installer()->install($this->installer()->stageFrom($source));
        $this->assertSame('1.0', app(InsertManager::class)->find('recipes')->version());

        $this->assertSame(['recipes' => '1.2'], $this->installer()->check());
        $this->assertSame('1.2', app(InsertManager::class)->find('recipes')->updateReady());

        $this->expectExceptionMessage('isn’t a link');
        $this->installer()->parseSource('just some words');
    }

    public function test_updates_from_github_hold_on_cost_little_and_keep_their_folder(): void
    {
        $v1 = file_get_contents($this->zip(['hana-booklet-recipes-aaa/insert.php' => $this->manifest('1.0')]));
        $renamed = file_get_contents($this->zip(['hana-booklet-recipes-bbb/insert.php' => str_replace("'name' => 'Recipes'", "'name' => 'Recipe box'", $this->manifest('1.3'))]));
        Http::fake([
            'api.github.com/repos/hana/booklet-recipes/releases/latest' => Http::sequence()
                ->push(['message' => 'Not Found'], 404)      // adding it: no releases yet
                ->push(['message' => 'Not Found'], 404)      // the first check
                ->push(['message' => 'Slow down'], 403)      // the second: GitHub's busy
                ->push(['message' => 'Not Found'], 404),     // fetching the update
            'api.github.com/repos/hana/booklet-recipes' => Http::response(['default_branch' => 'main']),
            'codeload.github.com/*' => Http::sequence()->push($v1)->push($renamed),
            'raw.githubusercontent.com/hana/booklet-recipes/HEAD/insert.php' => Http::response(str_replace("'name' => 'Recipes'", "'name' => 'Recipe box'", $this->manifest('1.3'))),
        ]);
        $source = ['type' => 'github', 'repo' => 'hana/booklet-recipes'];
        $this->installer()->install($this->installer()->stageFrom($source));

        // No releases: the version is read from insert.php, without downloading the insert.
        $this->assertSame(['recipes' => '1.3'], $this->installer()->check());
        Http::assertSentCount(5); // adding: release, repository, zip; checking: release, insert.php

        // GitHub busy: the update found before is still there, and Check now says it couldn't reach it.
        $installer = $this->installer();
        $this->assertSame([], $installer->check());
        $this->assertSame(['recipes'], $installer->unreached);
        app(InsertManager::class)->forget();
        $this->assertSame('1.3', app(InsertManager::class)->find('recipes')->updateReady());

        // Renamed since (and no 'key' of its own): it's still the same insert, so it replaces it.
        $meta = $this->installer()->staged($this->installer()->stageFrom($source));
        $this->assertSame('recipes', $meta['key']);
        $this->assertSame('Recipe box', $meta['name']);
        $this->assertSame('1.0', $meta['replaces']['version']);
    }

    public function test_the_shelf_asks_for_a_password_then_adds_it(): void
    {
        Http::fake(['example.com/*' => Http::response(file_get_contents($this->zip(['insert.php' => $this->manifest()])))]);
        $this->actingAs($this->admin);

        $shelf = Livewire::test(Shelf::class)
            ->call('startAdding')
            ->set('source', 'https://example.com/recipes.zip')
            ->call('fetch')
            ->assertHasNoErrors()
            ->assertSee('Adding an insert')
            ->assertSee('Recipes')
            ->assertSee('Only add inserts from people you trust');

        $shelf->set('password', 'wrong')->call('install')->assertHasErrors('password');
        $this->assertDirectoryDoesNotExist($this->dir.'/recipes');

        $shelf->set('password', 'correct-horse-battery')->call('install')->assertHasNoErrors()->assertRedirect();
        $this->assertFileExists($this->dir.'/recipes/insert.php');
        $this->assertDatabaseHas('activity_log', ['action' => 'added the insert']);

        // Given just now, so not asked again for a while.
        /** @var Shelf $shelfPage */
        $shelfPage = Livewire::test(Shelf::class)->instance();
        $this->assertFalse($shelfPage->needsPassword());
    }

    public function test_uploading_a_zip_and_who_may(): void
    {
        $file = new UploadedFile($this->zip(['insert.php' => $this->manifest()]), 'recipes.zip', 'application/zip', null, true);

        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($writer)->post(route('admin.settings.inserts.upload'), ['insert' => $file])->assertForbidden();

        $response = $this->actingAs($this->admin)->post(route('admin.settings.inserts.upload'), ['insert' => $file]);
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('Recipes', $this->installer()->staged($query['look'])['name']);

        config(['booklet.inserts_install' => false]);
        $this->assertStringContainsString('switched off', (string) $this->installer()->blocked());
        $this->actingAs($this->admin)->post(route('admin.settings.inserts.upload'), ['insert' => $file])
            ->assertSessionHasErrors('upload');
    }

    public function test_removing_takes_it_off_the_shelf(): void
    {
        $this->installer()->install($this->installer()->stage($this->zip(['insert.php' => $this->manifest()]), ['type' => 'upload']));
        $this->actingAs($this->admin);

        Livewire::test(Shelf::class, ['chosen' => 'recipes'])
            ->set('chosen', 'recipes')
            ->call('forgetInsert', true);

        $this->assertDirectoryDoesNotExist($this->dir.'/recipes');
        $this->assertDatabaseMissing('inserts', ['key' => 'recipes']);
    }
}
