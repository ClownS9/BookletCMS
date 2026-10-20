<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Stores\FileStore;
use App\Support\Health;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One content file that can't be read (unfinished merge marks, a YAML typo)
 * is left out and named in the check-up: it doesn't take the site down.
 */
class BrokenContentFileTest extends TestCase
{
    use RefreshDatabase;

    protected string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = storage_path('framework/testing/content-broken-'.uniqid());
        File::ensureDirectoryExists($this->folder.'/collections/posts');
        config(['booklet.paths.content' => $this->folder, 'booklet.drivers.entries' => 'file']);
        Booklet::flush();
        FileStore::$unreadable = [];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_a_file_with_merge_marks_is_left_out_and_named(): void
    {
        file_put_contents($this->folder.'/collections/posts/kuih-lapis.md', "---\nid: one\ntitle: Kuih lapis\n---\nNine layers.\n");
        file_put_contents($this->folder.'/collections/posts/pandan.md', "<<<<<<< HEAD\n---\nid: two\ntitle: Pandan chiffon\n---\n=======\n---\nid: two\ntitle: Pandan chiffon, third try\n---\n>>>>>>> 6f2f6b4\nSoft.\n");

        $titles = Booklet::entries()->all()->map(fn ($e) => $e->title())->values()->all();

        $this->assertSame(['Kuih lapis'], $titles);
        $this->assertCount(1, FileStore::$unreadable);
        $this->assertStringEndsWith('pandan.md', array_key_first(FileStore::$unreadable));

        $check = collect(Health::run())->firstWhere('key', 'content_files');
        $this->assertSame('fail', $check['state']);
        $this->assertStringContainsString('pandan.md', $check['text']);
        $this->assertStringContainsString('merge marks', $check['fix']);
    }

    public function test_all_readable_is_fine(): void
    {
        file_put_contents($this->folder.'/collections/posts/kuih-lapis.md', "---\nid: one\ntitle: Kuih lapis\n---\nNine layers.\n");

        $this->assertSame('ok', collect(Health::run())->firstWhere('key', 'content_files')['state']);
    }
}
