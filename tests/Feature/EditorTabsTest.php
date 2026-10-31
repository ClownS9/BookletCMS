<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\YourBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The editor's margin in two tabs, Main and SEO, so it isn't one long column.
 */
class EditorTabsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function firstPost()
    {
        return Booklet::entries()->all()->first(fn ($e) => $e->collectionHandle() === 'posts');
    }

    public function test_posts_and_pages_have_main_and_seo_tabs(): void
    {
        foreach (['posts', 'pages'] as $collection) {
            $html = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => $collection])
                ->assertSee('margin-tabs', false)
                ->assertSee('id="margin-tab-seo"', false)
                ->html();

            // The search fields and the share picture are in the SEO tab, not in Main.
            $main = substr($html, strpos($html, 'id="margin-main"'), strpos($html, 'id="margin-seo"') - strpos($html, 'id="margin-main"'));
            $seo = substr($html, strpos($html, 'id="margin-seo"'));
            $this->assertStringContainsString('search-card', $seo);
            $this->assertStringContainsString('Share image', $seo);
            $this->assertStringNotContainsString('Share image', $main);
            $this->assertStringNotContainsString('search-card', $main);
        }
    }

    public function test_the_open_tab_is_in_the_address(): void
    {
        $post = $this->firstPost();
        Livewire::withQueryParams(['tab' => 'seo'])->actingAs($this->admin)
            ->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->assertSet('side', 'seo');

        Livewire::withQueryParams(['tab' => 'nonsense'])->actingAs($this->admin)
            ->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->assertSet('side', 'main');
    }

    public function test_a_problem_in_one_tab_opens_it(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts'])->instance();

        $editor->exception(ValidationException::withMessages(['values.meta_title' => 'Too long.']), fn () => null);
        $this->assertSame('seo', $editor->side);

        $editor->exception(ValidationException::withMessages(['values.title' => 'Needed.']), fn () => null);
        $this->assertSame('main', $editor->side);

        // For real: saving without a title, while looking at SEO, opens Main.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts'])
            ->set('side', 'seo')->set('values.title', '')->call('save')
            ->assertHasErrors('values.title')->assertSet('side', 'main');
    }

    public function test_both_layouts_show_the_tabs(): void
    {
        $post = $this->firstPost();
        foreach (['book', 'dashboard'] as $layout) {
            YourBook::save($this->admin, ['layout' => $layout]);
            $this->actingAs($this->admin->fresh())->get(route('admin.entries.edit', ['posts', $post->id()]))
                ->assertOk()->assertSee('margin-tabs', false)->assertSee('SEO');
        }
    }
}
