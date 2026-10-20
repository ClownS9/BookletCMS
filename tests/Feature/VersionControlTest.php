<?php

namespace Tests\Feature;

use App\Livewire\Admin\VersionControl as Page;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\VersionControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Automated version control: the book's files committed to git on their own.
 * Each test works in its own throwaway repository.
 */
class VersionControlTest extends TestCase
{
    use RefreshDatabase;

    protected string $repo;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Process::run(['git', '--version'])->successful()) {
            $this->markTestSkipped('git is needed.');
        }
        // Outside the project, so a test can never commit into the project's own repository.
        $this->repo = rtrim(sys_get_temp_dir(), '/').'/booklet-vc-'.uniqid();
        File::ensureDirectoryExists($this->repo);
        if (trim(Process::path($this->repo)->run(['git', 'rev-parse', '--is-inside-work-tree'])->output()) === 'true') {
            File::deleteDirectory($this->repo);
            $this->markTestSkipped('The temp folder is inside a git repository.');
        }
        File::ensureDirectoryExists($this->repo.'/content/collections/posts');
        File::ensureDirectoryExists($this->repo.'/resources/blueprints/collections/posts');
        File::ensureDirectoryExists($this->repo.'/app');
        $this->write('content/collections/posts/kuih-lapis.md', "---\ntitle: Kuih lapis\n---\nNine layers.");
        $this->write('resources/blueprints/collections/posts/post.yaml', "title: Post\n");
        $this->write('app/Secret.php', '<?php // code, not content');
        // Like a Laravel project: its own .gitignore, and public/storage is a symlink (storage:link).
        $this->write('.gitignore', "/vendor\n/node_modules\n/public/storage\n/public/hot\n.env\n.env.backup\n.phpunit.result.cache\n");
        File::ensureDirectoryExists($this->repo.'/storage/app/public');
        File::ensureDirectoryExists($this->repo.'/public');
        symlink($this->repo.'/storage/app/public', $this->repo.'/public/storage');

        config([
            'booklet.git.path' => $this->repo,
            'booklet.git.enabled' => null,
            'booklet.git.push' => null,
            'booklet.git.paths' => ['content', 'resources/blueprints', 'resources/fieldsets'],
            'booklet.git.committer' => ['name' => 'Booklet', 'email' => 'booklet@kopi.example'],
            'booklet.drivers.entries' => 'file', // as on a real site (the tests' own content is in the database)
        ]);
        @unlink(storage_path('app/private/version-control.json'));
        \Illuminate\Support\Facades\Cache::forget(VersionControl::STATUS);
        \Illuminate\Support\Facades\Cache::forget(VersionControl::FIRST_SEEN);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Nur', 'email' => 'nur@kopi.example']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);
        File::deleteDirectory($this->repo.'-remote.git');
        @unlink(storage_path('app/private/version-control.json'));
        parent::tearDown();
    }

    protected function write(string $path, string $text): void
    {
        File::ensureDirectoryExists(dirname($this->repo.'/'.$path));
        file_put_contents($this->repo.'/'.$path, $text);
    }

    protected function git(string ...$args): string
    {
        return trim(Process::path($this->repo)->run(['git', ...$args])->output());
    }

    protected function start(): void
    {
        Livewire::actingAs($this->admin)->test(Page::class)
            ->assertSee('There’s no history here yet')
            ->call('startHistory')->assertHasNoErrors();
    }

    public function test_starting_a_history_commits_only_the_books_folders(): void
    {
        $this->start();

        $this->assertTrue(VersionControl::isRepo());
        $this->assertTrue(VersionControl::settings()['on']);
        $this->assertSame('The book, as it was when its history began', $this->git('log', '-1', '--format=%s'));
        $this->assertSame('Nur <nur@kopi.example>', $this->git('log', '-1', '--format=%an <%ae>'));
        $this->assertSame('Booklet', $this->git('log', '-1', '--format=%cn'));

        $tracked = explode("\n", $this->git('ls-files'));
        $this->assertContains('content/collections/posts/kuih-lapis.md', $tracked);
        $this->assertContains('resources/blueprints/collections/posts/post.yaml', $tracked);
        $this->assertNotContains('app/Secret.php', $tracked); // code is left alone
    }

    public function test_commits_say_who_did_what(): void
    {
        $this->start();
        $hana = User::factory()->create(['name' => 'Hana', 'email' => 'hana@kopi.example']);

        $this->actingAs($hana);
        $this->write('content/collections/posts/kuih-lapis.md', "---\ntitle: Kuih lapis\n---\nTen layers now.");
        ActivityLog::record('edited', 'edits', 'entry', 'x', 'Kuih lapis');
        $this->assertCount(1, VersionControl::pending());

        $hash = VersionControl::commit();
        $this->assertNotNull($hash);
        $this->assertSame('Hana edited “Kuih lapis”', $this->git('log', '-1', '--format=%s'));
        $this->assertSame('Hana', $this->git('log', '-1', '--format=%an'));
        $this->assertSame([], VersionControl::pending());
        $this->assertNull(VersionControl::commit()); // nothing new

        // Two people: a summary, with the details underneath, in the book's name.
        $this->write('content/collections/posts/pandan.md', "---\ntitle: Pandan\n---\n");
        ActivityLog::record('started a draft', 'edits', 'entry', 'y', 'Pandan');
        $this->actingAs($this->admin);
        ActivityLog::record('published', 'publishing', 'entry', 'y', 'Pandan');
        VersionControl::commit();
        $this->assertSame('2 changes by Hana and Nur', $this->git('log', '-1', '--format=%s'));
        $this->assertStringContainsString('- Nur published “Pandan”', $this->git('log', '-1', '--format=%b'));
        $this->assertSame('Booklet', $this->git('log', '-1', '--format=%an'));

        // Words are only ever words to git.
        $this->write('content/collections/posts/pandan.md', 'x');
        VersionControl::commit('"; rm -rf / #', $this->admin);
        $this->assertSame('"; rm -rf / #', $this->git('log', '-1', '--format=%s'));
        $this->assertFileExists($this->repo.'/app/Secret.php');
    }

    public function test_it_waits_for_a_quiet_moment(): void
    {
        $this->start();

        $this->write('content/collections/posts/kuih-lapis.md', 'changed');
        ActivityLog::record('edited', 'edits', 'entry', 'x', 'Kuih lapis');
        $this->assertFalse(VersionControl::due()); // someone's still at it

        $this->travel(3)->minutes();
        $this->assertTrue(VersionControl::due());
        $this->artisan('booklet:git-commit --when-due')->expectsOutputToContain('Committed')->assertSuccessful();
        $this->assertFalse(VersionControl::due());

        // Switched off: nothing is due, however long it waits.
        VersionControl::saveSettings(['on' => false]);
        $this->write('content/collections/posts/kuih-lapis.md', 'again');
        $this->travel(40)->minutes();
        $this->assertFalse(VersionControl::due());
    }

    public function test_the_history_and_putting_the_book_back(): void
    {
        $this->start();
        $first = $this->git('rev-parse', 'HEAD');

        $this->write('content/collections/posts/kuih-lapis.md', 'Ten layers.');
        $this->write('content/collections/posts/new-one.md', 'Brand new.');
        VersionControl::commit('Second go', $this->admin);

        $history = VersionControl::history();
        $this->assertSame(['Second go', 'The book, as it was when its history began'], $history->pluck('subject')->all());
        $this->assertSame(2, $history->first()['files']);
        $this->assertSame([['M', 'content/collections/posts/kuih-lapis.md', 'Posts: kuih-lapis'], ['A', 'content/collections/posts/new-one.md', 'Posts: new-one']],
            VersionControl::files($history->first()['hash']));

        // Something not yet committed is kept first, then everything goes back.
        $this->write('content/collections/posts/kuih-lapis.md', 'Eleven layers (unsaved).');
        Livewire::actingAs($this->admin)->test(Page::class)
            ->call('openCommit', $first)
            ->assertSee('Put the book back to here')
            ->call('askRestore', $first)
            ->assertSee('Put the whole book back')
            ->call('restore')->assertHasNoErrors();

        $this->assertStringContainsString('Nine layers.', file_get_contents($this->repo.'/content/collections/posts/kuih-lapis.md'));
        $this->assertFileDoesNotExist($this->repo.'/content/collections/posts/new-one.md');
        $subjects = explode("\n", $this->git('log', '--format=%s'));
        $this->assertStringStartsWith('Put the book back to how it was on', $subjects[0]);
        $this->assertSame('Changes to the book by Nur', $subjects[1]); // the unsaved words, kept
        $this->assertFileExists($this->repo.'/app/Secret.php');

        Livewire::actingAs($this->admin)->test(Page::class)->call('askRestore', 'not-a-hash')->call('restore')->assertHasErrors('git');
    }

    public function test_sending_commits_to_a_remote(): void
    {
        $this->start();
        $this->write('content/collections/posts/kuih-lapis.md', 'changed');

        // No remote yet: it says so, nothing breaks.
        VersionControl::saveSettings(['push' => true]);
        VersionControl::commit('One', $this->admin);
        $this->assertStringContainsString('Not connected', VersionControl::status()['push_error']);

        Process::run(['git', 'init', '--bare', $this->repo.'-remote.git']);
        $this->git('remote', 'add', 'origin', $this->repo.'-remote.git');
        $this->write('content/collections/posts/kuih-lapis.md', 'changed again');
        VersionControl::commit('Two', $this->admin);

        $this->assertNull(VersionControl::status()['push_error']);
        $this->assertSame('Two', trim(Process::run(['git', '--git-dir='.$this->repo.'-remote.git', 'log', '-1', '--all', '--format=%s'])->output()));
    }

    public function test_only_admins_and_the_check_up(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        $this->actingAs($writer)->get(route('admin.settings.version-control'))->assertForbidden();
        Livewire::actingAs($writer)->test(Page::class)->assertForbidden();

        // Off: the check-up says nothing. On without a history: it does.
        $this->assertNull(collect(\App\Support\Health::run())->firstWhere('key', 'version_control'));
        VersionControl::saveSettings(['on' => true]);
        /** @var array<string, mixed> $check */
        $check = collect(\App\Support\Health::run())->first(fn (array $c) => $c['key'] === 'version_control', []);
        $this->assertSame('fail', $check['state']);
        $this->assertStringContainsString('no history', (string) $check['text']);

        // Switching on asks for a history first.
        VersionControl::saveSettings(['on' => false]);
        Livewire::actingAs($this->admin)->test(Page::class)->call('toggle', 'on')->assertHasErrors('git');
    }

    public function test_everything_commits_the_project_but_never_secrets(): void
    {
        $this->start();
        $this->write('README.md', '# Booklet');
        $this->write('docs/screenshots/contents.png', 'png');
        $this->write('.env', 'APP_KEY=base64:secret');
        $this->write('.env.local', 'DB_PASSWORD=secret');
        $this->write('.env.example', 'APP_KEY=');
        $this->write('database/database.sqlite', 'people and passwords');
        $this->write('vendor/laravel/x.php', '<?php');
        $this->write('storage/logs/laravel.log', 'errors');
        $this->write('storage/app/private/backups/b.zip', 'zip');

        // Content only (the default): none of it.
        $this->assertSame([], collect(VersionControl::pending())->pluck(1)->filter(fn ($f) => ! str_starts_with($f, 'content/'))->values()->all());

        Livewire::actingAs($this->admin)->test(Page::class)
            ->assertSee('Commit everything, not just content')
            ->call('toggle', 'everything');
        $this->assertTrue(VersionControl::everything());

        $waiting = collect(VersionControl::pending())->pluck(1)->sort()->values()->all();
        $this->assertSame(['.env.example', '.gitignore', 'README.md', 'app/Secret.php', 'docs/screenshots/contents.png'], $waiting);

        // Someone's still typing in a file: it waits until they've left it alone.
        config(['booklet.drivers.entries' => 'file']);
        $this->assertFalse(VersionControl::due());
        $this->travel(3)->minutes();
        $this->assertTrue(VersionControl::due());

        VersionControl::commit();
        $this->assertSame('Updated .env.example, .gitignore and 3 more files', $this->git('log', '-1', '--format=%s'));
        $tracked = explode("\n", $this->git('ls-files'));
        foreach (['.env', '.env.local', 'database/database.sqlite', 'vendor/laravel/x.php', 'storage/logs/laravel.log', 'storage/app/private/backups/b.zip', 'public/storage'] as $secret) {
            $this->assertNotContains($secret, $tracked, $secret);
        }
        $this->assertContains('README.md', $tracked);

        // The admin's changes and the code's, in one commit: both told.
        $this->write('README.md', '# Booklet, a CMS');
        $this->write('content/collections/posts/kuih-lapis.md', 'changed');
        $this->actingAs($this->admin);
        ActivityLog::record('edited', 'edits', 'entry', 'x', 'Kuih lapis');
        VersionControl::commit();
        $this->assertSame('Nur edited “Kuih lapis”', $this->git('log', '-1', '--format=%s'));
        $this->assertSame('Also changed: README.md', $this->git('log', '-1', '--format=%b'));

        // Putting the book back never touches code.
        $first = collect(VersionControl::history(50))->last()['hash'];
        Livewire::actingAs($this->admin)->test(Page::class)->call('askRestore', $first)->call('restore')->assertHasNoErrors();
        $this->assertSame('# Booklet, a CMS', file_get_contents($this->repo.'/README.md'));
        $this->assertStringContainsString('Nine layers', file_get_contents($this->repo.'/content/collections/posts/kuih-lapis.md'));
    }

    public function test_things_staged_by_hand_are_left_alone(): void
    {
        $this->start();
        $this->write('app/Work.php', '<?php // half done');
        $this->git('add', 'app/Work.php'); // the developer staged it themselves

        $this->write('content/collections/posts/kuih-lapis.md', 'changed');
        VersionControl::commit('Content only', $this->admin);

        $this->assertSame('content/collections/posts/kuih-lapis.md', $this->git('show', '--name-only', '--format=', 'HEAD'));
        $this->assertSame('app/Work.php', $this->git('diff', '--cached', '--name-only')); // still staged, not committed
    }

    public function test_booklets_own_storage_stays_out_of_git_even_with_a_plain_git_add(): void
    {
        config(['booklet.paths.storage' => $this->repo.'/storage/booklet']);
        $this->write('storage/booklet/revisions/collections/posts/kuih-lapis/1.yaml', "title: An older draft\n");
        $this->write('storage/booklet/forms/contact/1.yaml', "email: visitor@example.com\n");

        $this->start();

        $this->assertFileExists($this->repo.'/storage/booklet/.gitignore');
        // By hand, everything at once: still nothing from Booklet's storage.
        $this->git('add', '.');
        $staged = $this->git('diff', '--cached', '--name-only');
        $this->assertStringNotContainsString('storage/booklet/revisions', $staged);
        $this->assertStringNotContainsString('storage/booklet/forms', $staged);

        // Taken away, it comes back on the next run; one someone edited is left as it is.
        unlink($this->repo.'/storage/booklet/.gitignore');
        $this->artisan('booklet:git-commit')->assertExitCode(0);
        $this->assertFileExists($this->repo.'/storage/booklet/.gitignore');

        file_put_contents($this->repo.'/storage/booklet/.gitignore', "# mine\n*\n");
        VersionControl::guardPrivateFolders();
        $this->assertSame("# mine\n*\n", file_get_contents($this->repo.'/storage/booklet/.gitignore'));
    }

    public function test_addresses_people_paste_are_understood(): void
    {
        $this->assertSame('https://github.com/hana/kopi.git', VersionControl::normalizeRemote('hana/kopi'));
        $this->assertSame('https://github.com/hana/kopi.git', VersionControl::normalizeRemote('https://github.com/hana/kopi'));
        $this->assertSame('https://github.com/hana/kopi.git', VersionControl::normalizeRemote('https://github.com/hana/kopi.git'));
        $this->assertSame('https://github.com/hana/kopi.git', VersionControl::normalizeRemote('https://github.com/hana/kopi/tree/main/content'));
        $this->assertSame('git@github.com:hana/kopi.git', VersionControl::normalizeRemote('git@github.com:hana/kopi.git'));
        $this->assertNull(VersionControl::normalizeRemote('https://hana:secret@github.com/hana/kopi.git'), 'never a password in the address');
        $this->assertNull(VersionControl::normalizeRemote('just words'));
        $this->assertNull(VersionControl::normalizeRemote('http://github.com/hana/kopi'));

        $this->assertSame('https://github.com/hana/kopi', VersionControl::webUrl('git@github.com:hana/kopi.git'));
        $this->assertSame('hana/kopi', VersionControl::remoteLabel('https://github.com/hana/kopi.git'));

        $this->assertSame('signin', VersionControl::explain("remote: Invalid username or token.\nfatal: Authentication failed for 'https://github.com/hana/kopi.git/'")[0]);
        $this->assertSame('missing', VersionControl::explain('remote: Repository not found.')[0]);
        $this->assertSame('diverged', VersionControl::explain(" ! [rejected]        HEAD -> main (fetch first)\nerror: failed to push some refs")[0]);
        $this->assertSame('offline', VersionControl::explain('fatal: unable to access: Could not resolve host: github.com')[0]);
        $this->assertSame('sshkey', VersionControl::explain('git@github.com: Permission denied (publickey).')[0]);
    }

    public function test_connecting_sends_everything_and_keeps_sending(): void
    {
        $this->start();
        Process::run(['git', 'init', '--bare', $this->repo.'-remote.git']);

        VersionControl::connect($this->repo.'-remote.git', 'github_pat_'.str_repeat('a', 40));
        $this->assertTrue(VersionControl::remoteIsEmpty());
        $this->assertTrue(VersionControl::push());
        $this->assertFalse(VersionControl::remoteIsEmpty());

        // The token is kept encrypted by Booklet, never in git's own settings.
        $this->assertStringNotContainsString('github_pat_', (string) file_get_contents($this->repo.'/.git/config'));
        $this->assertStringNotContainsString('github_pat_', (string) file_get_contents(storage_path('app/private/version-control-token')));
        $this->assertSame('…aaaa', VersionControl::tokenHint());

        // Disconnecting forgets the remote and the token; the history stays.
        VersionControl::disconnect();
        $this->assertSame([], VersionControl::remotes());
        $this->assertFalse(VersionControl::hasToken());
        $this->assertNotSame('', $this->git('log', '-1', '--format=%s'));
    }

    public function test_the_token_is_handed_to_git_only_for_sending(): void
    {
        // A stand-in for git that writes down how it was called.
        $spy = $this->repo.'-spy.sh';
        file_put_contents($spy, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"$spy.args\"\n\"\$GIT_ASKPASS\" 'Password for https://github.com' >> \"$spy.args\"\n");
        chmod($spy, 0755);
        VersionControl::saveToken('github_pat_'.str_repeat('z', 40));
        config(['booklet.git.binary' => $spy]);

        VersionControl::git(['push', 'origin', 'HEAD:main']);
        VersionControl::git(['status']);
        $said = (string) file_get_contents($spy.'.args');
        @unlink($spy);
        @unlink($spy.'.args');
        VersionControl::saveToken(null);

        $this->assertStringContainsString('-c credential.helper= push origin HEAD:main', $said, 'the Mac Keychain stays out of it');
        $this->assertStringContainsString('github_pat_'.str_repeat('z', 40), $said, 'git gets the token when it asks');
        $this->assertStringContainsString("\nstatus", $said);
        $this->assertStringNotContainsString('-c credential.helper= status', $said, 'only for talking to GitHub');
    }

    public function test_when_github_already_has_other_commits_it_asks_first(): void
    {
        $this->start();
        // GitHub with a README made there.
        Process::run(['git', 'init', '--bare', $this->repo.'-remote.git']);
        $other = $this->repo.'-other';
        Process::run(['git', 'clone', $this->repo.'-remote.git', $other]);
        file_put_contents($other.'/README.md', '# Made on GitHub');
        Process::path($other)->run(['git', '-c', 'user.name=GitHub', '-c', 'user.email=g@example.com', 'add', '.']);
        Process::path($other)->run(['git', '-c', 'user.name=GitHub', '-c', 'user.email=g@example.com', 'commit', '-m', 'Initial commit']);
        Process::path($other)->run(['git', 'push', 'origin', 'HEAD:main']);
        File::deleteDirectory($other);

        VersionControl::connect($this->repo.'-remote.git');
        $this->assertFalse(VersionControl::remoteIsEmpty());
        $this->assertFalse(VersionControl::push());
        $this->assertSame('diverged', VersionControl::status()['push_problem']);

        Livewire::actingAs($this->admin)->test(Page::class)
            ->assertSee('GitHub already has commits this book doesn’t')
            ->assertSee('Replace GitHub’s copy with this book')
            ->call('replaceRemote')
            ->assertDispatched('stamp');

        $this->assertNotSame('Initial commit', trim(Process::run(['git', '--git-dir='.$this->repo.'-remote.git', 'log', '-1', '--format=%s', 'main'])->output()));
        $this->assertTrue(VersionControl::settings()['push']);
    }

    public function test_a_bad_address_or_missing_token_is_explained_on_the_page(): void
    {
        $this->start();
        Livewire::actingAs($this->admin)->test(Page::class)
            ->assertSee('Make an empty repository on GitHub')
            ->set('address', 'not an address')->call('connect')->assertHasErrors('address')
            ->set('address', 'hana/kopi')->set('signIn', 'token')->set('token', '')->call('connect')->assertHasErrors('token');
        $this->assertSame([], VersionControl::remotes());
    }

    public function test_private_files_from_before_can_be_taken_out_of_git(): void
    {
        $this->start();
        $this->write('.env', "APP_KEY=base64:not-real\n");
        $this->write('storage/booklet/forms/contact/1.yaml', "email: visitor@example.com\n");
        $this->git('add', '-f', '.env', 'storage/booklet/forms/contact/1.yaml');
        $this->git('-c', 'user.name=Someone', '-c', 'user.email=s@example.com', 'commit', '-m', 'Oops');

        $this->assertSame(['.env', 'storage/booklet/forms/contact/1.yaml'], VersionControl::privateTracked());

        Livewire::actingAs($this->admin)->test(Page::class)
            ->assertSee('Take them out of git')
            ->call('untrackPrivate')->assertDispatched('stamp');

        $this->assertSame([], VersionControl::privateTracked());
        $this->assertFileExists($this->repo.'/.env', 'still on the computer');
        $this->assertSame('Stop keeping private files in git', $this->git('log', '-1', '--format=%s'));
    }

    public function test_files_with_merge_marks_are_never_committed(): void
    {
        $this->start();
        $this->write('content/collections/posts/kuih-lapis.md', "<<<<<<< HEAD\nNine layers.\n=======\nTen layers.\n>>>>>>> 6f2f6b4\n");

        try {
            VersionControl::commit('Half merged', $this->admin);
            $this->fail('It should have refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unfinished merge marks', $e->getMessage());
            $this->assertStringContainsString('content/collections/posts/kuih-lapis.md', $e->getMessage());
        }
        $this->assertNotSame('Half merged', $this->git('log', '-1', '--format=%s'));

        Livewire::actingAs($this->admin)->test(Page::class)->assertSee('Some files have unfinished merge marks');

        $this->write('content/collections/posts/kuih-lapis.md', "Ten layers.\n");
        $this->assertNotNull(VersionControl::commit('Fixed', $this->admin));
    }
}
