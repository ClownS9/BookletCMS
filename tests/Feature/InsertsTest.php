<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Inserts\InsertManager;
use App\Livewire\Admin\InsertPage;
use App\Livewire\Admin\Inserts as Shelf;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Inserts: extras tucked into the book from inserts/{name}/insert.php.
 */
class InsertsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->dir = storage_path('framework/testing/inserts-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['booklet.inserts_path' => $this->dir]);
        $this->app->forgetInstance(InsertManager::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function manager(): InsertManager
    {
        return app(InsertManager::class);
    }

    /** A small insert, written to the test shelf. */
    protected function insert(string $key, string $php, array $views = [], array $assets = []): void
    {
        File::ensureDirectoryExists("{$this->dir}/{$key}/views");
        File::put("{$this->dir}/{$key}/insert.php", $php);
        foreach ($views as $name => $blade) {
            File::put("{$this->dir}/{$key}/views/{$name}.blade.php", $blade);
        }
        foreach ($assets as $name => $content) {
            File::ensureDirectoryExists("{$this->dir}/{$key}/assets");
            File::put("{$this->dir}/{$key}/assets/{$name}", $content);
        }
    }

    protected function hello(): void
    {
        $this->insert('hello', <<<'PHP'
<?php
use App\Inserts\Insert;
return [
    'name' => 'Hello',
    'about' => 'Says hello.',
    'settings' => ['greeting' => ['type' => 'text', 'default' => 'Hi'], 'loud' => ['type' => 'toggle', 'default' => false], 'times' => ['type' => 'number', 'default' => 1, 'min' => 1, 'max' => 5]],
    'slips' => [
        'hello' => fn ($who, Insert $i) => '<span class="hello">'.e($i->setting('greeting')).', '.e($who ?: 'you').'</span>',
        'oops' => fn () => throw new RuntimeException('slip broke'),
        'oopsview' => fn ($w, Insert $i) => $i->view('broken'),
    ],
    'page' => [
        'title' => 'Greetings', 'chapter' => 'People', 'who' => ['admin'],
        'form' => ['name' => ''],
        'data' => fn (Insert $i) => ['names' => $i->records('names')->all()],
        'actions' => [
            'add' => function (Insert $i, array $form) {
                $i->validate($form, ['name' => 'required|max:40']);
                $i->records('names')->create(['title' => $form['name']]);
                return ['form' => ['name' => ''], 'stamp' => 'Added.'];
            },
        ],
    ],
    'when' => ['entry.published' => fn ($entry, Insert $i) => $i->records('log')->create(['title' => 'published '.$entry->title()])],
    'site' => ['css' => ['hello.css']],
];
PHP, ['broken' => '<div>@php throw new RuntimeException("view broke"); @endphp</div>', 'admin' => '<p>Names: @foreach ($names as $n)<b>{{ $n->field(\'title\') }}</b>@endforeach</p><input wire:model="form.name">@error(\'form.name\')<em>{{ $message }}</em>@enderror'],
            ['hello.css' => '.hello { color: red }']);
    }

    public function test_the_shelf_finds_inserts_and_switches_them_on_and_off(): void
    {
        $this->hello();

        $this->assertSame(['hello'], $this->manager()->all()->keys()->all());
        $this->assertTrue($this->manager()->active()->isEmpty(), 'off until switched on');

        $this->actingAs(User::factory()->create(['role' => 'editor']))->get(route('admin.settings.inserts'))->assertForbidden();
        Livewire::actingAs($this->admin)->test(Shelf::class)->assertSee('Hello')->assertSee('Says hello.')->call('toggle');

        $this->manager()->forget();
        $this->assertTrue($this->manager()->find('hello')->active());

        $this->manager()->disable('hello');
        $this->manager()->forget();
        $this->assertFalse($this->manager()->find('hello')->active());
    }

    public function test_settings_are_drawn_checked_and_kept(): void
    {
        $this->hello();
        $this->manager()->enable('hello');

        Livewire::actingAs($this->admin)->test(Shelf::class, ['chosen' => 'hello'])
            ->assertSet('values.greeting', 'Hi')
            ->set('values.times', 9)->call('saveSettings')->assertHasErrors('values.times')
            ->set('values.times', 3)->set('values.greeting', 'Selamat pagi')->call('toggleValue', 'loud')->call('saveSettings')->assertHasNoErrors();

        $this->manager()->forget();
        $this->assertSame(['greeting' => 'Selamat pagi', 'loud' => true, 'times' => 3], $this->manager()->find('hello')->settings());
    }

    public function test_slips_show_in_writing_and_never_break_a_page(): void
    {
        $this->hello();
        $this->manager()->enable('hello');
        $this->manager()->reboot();

        Booklet::entries()->findBySlug('posts', 'hello-from-booklet')
            ->set('content', "[[hello: Siti]]\n\nA typo stays: [[helo]]\n\nA broken one: [[oops]]\n\nOne broken in its view: [[oopsview]]\n\nThe end of the post.")->save();

        $html = $this->get('/blog/hello-from-booklet')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="hello">Hi, Siti</span>', $html);
        $this->assertStringContainsString('[[helo]]', $html);
        $this->assertStringNotContainsString('[[oops]]', $html);
        $this->assertStringContainsString('The end of the post.', $html, 'a slip failing inside its own view leaves the page whole');
        $this->assertStringContainsString('</footer>', $html);
        $this->assertStringContainsString('view broke', $this->manager()->find('hello')->error(), 'the latest problem is noted on the shelf');
        $this->assertStringContainsString('/inserts/hello/assets/hello.css', $html);

        // Switched off: its slips quietly go.
        $this->manager()->disable('hello');
        $this->manager()->reboot();
        $this->assertStringNotContainsString('[[hello', $this->get('/blog/hello-from-booklet')->getContent());
    }

    public function test_a_broken_insert_is_torn_out_and_the_book_carries_on(): void
    {
        $this->hello();
        $this->manager()->enable('hello');
        File::put("{$this->dir}/hello/insert.php", '<?php return [ oops');

        $this->manager()->reboot();
        $insert = $this->manager()->find('hello');
        $this->assertTrue($insert->tornOut());
        $this->assertStringContainsString('ParseError', $insert->error());
        $this->assertTrue($this->manager()->active()->isEmpty());

        $this->get('/blog/hello-from-booklet')->assertOk();
        Livewire::actingAs($this->admin)->test(Shelf::class, ['chosen' => 'hello'])->assertSee('couldn’t be read');

        // Fixed, and tried again.
        $this->hello();
        $this->manager()->forget();
        Livewire::actingAs($this->admin)->test(Shelf::class, ['chosen' => 'hello'])->call('retry');
        $this->manager()->forget();
        $this->assertTrue($this->manager()->find('hello')->active());
    }

    public function test_an_insert_gets_a_page_in_the_book(): void
    {
        $this->hello();
        $this->manager()->enable('hello');
        $this->manager()->reboot();

        $this->actingAs(User::factory()->create(['role' => 'editor']))->get(route('admin.insert', 'hello'))->assertForbidden();

        $html = $this->actingAs($this->admin)->get(route('admin.insert', 'hello'))->assertOk()->getContent();
        $this->assertStringContainsString('Greetings', $html);
        $this->assertMatchesRegularExpression('/Chapter \d+ · an insert/i', $html, 'tucked into the People chapter');
        $this->assertStringContainsString(route('admin.insert', 'hello'), $this->get(route('admin.people'))->getContent(), 'People links to it');

        Livewire::actingAs($this->admin)->test(InsertPage::class, ['insert' => 'hello'])
            ->call('act', 'add')->assertHasErrors('form.name')
            ->set('form.name', 'Hana')->call('act', 'add')->assertHasNoErrors()->assertSet('form.name', '')->assertSee('Hana');

        $this->get(route('admin.insert', 'nope'))->assertNotFound();
    }

    public function test_inserts_hear_about_things_that_happen(): void
    {
        $this->hello();
        $this->manager()->enable('hello');
        $this->manager()->reboot();

        $post = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Kuih lapis'])->save();
        Livewire::actingAs($this->admin)->test(\App\Livewire\Admin\EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->call('setStatus', 'published')->call('save');

        $this->assertSame('published Kuih lapis', $this->manager()->find('hello')->records('log')->all()->first()?->field('title'));
    }

    public function test_its_files_are_served_only_from_its_assets_folder(): void
    {
        $this->hello();
        $this->get('/inserts/hello/assets/hello.css')->assertNotFound(); // off

        $this->manager()->enable('hello');
        $this->manager()->reboot();
        $this->get('/inserts/hello/assets/hello.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('/inserts/hello/assets/../insert.php')->assertNotFound();
        $this->get('/inserts/hello/assets/%2e%2e/insert.php')->assertNotFound();
    }

    public function test_safe_mode_leaves_every_insert_out(): void
    {
        $this->hello();
        $this->manager()->enable('hello');
        config(['booklet.safe_mode' => true]);
        $this->assertTrue($this->manager()->active()->isEmpty());
    }

    public function test_a_new_insert_can_be_made_from_the_command_line(): void
    {
        $this->artisan('booklet:insert', ['name' => 'Reading List'])->assertSuccessful();
        $this->assertFileExists("{$this->dir}/reading-list/insert.php");

        $this->manager()->forget();
        $this->manager()->enable('reading-list');
        $this->manager()->reboot();
        Livewire::actingAs($this->admin)->test(InsertPage::class, ['insert' => 'reading-list'])
            ->set('form.title', 'The Cake Bible')->call('act', 'add')->assertSee('The Cake Bible');

        $this->artisan('booklet:insert', ['name' => 'reading-list'])->assertFailed();
    }
}
