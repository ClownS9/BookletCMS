<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\ImportWordPress;
use App\Models\Comment;
use App\Models\Redirect;
use App\Models\User;
use App\Support\HtmlToMarkdown;
use App\Support\SafeFetch;
use App\Support\WordPressImport;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moving in from WordPress, with the export file in tests/Fixtures.
 */
class WordPressImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin', 'email' => 'nur@example.com']);

        // Pretend the old site lives at a public address, and serve its picture.
        SafeFetch::$resolve = fn () => ['93.184.216.34'];
        $jpeg = UploadedFile::fake()->image('kuih.jpg', 1200, 800)->getContent();
        Http::fake(['old-kopi.example/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
    }

    protected function tearDown(): void
    {
        SafeFetch::$resolve = null;
        parent::tearDown();
    }

    protected function upload(): string
    {
        return WordPressImport::keepUpload(base_path('tests/Fixtures/wordpress-export.xml'));
    }

    protected function byTitle(string $title)
    {
        return Booklet::entries()->all()->first(fn ($e) => $e->title() === $title);
    }

    public function test_a_look_inside_before_anything_happens(): void
    {
        $summary = WordPressImport::summary($this->upload());

        $this->assertSame('Kopi & Kuih', $summary['site']['title']);
        $this->assertSame(1, $summary['posts'], 'the protected one counts as a draft-to-be, not a post');
        $this->assertSame(2, $summary['pages']);
        $this->assertSame(1, $summary['pictures']);
        $this->assertSame(2, $summary['comments'], 'spam left out');
        $this->assertSame(['product' => 1], $summary['other']);
        $this->assertNull($this->byTitle('About us'), 'nothing brought in yet');
    }

    public function test_bringing_it_in(): void
    {
        $result = WordPressImport::run($this->upload(), $this->nur, ['authors' => 'match']);
        $this->assertSame(1, $result['report']['posts']);
        $this->assertSame(2, $result['report']['pages']);
        $this->assertSame(1, $result['report']['pictures']);

        $post = $this->byTitle('Kuih & coconut: a love story');
        $this->assertTrue($post->published());
        $this->assertSame('2023-05-02 08:30', $post->date()->format('Y-m-d H:i'));
        $this->assertSame((string) $this->nur->id, (string) $post->get('author'), 'matched by email, whatever the capitals');
        $this->assertSame(['recipes'], $post->get('categories'));
        $this->assertEqualsCanonicalizing(['coconut', 'breakfast'], $post->get('tags'), 'a second category becomes a tag');
        $this->assertSame('A small green treat.', $post->get('excerpt'));
        $this->assertSame('How we make kuih with fresh coconut.', $post->get('meta_description'));
        $this->assertNull($post->get('meta_title'), 'Yoast placeholders are left behind');

        // The picture came across, as the cover and inside the text.
        $cover = $post->get('cover')[0];
        $this->assertStringStartsWith('wordpress/2023/05/kuih', $cover);
        $this->assertSame('Green kuih on a plate', Booklet::assets()->find(['container' => 'media', 'path' => $cover])->get('alt'));
        $content = $post->get('content');
        $this->assertStringContainsString('**sweet**', $content);
        $this->assertStringContainsString('/storage/'.$cover, $content);
        $this->assertStringNotContainsString('[gallery', $content);
        $this->assertStringNotContainsString('<!-- wp:', $content);

        // Links between old posts point at the new addresses.
        $about = $this->byTitle('About us');
        $this->assertStringContainsString('[about us]('.$about->uri().')', $content);

        // Pages keep their places.
        $team = $this->byTitle('Our team');
        $this->assertSame($about->uri().'/team', $team->uri(), 'under its parent');
        $this->assertSame('/about-2', $about->uri(), 'the starter book already has an /about');
        $this->assertTrue(collect($result['notes'])->contains(fn ($n) => str_contains($n, 'already uses /about')));
        $this->assertTrue($team->published());
        $this->assertStringContainsString("Every morning\\\nfrom six.", $about->get('content'));

        // Protected posts come in as drafts.
        $this->assertFalse($this->byTitle('Secret recipe')->published());

        // Comments, with the reply under its note, and no spam.
        $notes = Comment::where('entry_id', $post->id())->orderBy('id')->get();
        $this->assertCount(2, $notes);
        $this->assertSame('Looks lovely!', $notes[0]->body);
        $this->assertSame($notes[0]->id, $notes[1]->parent_id);
        $this->assertSame('2023-05-03', $notes[0]->created_at->format('Y-m-d'));

        // The old address forwards.
        $this->assertSame($post->uri(), Redirect::where('from_path', '/2023/05/kuih-coconut')->value('to_path'));
        $this->get('/2023/05/kuih-coconut')->assertRedirect($post->uri());
    }

    public function test_running_it_twice_brings_nothing_twice(): void
    {
        WordPressImport::run($this->upload(), $this->nur);
        $count = Booklet::entries()->all()->count();

        $again = WordPressImport::run($this->upload(), $this->nur);

        $this->assertSame($count, Booklet::entries()->all()->count());
        $this->assertSame(4, $again['report']['already']);
        $this->assertSame(2, Comment::count() - 1, 'comments not doubled (the starter note is the other one)');
    }

    public function test_pictures_from_private_addresses_are_never_fetched(): void
    {
        SafeFetch::$resolve = fn () => ['10.0.0.5'];

        $result = WordPressImport::run($this->upload(), $this->nur);

        $this->assertSame(0, $result['report']['pictures']);
        $this->assertTrue(collect($result['notes'])->contains(fn ($n) => str_contains($n, 'private network')));
        $this->assertFalse(SafeFetch::allowed('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse(SafeFetch::allowed('file:///etc/passwd'));
    }

    public function test_the_page_is_for_admins_and_takes_only_wordpress_exports(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get(route('admin.import'))->assertForbidden();

        $this->actingAs($this->nur);
        $this->post(route('admin.import.upload'), ['export' => UploadedFile::fake()->createWithContent('notes.xml', '<?xml version="1.0"?><notes/>')])
            ->assertSessionHasErrors('export');

        $name = $this->upload();
        Livewire::test(ImportWordPress::class, ['file' => $name])
            ->assertSee('Kopi &amp; Kuih', false)
            ->set('authors', 'create')
            ->call('bringIn')
            ->assertSet('file', '');

        $this->assertNotNull(User::where('email', 'hana@example.com')->where('role', 'writer')->first(), 'Hana added as a writer');
    }

    public function test_html_becomes_tidy_markdown(): void
    {
        $this->assertSame("## A heading\n\nOne **two** _three_.\n\n- a\n- b", HtmlToMarkdown::convert('<h1>A heading</h1><p>One <b>two</b> <i>three</i>.</p><ul><li>a</li><li>b</li></ul>'));
        $this->assertSame('[Watch the video](https://www.youtube.com/watch?v=abc123DEF45)', HtmlToMarkdown::convert('<iframe src="https://www.youtube.com/embed/abc123DEF45"></iframe>'));
        $this->assertSame('ok', HtmlToMarkdown::convert('<script>alert(1)</script><p>ok</p>'));
    }
}
