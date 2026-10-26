<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Inserts\InsertManager;
use App\Livewire\Admin\Collections;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\Blocks;
use App\Support\EditionCompare;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Blocks: cut-outs tucked into a page (quotes, pictures, buttons…), from
 * Booklet and from inserts.
 */
class BlocksTest extends TestCase
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
        config(['booklet.inserts_path' => base_path('tests/Fixtures/inserts')]);
        $this->app->forgetInstance(InsertManager::class);
    }

    protected function about()
    {
        return Booklet::entries()->findBySlug('pages', 'about');
    }

    /** Save some blocks on the About page and fetch it as a reader. */
    protected function show(array $blocks): string
    {
        $this->about()->set('blocks', $blocks)->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get('/about')->assertOk()->getContent();
    }

    public function test_what_the_editor_sends_is_cleaned_before_it_is_kept(): void
    {
        $clean = Blocks::clean([
            ['type' => 'button', 'heading' => "Come\nalong", 'label' => 'Go', 'link' => 'javascript:alert(1)', 'extra' => 'dropped'],
            ['type' => 'button', 'link' => 'kopi.example/menu'],
            ['type' => 'picture', 'picture' => ['../../.env', '/etc/passwd', 'blog/cake.jpg', 'blog/other.jpg'], 'size' => 'enormous'],
            ['type' => 'Not A Kind!'],
            'not even a block',
            ['type' => 'recipes.card', 'id' => 'keep-me', 'title' => 'Kuih lapis'], // an insert that isn't here
        ]);

        $this->assertCount(4, $clean);
        $this->assertSame('Come along', $clean[0]['heading']);
        $this->assertArrayNotHasKey('link', $clean[0]); // unsafe: gone
        $this->assertArrayNotHasKey('extra', $clean[0]);
        $this->assertTrue($clean[0]['enabled']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $clean[0]['id']);
        $this->assertSame('https://kopi.example/menu', $clean[1]['link']);
        $this->assertSame(['blog/cake.jpg'], $clean[2]['picture']);
        $this->assertSame('text', $clean[2]['size']); // back to the default
        $this->assertSame(['type' => 'recipes.card', 'id' => 'keep-me', 'title' => 'Kuih lapis'], $clean[3]);
    }

    public function test_cut_outs_are_added_moved_hidden_and_taken_out_in_the_editor(): void
    {
        $about = $this->about();
        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])
            ->assertSee('Cut-outs')
            ->assertSee('Tuck in a cut-out')
            ->call('addBlock', 'blocks', 'quote')
            ->call('addBlock', 'blocks', 'button')
            ->call('addBlock', 'blocks', 'break', 0); // at the top

        $this->assertSame(['break', 'quote', 'button'], array_column($editor->get('values.blocks'), 'type'));

        $editor->set('values.blocks.1.quote', 'Kopi first, then everything else.')
            ->set('values.blocks.1.cite', 'Hana')
            ->set('values.blocks.2.heading', 'Visit the stall')
            ->set('values.blocks.2.link', '/contact')
            ->call('moveBlock', 'blocks', 0, 2)          // the break to the end
            ->call('duplicateBlock', 'blocks', 0)        // two quotes
            ->call('toggleBlock', 'blocks', 1)           // the copy is hidden
            ->call('removeBlock', 'blocks', 3)
            ->assertSee('Put it back')
            ->call('undoRemoveBlock')
            ->call('removeBlock', 'blocks', 3);           // the break, for good this time

        $blocks = $editor->get('values.blocks');
        $this->assertSame(['quote', 'quote', 'button'], array_column($blocks, 'type'));
        $this->assertNotSame($blocks[0]['id'], $blocks[1]['id']);
        $this->assertFalse($blocks[1]['enabled']);

        $editor->call('save')->assertHasNoErrors();

        // Kept the way Statamic keeps sets.
        $kept = Booklet::entries()->find($about->id())->get('blocks');
        $this->assertSame(['id' => $blocks[0]['id'], 'type' => 'quote', 'enabled' => true, 'quote' => 'Kopi first, then everything else.', 'cite' => 'Hana'], $kept[0]);
        $this->assertFalse($kept[1]['enabled']);
        $this->assertSame('/contact', $kept[2]['link']);

        // Opening it again brings them all back.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])
            ->assertSet('values.blocks.0.cite', 'Hana')
            ->assertSee('Hidden from readers');
    }

    public function test_only_blocks_fields_and_offered_kinds_can_be_changed(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()]);
        $editor->call('addBlock', 'blocks', 'no-such-kind')->assertStatus(422);
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->call('addBlock', 'title', 'quote')->assertStatus(404);
    }

    public function test_pictures_are_chosen_from_media_for_a_cut_out(): void
    {
        $paths = [];
        foreach ([1, 2, 3] as $i) {
            $paths[] = (new AssetUploader('media'))->store(UploadedFile::fake()->image("market-$i.jpg", 800, 600), 'blog')->path();
        }

        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->call('addBlock', 'blocks', 'picture')
            ->call('addBlock', 'blocks', 'gallery')
            ->call('openBlockPicker', 'blocks', 0, 'picture')
            ->call('choose', $paths[0])
            ->assertSet('picking', null)                   // one picture: done
            ->call('openBlockPicker', 'blocks', 1, 'pictures')
            ->assertSee('Choose pictures')
            ->call('choose', $paths[1])
            ->call('choose', $paths[2])
            ->call('choose', $paths[1])                    // already in
            ->assertSet('picking', 'blocks.1.pictures')    // several: stays open
            ->call('closePicker')
            ->call('removeBlockPicture', 'blocks', 1, 'pictures', $paths[1]);

        $this->assertSame([$paths[0]], $editor->get('values.blocks.0.picture'));
        $this->assertSame([$paths[2]], $editor->get('values.blocks.1.pictures'));

        $editor->call('save')->assertHasNoErrors();
        $html = $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('block--picture', $html);
        $this->assertStringContainsString(basename($paths[0]), $html);
        $this->assertStringContainsString('block--gallery', $html);
    }

    public function test_readers_see_the_cut_outs_but_not_hidden_ones(): void
    {
        $html = $this->show([
            ['id' => 'a', 'type' => 'quote', 'quote' => 'Kopi first, then everything else.', 'cite' => 'Hana'],
            ['id' => 'b', 'type' => 'quote', 'enabled' => false, 'quote' => 'A secret draft line'],
            ['id' => 'c', 'type' => 'button', 'heading' => 'Visit the stall', 'label' => 'Find us', 'link' => '/contact'],
            ['id' => 'd', 'type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10'],
            ['id' => 'e', 'type' => 'note', 'kind' => 'warning', 'text' => 'Closed on **Fridays**.'],
            ['id' => 'f', 'type' => 'columns', 'left' => 'Mornings', 'right' => 'Evenings'],
            ['id' => 'g', 'type' => 'button', 'heading' => 'Sneaky', 'link' => 'javascript:alert(1)'],
            ['id' => 'h', 'type' => 'text', 'text' => 'Plain words <script>alert(1)</script>'],
            ['id' => 'i', 'type' => 'recipes.card', 'title' => 'From a missing insert'],
        ]);

        $this->assertStringContainsString('Kopi first, then everything else.', $html);
        $this->assertStringContainsString('Hana', $html);
        $this->assertStringNotContainsString('A secret draft line', $html);
        $this->assertStringContainsString('href="/contact"', $html);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringContainsString('<strong>Fridays</strong>', $html);
        $this->assertStringContainsString('Careful', $html);
        $this->assertStringContainsString('Evenings', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Plain words', $html);
        $this->assertStringNotContainsString('From a missing insert', $html);
        $this->assertStringContainsString('class="blocks-room"', $html);
    }

    public function test_the_words_in_cut_outs_are_found_and_counted(): void
    {
        $this->show([['id' => 'a', 'type' => 'quote', 'quote' => 'Pandan chiffon on Saturdays']]);

        $this->get('/search?q=chiffon')->assertOk()->assertSee('About');
        $about = $this->about();
        $this->assertStringContainsString('Pandan chiffon', Blocks::textOf($about));

        // In History, a cut-out reads in a line.
        $this->assertSame('Quote “Pandan chiffon on Saturdays”', EditionCompare::words($about->get('blocks'), ['type' => 'blocks', 'options' => [], 'taxonomy' => null]));
    }

    public function test_inserts_add_their_own_cut_outs(): void
    {
        $this->assertArrayNotHasKey('events.upcoming', Blocks::kinds());

        app(InsertManager::class)->enable('events');
        app(InsertManager::class)->reboot();
        $events = app(InsertManager::class)->find('events');
        $events->records('events')->create(['title' => 'Market day', 'starts' => now()->addDay()->format('Y-m-d').' 08:00', 'all_day' => false]);

        $this->assertSame('Upcoming events', Blocks::kinds()['events.upcoming']['name']);
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->call('addBlock', 'blocks', 'events')->assertStatus(422);

        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->call('addBlock', 'blocks', 'events.upcoming')
            ->assertSee('from Events')
            ->set('values.blocks.0.heading', 'This week at the stall')
            ->call('save')->assertHasNoErrors();

        $html = $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('This week at the stall', $html);
        $this->assertStringContainsString('Market day', $html);

        // Switched off: the cut-out waits in the page, unseen, and comes back with the insert.
        app(InsertManager::class)->disable('events');
        app(InsertManager::class)->forget();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        $this->assertStringNotContainsString('Market day', $this->get('/about')->getContent());
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->assertSee('From an insert that’s switched off')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('events.upcoming', Booklet::entries()->find($this->about()->id())->get('blocks')[0]['type']);
    }

    public function test_an_insert_cut_out_that_breaks_leaves_the_rest_of_the_page(): void
    {
        $dir = storage_path('framework/testing/inserts-'.uniqid());
        mkdir($dir.'/shaky', 0777, true);
        file_put_contents($dir.'/shaky/insert.php', <<<'PHP'
        <?php
        return [
            'name' => 'Shaky',
            'blocks' => [
                'wobble' => ['name' => 'Wobble', 'fields' => ['when' => ['type' => 'select', 'options' => fn ($insert) => ['now' => 'Now']], 'bad' => ['type' => 'nonsense']],
                    'show' => function () { throw new RuntimeException('Fell over'); }],
                'nope' => ['name' => 'No show'], // nothing to draw it with: not offered
            ],
        ];
        PHP);
        config(['booklet.inserts_path' => $dir]);
        $this->app->forgetInstance(InsertManager::class);
        app(InsertManager::class)->enable('shaky');

        try {
            $kinds = Blocks::kinds();
            $this->assertArrayHasKey('shaky.wobble', $kinds);
            $this->assertArrayNotHasKey('shaky.nope', $kinds);
            $this->assertSame(['when'], array_keys($kinds['shaky.wobble']['fields']));
            $this->assertSame(['now' => 'Now'], Blocks::options($kinds['shaky.wobble']['fields']['when']));

            $html = Blocks::render([
                ['id' => 'a', 'type' => 'shaky.wobble', 'when' => 'now'],
                ['id' => 'b', 'type' => 'quote', 'quote' => 'Still standing'],
            ])->toHtml();
            $this->assertStringContainsString('Still standing', $html);
            $this->assertStringContainsString('Fell over', (string) app(InsertManager::class)->find('shaky')->error());
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }

    public function test_admins_add_cut_outs_from_the_editor(): void
    {
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $writer = User::factory()->create(['role' => 'writer']);
        $mine = Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post'], ['title' => 'Mine', 'author' => $writer->id]);
        $mine->save();

        // Writers don't see the offer, and can't use it.
        Livewire::actingAs($writer)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $mine->id()])
            ->assertDontSee('Add cut-outs to Posts')
            ->call('addCutouts')->assertForbidden();

        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->assertSee('Add cut-outs to Posts')
            ->call('addCutouts')
            ->assertDontSee('Add cut-outs to Posts')
            ->assertSee('Tuck in a cut-out')
            ->call('addBlock', 'blocks', 'quote')
            ->set('values.blocks.0.quote', 'Fresh from the editor')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('blocks', Booklet::blueprints()->findIn('collections.posts', 'post')->field('blocks')['type']);
        $this->assertStringContainsString('Fresh from the editor', $this->get('/blog/hello-from-booklet')->getContent());

        // Pages already have them: no offer there.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $this->about()->id()])
            ->assertDontSee('Add cut-outs to');
    }

    public function test_chapters_offer_a_blocks_field(): void
    {
        Livewire::actingAs($this->admin)->test(Collections::class)
            ->call('pick', 'posts')
            ->assertSee('+ Blocks')
            ->call('addField', 'blocks')
            ->call('save')->assertHasNoErrors();

        $field = Booklet::blueprints()->findIn('collections.posts', 'post')->field('blocks');
        $this->assertSame('blocks', $field['type']);
    }
}
