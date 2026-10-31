<?php

namespace Tests\Feature;

use App\Livewire\Admin\Contents;
use App\Livewire\Admin\Updates as UpdatesPage;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\SafeFetch;
use App\Support\Updates;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * "A new version of Booklet is out": the notice, what changed, and the
 * safe update (backup, checked download, swap, database, go back) — run
 * against a pretend Booklet folder, never this one.
 */
class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $root;

    protected string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BookletStarterSeeder::class);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);

        // A pretend older Booklet, with things of the site's own around it.
        $this->root = storage_path('framework/testing/booklet-root-'.uniqid());
        $files = [
            'app/Old.php' => '<?php // 1.0', 'vendor/autoload.php' => '<?php', 'public/index.php' => '<?php // 1.0', 'artisan' => '#!/usr/bin/env php',
            'bootstrap/app.php' => '<?php', 'config/app.php' => '<?php return [];', 'themes/harbour/theme.json' => '{"v":"1.0"}',
            // Not Booklet's: never touched.
            '.env' => 'APP_KEY=base64:secret', 'storage/app/public/kopi.jpg' => 'JPEG', 'content/collections/posts/hello.md' => '# Hello',
            'themes/mine/theme.json' => '{"mine":true}', 'database/database.sqlite' => 'SQLITE', 'public/storage/link.txt' => 'link',
        ];
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$path));
            file_put_contents($this->root.'/'.$path, $contents);
        }
        Updates::$root = $this->root;
        File::deleteDirectory(Updates::dir());
        SafeFetch::$resolve = fn () => ['140.82.112.3'];
        $this->zip = $this->release('9.0');
    }

    protected function tearDown(): void
    {
        Updates::$root = null;
        SafeFetch::$resolve = null;
        File::deleteDirectory($this->root);
        File::deleteDirectory(Updates::dir());
        @unlink($this->zip);
        parent::tearDown();
    }

    /** A release .zip, as booklet:package makes it (or spoilt, to test the checks). */
    protected function release(string $version, array $extra = [], ?string $says = null, bool $whole = true): string
    {
        $path = storage_path('framework/testing/release-'.uniqid().'.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('booklet/booklet.json', json_encode(['version' => $says ?? $version, 'owned' => Updates::OWNED]));
        $files = ['app/New.php' => '<?php // '.$version, 'public/index.php' => '<?php // '.$version, 'artisan' => '#!/usr/bin/env php', 'bootstrap/app.php' => '<?php', 'config/app.php' => '<?php return [];', 'themes/harbour/theme.json' => '{"v":"'.$version.'"}'];
        if ($whole) {
            $files['vendor/autoload.php'] = '<?php';
        }
        foreach ($files + $extra as $file => $contents) {
            $zip->addFromString('booklet/'.$file, $contents);
        }
        $zip->close();

        return $path;
    }

    protected function fakeGitHub(?string $zip = null, ?string $sha = null): void
    {
        $zip ??= $this->zip;
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => 'v9.0', 'name' => 'Booklet 9.0', 'html_url' => 'https://github.com/ClownS9/BookletCMS/releases/tag/v9.0',
                'published_at' => '2026-11-02T08:00:00Z',
                'body' => "## New\n\n- Many posts at once\n- <script>alert(1)</script> fixed",
                'assets' => [['name' => 'booklet-9.0.zip', 'size' => filesize($zip), 'digest' => 'sha256:'.($sha ?? hash_file('sha256', $zip)),
                    'browser_download_url' => 'https://github.com/ClownS9/BookletCMS/releases/download/v9.0/booklet-9.0.zip']],
            ]),
            'github.com/ClownS9/BookletCMS/releases/download/*' => Http::response((string) file_get_contents($zip)),
        ]);
    }

    public function test_a_new_version_is_found_with_what_changed_and_shown_to_admins(): void
    {
        $this->fakeGitHub();
        $this->assertSame('9.0', Updates::check()['version']);
        $notes = Updates::notesHtml(Updates::available());
        $this->assertStringContainsString('<li>Many posts at once</li>', $notes);
        $this->assertStringNotContainsString('<script>', $notes, 'what changed is shown safely');
        $this->assertStringStartsWith('Many posts at once · ', Updates::summary(Updates::available()));
        $this->assertStringNotContainsString('<', Updates::summary(Updates::available()));

        Livewire::actingAs($this->admin)->test(Contents::class)->assertSee('Booklet 9.0 is out')->assertSee('See what changed')
            ->call('notNowUpdate')->assertDontSee('Booklet 9.0 is out');
        $editor = User::factory()->create(['role' => 'editor']);
        Livewire::actingAs($editor)->test(Contents::class)->assertDontSee('Booklet 9.0 is out');

        Livewire::actingAs($this->admin)->test(UpdatesPage::class)->assertSee('Booklet 9.0 is out')->assertSee('Many posts at once')->assertSee('Update to 9.0');
        $this->actingAs($editor)->get(route('admin.settings.updates'))->assertForbidden();
    }

    public function test_an_older_or_same_version_isnt_offered(): void
    {
        Cache::forever(Updates::CACHE, ['version' => '1.0', 'checked_at' => now()->toIso8601String()]);
        $this->assertNull(Updates::available());
        Cache::forever(Updates::CACHE, ['version' => '0.9.9']);
        $this->assertNull(Updates::available());
        Livewire::actingAs($this->admin)->test(UpdatesPage::class)->assertSee('This is the newest Booklet.');
    }

    public function test_updating_backs_up_swaps_only_booklets_files_and_can_go_back(): void
    {
        $this->fakeGitHub();
        $release = Updates::check();

        // Getting ready, a step at a time; the site doesn't change.
        $this->assertSame('backup', Updates::prepare($release, $this->admin));
        $this->assertTrue(\App\Support\Backups::list()->contains(fn ($b) => str_ends_with($b['name'], '-before-update.zip')), 'a backup first');
        $this->assertSame('downloaded', Updates::prepare($release, $this->admin));
        $this->assertSame('checked', Updates::prepare($release, $this->admin));
        $this->assertFileExists($this->root.'/app/Old.php', 'nothing swapped yet');

        $token = Updates::apply($this->admin);
        $this->assertFileExists($this->root.'/app/New.php');
        $this->assertFileDoesNotExist($this->root.'/app/Old.php');
        $this->assertStringContainsString('9.0', (string) file_get_contents($this->root.'/public/index.php'));
        $this->assertStringContainsString('9.0', (string) file_get_contents($this->root.'/themes/harbour/theme.json'));
        // Yours, untouched.
        foreach (['.env' => 'APP_KEY=base64:secret', 'storage/app/public/kopi.jpg' => 'JPEG', 'content/collections/posts/hello.md' => '# Hello',
            'themes/mine/theme.json' => '{"mine":true}', 'database/database.sqlite' => 'SQLITE', 'public/storage/link.txt' => 'link'] as $path => $was) {
            $this->assertSame($was, file_get_contents($this->root.'/'.$path), $path.' is left alone');
        }

        $this->assertSame('9.0', Updates::finish($token, $this->admin));
        $this->assertSame(1, ActivityLog::where('action', 'updated Booklet to')->count());
        $this->assertSame('1.0', Updates::undoable()['from']);
        Livewire::actingAs($this->admin)->test(UpdatesPage::class)->assertSee('Go back to 1.0')->assertSee('Updated from 1.0 to 9.0');

        // Going back: the old files return, yours still untouched.
        $this->assertSame('1.0', Updates::undo($this->admin));
        $this->assertFileExists($this->root.'/app/Old.php');
        $this->assertFileDoesNotExist($this->root.'/app/New.php');
        $this->assertStringContainsString('// 1.0', (string) file_get_contents($this->root.'/public/index.php'));
        $this->assertSame('{"mine":true}', file_get_contents($this->root.'/themes/mine/theme.json'));
        $this->assertNull(Updates::undoable());
        $this->assertSame(1, ActivityLog::where('action', 'went back to Booklet')->count());
    }

    public function test_a_download_that_doesnt_match_github_isnt_used(): void
    {
        $this->fakeGitHub(sha: str_repeat('0', 64));
        $release = Updates::check();
        Updates::prepare($release, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fingerprint');
        try {
            Updates::prepare($release, $this->admin);
        } finally {
            $this->assertFileExists($this->root.'/app/Old.php');
            $this->assertDirectoryDoesNotExist(Updates::dir('staging'));
        }
    }

    public function test_a_release_that_isnt_whole_or_says_another_version_isnt_used(): void
    {
        foreach ([
            [$this->release('9.0', whole: false), 'missing vendor/autoload.php'],
            [$this->release('9.0', says: '8.0'), 'version 8.0, not 9.0'],
            [$this->release('9.0', ['../../evil.php' => '<?php']), 'points outside its folder'],
        ] as [$zip, $why]) {
            try {
                Updates::unpack($zip, '9.0');
                $this->fail('should have refused: '.$why);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            } finally {
                @unlink($zip);
            }
        }
        $this->assertFileDoesNotExist(storage_path('evil.php'));
    }

    public function test_a_copy_kept_with_git_is_updated_with_git(): void
    {
        File::ensureDirectoryExists($this->root.'/.git');
        $this->assertStringContainsString('git pull', (string) Updates::blocked());
        $this->fakeGitHub();
        $this->expectException(RuntimeException::class);
        Updates::prepare(Updates::check(), $this->admin);
    }

    public function test_only_admins_can_swap_files(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post(route('admin.updates.apply'))->assertForbidden();
        $this->actingAs($editor)->post(route('admin.updates.undo'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.updates.finish', ['token' => 'x']))->assertForbidden();

        // Nothing ready: told so, nothing changes.
        $this->actingAs($this->admin)->post(route('admin.updates.apply'))->assertRedirect(route('admin.settings.updates'))->assertSessionHas('stamp');
        $this->actingAs($this->admin)->get(route('admin.updates.finish', ['token' => 'nope']))->assertRedirect(route('admin.settings.updates'));
        $this->assertFileExists($this->root.'/app/Old.php');
    }
}
