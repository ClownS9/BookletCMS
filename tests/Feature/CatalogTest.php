<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\CoverDesigner;
use App\Livewire\Admin\Inserts;
use App\Models\User;
use App\Support\Catalog;
use App\Support\SafeFetch;
use App\Support\StaticSite;
use App\Themes\ThemeManager;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Finding themes and inserts on GitHub from inside Booklet: repositories
 * with the topic booklet-theme or booklet-insert, described from their own
 * theme.json or insert.php, and looked inside before anything is added.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        Cache::flush();
        File::deleteDirectory(ThemeManager::path());
        ThemeManager::forget();
        SafeFetch::$resolve = fn () => ['140.82.112.6'];
    }

    protected function tearDown(): void
    {
        SafeFetch::$resolve = null;
        File::deleteDirectory(ThemeManager::path());
        File::deleteDirectory(StaticSite::privatePath('theme-staging'));
        parent::tearDown();
    }

    protected function repo(string $full, int $stars = 3): array
    {
        return ['full_name' => $full, 'name' => explode('/', $full)[1], 'description' => 'From GitHub', 'owner' => ['login' => explode('/', $full)[0]],
            'stargazers_count' => $stars, 'pushed_at' => now()->subDays(2)->toIso8601String(), 'default_branch' => 'main', 'license' => ['spdx_id' => 'MIT']];
    }

    protected function fakeGithub(): void
    {
        Http::fake([
            'api.github.com/search/repositories*' => Http::response(['total_count' => 2, 'items' => [
                $this->repo('hana/booklet-tide', 12),
                $this->repo('sam/booklet-empty'),
                ['full_name' => 'not a repo'],
            ]]),
            'raw.githubusercontent.com/hana/booklet-tide/main/theme.json' => Http::response(json_encode([
                'key' => 'tide', 'name' => 'Tide', 'version' => '1.2', 'about' => 'Sea-glass blues.', 'author' => 'Hana', 'preview' => 'preview.png',
            ])),
            'raw.githubusercontent.com/*' => Http::response('Not Found', 404),
        ]);
    }

    public function test_it_finds_themes_by_their_topic_and_reads_each_one(): void
    {
        $this->fakeGithub();
        $found = Catalog::search('theme', 'blue sea; <script>');

        $this->assertNull($found['error']);
        $this->assertCount(2, $found['items'], 'only real repository names');
        [$tide, $empty] = $found['items'];
        $this->assertSame('Tide', $tide['name']);
        $this->assertSame('1.2', $tide['version']);
        $this->assertSame('https://raw.githubusercontent.com/hana/booklet-tide/main/assets/preview.png', $tide['preview']);
        $this->assertTrue($tide['readable']);
        $this->assertFalse($empty['readable'], 'no theme.json: can’t be added');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.github.com/search/repositories')
            && str_contains(urldecode($request->url()), 'topic:booklet-theme archived:false blue sea')
            && ! str_contains(urldecode($request->url()), '<script>'));

        // Kept for a while: the same search doesn't ask GitHub again.
        Catalog::search('theme', 'blue sea; <script>');
        Http::assertSentCount(3); // one search, two theme.json files
    }

    public function test_github_saying_slow_down_is_said_plainly(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'rate limited'], 403, ['X-RateLimit-Reset' => (string) now()->addMinutes(20)->timestamp])]);
        $found = Catalog::search('insert');
        $this->assertSame([], $found['items']);
        $this->assertStringContainsString('enough searches', $found['error']);
        $this->assertStringContainsString('BOOKLET_GITHUB_TOKEN', $found['error']);
    }

    public function test_the_cover_shows_them_and_looks_inside_one(): void
    {
        $this->fakeGithub();
        $zip = tempnam(sys_get_temp_dir(), 'tide').'.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('hana-booklet-tide-abc/theme.json', json_encode(['key' => 'tide', 'name' => 'Tide', 'version' => '1.2']));
        $z->addFromString('hana-booklet-tide-abc/assets/tide.css', '.theme-tide .masthead { border: 0; }');
        $z->close();
        Http::fake([
            'api.github.com/repos/hana/booklet-tide/releases/latest' => Http::response(['tag_name' => '1.2', 'zipball_url' => 'https://api.github.com/repos/hana/booklet-tide/zipball/1.2']),
            'api.github.com/repos/hana/booklet-tide/zipball/*' => Http::response((string) file_get_contents($zip)),
        ]);

        $page = Livewire::actingAs($this->admin)->test(CoverDesigner::class)
            ->call('startAdding')
            ->assertSee('Find themes on GitHub')->assertSee('Tide')->assertSee('Sea-glass blues.')->assertSee('No theme.json found');

        $page->call('lookAt', 'hana/booklet-tide')->assertHasNoErrors();
        $this->assertNotSame('', $page->get('staged'), 'unpacked to one side, waiting for a yes');
        $page->assertSee('Waiting for a yes')->assertSee('A cover pack');
        $this->assertNull(ThemeManager::find('tide'), 'nothing added yet');

        $page->call('lookAt', 'not/a/repo name')->assertSet('staged', $page->get('staged'));
        @unlink($zip);
    }

    public function test_the_inserts_shelf_shows_them_and_what_you_have(): void
    {
        config(['booklet.inserts_path' => base_path('inserts')]);
        Http::fake([
            'api.github.com/search/repositories*' => Http::response(['total_count' => 1, 'items' => [$this->repo('booklet/booklet-events')]]),
            'raw.githubusercontent.com/booklet/booklet-events/main/insert.php' => Http::response("<?php\nreturn ['name' => 'Events', 'version' => '9.0', 'about' => 'Things happening.', 'needs' => '1.0'];"),
        ]);

        Livewire::actingAs($this->admin)->test(Inserts::class)->call('startAdding')
            ->assertSee('Find inserts on GitHub')->assertSee('Things happening.')
            ->assertSee('Newer than yours (1.0)')->assertSee('Look at the update')->assertSee('Code: asks for your password');
    }
}
