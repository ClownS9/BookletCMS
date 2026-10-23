<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\StaticPublishing;
use App\Models\User;
use App\Support\StaticSite;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Publishing the site as plain files: every page made as a visitor sees it,
 * written for the published address, with what needs a server swapped for
 * something that doesn't; and sent to GitHub Pages.
 */
class StaticSiteTest extends TestCase
{
    use RefreshDatabase;

    protected string $out;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        User::factory()->create(['role' => 'admin']); // a site with no one yet sends every page to first setup
        $this->out = rtrim(sys_get_temp_dir(), '/').'/booklet-static-'.uniqid();
        @unlink(\App\Support\StaticSite::privatePath('static-site.json'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        File::deleteDirectory($this->out.'-remote.git');
        File::deleteDirectory($this->out.'-repo');
        @unlink(\App\Support\StaticSite::privatePath('static-site.json'));
        parent::tearDown();
    }

    protected function read(string $file): string
    {
        $this->assertFileExists($this->out.'/'.$file);

        return (string) file_get_contents($this->out.'/'.$file);
    }

    public function test_the_whole_site_as_files_for_its_published_address(): void
    {
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        Booklet::entries()->make(['collection' => 'posts', 'slug' => 'not-yet', 'published' => false], ['title' => 'Not yet'])->save();

        $before = url('/');
        $made = StaticSite::build('https://you.github.io/kopi', $this->out);

        $this->assertGreaterThanOrEqual(4, $made['pages']);
        foreach (['index.html', 'blog/index.html', 'about/index.html', '404.html', 'search/index.html', 'search-index.json', 'feed.xml', 'sitemap.xml', 'robots.txt', '.nojekyll', 'booklet/site.css', 'booklet/site.js'] as $file) {
            $this->assertFileExists($this->out.'/'.$file, $file);
        }
        $this->assertFileExists($this->out.'/'.trim((string) parse_url(app(\App\Booklet\Site::class)->url($post), PHP_URL_PATH), '/').'/index.html');
        $this->assertFileDoesNotExist($this->out.'/blog/not-yet/index.html', 'drafts stay out');

        $home = $this->read('index.html');
        $this->assertStringContainsString('https://you.github.io/kopi/booklet/site.css', $home, 'every address is the published one');
        $this->assertStringNotContainsString($before.'/', $home, 'no links to the address Booklet runs at');
        $this->assertStringContainsString('data-static', $home);
        $this->assertStringNotContainsString('wire:id', $this->read('blog/index.html'), 'no forms that need a server');
        $this->assertStringContainsString('data-static-search', $this->read('search/index.html'));
        $this->assertStringContainsString('href="https://you.github.io/kopi/blog/category/news"', $this->read('blog/index.html'), 'category chips go to the category’s own page');
        $this->assertStringNotContainsString('?category=', $this->read('blog/index.html'));
        $this->assertStringContainsString('Hello from Booklet', $this->read('search-index.json'));

        // Afterwards, Booklet's own addresses are back to normal.
        $this->assertSame($before, url('/'));
    }

    public function test_paged_lists_become_folders(): void
    {
        foreach (range(1, 12) as $n) {
            Booklet::entries()->make(['collection' => 'posts', 'slug' => 'note-'.$n, 'date' => now()->subDays($n)->format('Y-m-d H:i:s')], ['title' => 'Note '.$n])->save();
        }

        StaticSite::build('https://kopikuih.example', $this->out);

        $this->assertStringContainsString('href="https://kopikuih.example/blog/page/2/"', $this->read('blog/index.html'));
        $this->assertStringContainsString('href="https://kopikuih.example/blog/"', $this->read('blog/page/2/index.html'), 'back to page 1');
    }

    public function test_it_goes_to_the_gh_pages_branch_and_leaves_yours_alone(): void
    {
        $repo = $this->out.'-repo';
        File::ensureDirectoryExists($repo);
        Process::path($repo)->run(['git', 'init', '-q', '-b', 'main']);
        file_put_contents($repo.'/README.md', '# Mine');
        Process::path($repo)->run(['git', 'add', '.']);
        Process::path($repo)->run(['git', '-c', 'user.name=Me', '-c', 'user.email=me@example.com', 'commit', '-qm', 'Mine']);
        Process::run(['git', 'init', '-q', '--bare', $this->out.'-remote.git']);
        Process::path($repo)->run(['git', 'remote', 'add', 'origin', $this->out.'-remote.git']);
        Process::path($repo)->run(['git', 'push', '-q', 'origin', 'main']);
        config(['booklet.git.path' => $repo]);

        StaticSite::build('https://you.github.io/kopi', StaticSite::folder());
        StaticSite::sendToGithub();
        StaticSite::sendToGithub(); // again: still one commit on gh-pages

        $git = fn (...$args) => trim(Process::run(['git', '--git-dir='.$this->out.'-remote.git', ...$args])->output());
        $this->assertStringContainsString('index.html', $git('ls-tree', '--name-only', 'gh-pages'));
        $this->assertSame('1', $git('rev-list', '--count', 'gh-pages'));
        $this->assertSame('Mine', $git('log', '-1', '--format=%s', 'main'), 'your own branch is untouched');
        $this->assertNotNull(StaticSite::last()['sent']);
        $this->assertDirectoryDoesNotExist(StaticSite::folder().'/.git');
        File::deleteDirectory(StaticSite::folder());
    }

    public function test_the_settings_page_publishes_in_a_separate_run(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Process::fake();

        Livewire::actingAs($admin)->test(StaticPublishing::class)
            ->assertSee('Publish as plain files')
            ->set('address', 'not an address')->call('save')->assertHasErrors('address')
            ->set('address', 'https://kopikuih.example')->set('auto', true)->call('publish')->assertHasNoErrors();

        Process::assertRan(fn ($process) => in_array('booklet:publish-static', (array) $process->command, true));
        $this->assertSame('https://kopikuih.example', StaticSite::settings()['address']);

        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($writer)->get(route('admin.settings.static'))->assertForbidden();
    }

    public function test_it_publishes_again_once_things_go_quiet(): void
    {
        StaticSite::saveSettings(['auto' => true]);
        StaticSite::changed();
        $this->assertFalse(StaticSite::due());

        $this->travel(3)->minutes();
        $this->assertTrue(StaticSite::due());

        StaticSite::saveSettings(['auto' => false]);
        $this->assertFalse(StaticSite::due());
        Cache::forget(StaticSite::DIRTY);
    }

    public function test_github_pages_address_from_the_connected_repository(): void
    {
        $repo = $this->out.'-repo';
        File::ensureDirectoryExists($repo);
        Process::path($repo)->run(['git', 'init', '-q']);
        config(['booklet.git.path' => $repo]);
        $this->assertNull(StaticSite::githubPagesAddress());

        Process::path($repo)->run(['git', 'remote', 'add', 'origin', 'https://github.com/Hana/kopi-kuih.git']);
        $this->assertSame('https://hana.github.io/kopi-kuih', StaticSite::githubPagesAddress());

        Process::path($repo)->run(['git', 'remote', 'set-url', 'origin', 'git@github.com:hana/hana.github.io.git']);
        $this->assertSame('https://hana.github.io', StaticSite::githubPagesAddress(), 'a user site lives at the root');

        StaticSite::saveSettings(['github' => true]);
        $this->assertSame('https://hana.github.io', StaticSite::address());
        StaticSite::saveSettings(['domain' => 'kopikuih.com']);
        $this->assertSame('https://kopikuih.com', StaticSite::address(), 'your own domain wins');
    }
}
