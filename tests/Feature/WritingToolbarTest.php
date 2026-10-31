<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\Markdown;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The writing toolbar's Markdown reaches readers as they'd expect, and its
 * Picture button puts a picture from Media into the words.
 */
class WritingToolbarTest extends TestCase
{
    use RefreshDatabase;

    public function test_what_the_toolbar_writes_is_drawn_on_the_site(): void
    {
        $html = Markdown::html("~~old~~ ==new== `code`\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n\n- [ ] to do\n- [x] done\n\n1. one\n2. two\n\n---");

        $this->assertStringContainsString('<del>old</del>', $html);
        $this->assertStringContainsString('<mark>new</mark>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<hr', $html);

        $this->assertStringNotContainsString('<script', Markdown::html('<script>alert(1)</script> [x](javascript:alert(1))'), 'raw HTML and unsafe links still go');
        $this->assertStringContainsString('<mark>', (string) app(\App\Booklet\Site::class)->markdown('==yes=='));
    }

    public function test_the_picture_button_sends_the_chosen_picture_to_the_words(): void
    {
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Booklet::entries()->whereCollection('posts')->first();

        Livewire::actingAs($admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->call('openPicker', '@body')->assertSet('picking', '@body')
            ->call('choose', 'blog/kuih-lapis.jpg')
            ->assertSet('picking', null)
            // No alt text written for it: its name stands in.
            ->assertDispatched('booklet-picture', fn ($name, $params) => str_starts_with($params['markdown'], '![kuih-lapis](') && str_contains($params['markdown'], 'blog/kuih-lapis.jpg'));
    }

    public function test_the_toolbar_has_its_new_buttons(): void
    {
        $bar = (string) file_get_contents(resource_path('views/livewire/admin/partials/editor-format-bar.blade.php'));
        foreach (['h2', 'h3', 'strike', 'mark', 'code', 'picture', 'numbers', 'tasks', 'codeblock', 'table', 'divider', 'undo', 'redo'] as $kind) {
            $this->assertStringContainsString("\$btn('{$kind}'", $bar, $kind);
        }
        $js = (string) file_get_contents(public_path('booklet/booklet.js'));
        $this->assertStringContainsString("document.execCommand('insertText', false, text)", $js, 'changes keep the undo history');
        $this->assertStringContainsString('_continueList(e, ta)', $js);
    }
}
