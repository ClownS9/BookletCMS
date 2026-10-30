<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Support\Headings;
use App\Support\Pictures;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the readers' page check-up found, kept fixed: fonts don't hold the
 * page up, the first pictures aren't held back, and headings that skip a
 * step are pointed out to the writer.
 */
class ReaderPagesCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
    }

    public function test_the_fonts_dont_hold_up_the_page(): void
    {
        \App\Models\User::factory()->create(['role' => 'admin']);
        $this->get('/blog')->assertOk()
            ->assertSee('rel="preload" as="style" href="https://fonts.googleapis.com', false)
            ->assertSee('media="print" onload="this.media=\'all\'"', false)
            ->assertSee('<noscript><link rel="stylesheet" href="https://fonts.googleapis.com', false);
    }

    public function test_the_first_pictures_on_a_page_come_at_once_and_the_rest_wait(): void
    {
        $this->assertSame(['eager', 'eager', 'lazy', 'lazy'], [Pictures::loading(), Pictures::loading(), Pictures::loading(), Pictures::loading()]);
    }

    public function test_headings_that_skip_a_step_are_found(): void
    {
        $this->assertNull(Headings::skip("Intro\n\n## Mornings\n\n### Kopi\n\n## Evenings"));
        $this->assertSame(['text' => 'Mornings', 'level' => 3, 'after' => 1], Headings::skip("Hello\n\n### Mornings\n\nWords."));
        $this->assertSame(['text' => 'Kopi', 'level' => 4, 'after' => 2], Headings::skip("## Mornings\n\n#### Kopi"));
        $this->assertNull(Headings::skip("```\n### not a heading\n```\n\n## Fine"), 'code is left out');
        $this->assertSame(3, Headings::skip('<h3>From an import</h3>')['level']);
    }

    public function test_the_writing_page_points_out_a_skipped_heading(): void
    {
        $nur = \App\Models\User::factory()->create(['role' => 'admin']);
        \Livewire\Livewire::actingAs($nur)->test(\App\Livewire\Admin\EntryEditor::class, ['collection' => 'posts'])
            ->set('values.title', 'Kopi')->set('values.content', "Hello\n\n### Mornings")
            ->assertSee('“Mornings” is a ### heading right under the title.', false)
            ->set('values.content', "Hello\n\n## Mornings")
            ->assertDontSee('heading right under the title');
    }
}
