<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\PrintBook as PrintBookPage;
use App\Models\User;
use App\Support\PrintBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Print as a book: choose what goes in, then the typeset view (the pages
 * themselves are cut in the browser by booklet/print.js).
 */
class PrintBookTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin', 'name' => 'Nur Aisyah']);
        $this->actingAs($this->nur);

        foreach ([['Spring', '2026-03-02', ['garden']], ['Summer', '2026-06-10', []], ['Autumn', '2026-09-21', ['garden']]] as [$title, $date, $tags]) {
            Booklet::entries()->make(
                ['collection' => 'posts', 'published' => true, 'date' => $date.' 09:00:00'],
                ['title' => $title, 'content' => "The **{$title}** chapter.\n\nA second paragraph.", 'author' => $this->nur->id, 'tags' => $tags],
            )->save();
        }
        Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Unfinished winter', 'content' => 'x', 'author' => $this->nur->id])->save();
    }

    protected function titles(array $o): array
    {
        return PrintBook::entries(PrintBook::normalise($o))->map->title()->filter(fn ($t) => in_array($t, ['Spring', 'Summer', 'Autumn', 'Unfinished winter'], true))->values()->all();
    }

    public function test_only_published_entries_in_reading_order(): void
    {
        $this->assertSame(['Spring', 'Summer', 'Autumn'], $this->titles(['collection' => 'posts']));
        $this->assertSame(['Autumn', 'Summer', 'Spring'], $this->titles(['collection' => 'posts', 'order' => 'newest']));
    }

    public function test_dates_tags_and_authors_narrow_it_down(): void
    {
        $this->assertSame(['Summer', 'Autumn'], $this->titles(['collection' => 'posts', 'from' => '2026-05-01']));
        $this->assertSame(['Spring'], $this->titles(['collection' => 'posts', 'to' => '2026-03-31']));
        $this->assertSame(['Spring', 'Autumn'], $this->titles(['collection' => 'posts', 'tag' => 'garden']));
        $this->assertSame([], $this->titles(['collection' => 'posts', 'author' => '999']));
    }

    public function test_odd_choices_fall_back_to_safe_ones(): void
    {
        $o = PrintBook::normalise(['collection' => 'nope', 'size' => 'poster', 'from' => 'yesterday', 'tag' => '<script>', 'title' => '']);

        $this->assertSame('posts', $o['collection']);
        $this->assertSame('a5', $o['size']);
        $this->assertSame('', $o['from']);
        $this->assertSame('', $o['tag']);
        $this->assertNotSame('', $o['title'], 'the site name stands in');
    }

    public function test_the_options_page(): void
    {
        $this->get(route('admin.print'))->assertOk()->assertSee('Print as a book');

        Livewire::test(PrintBookPage::class)
            ->set('title', 'A Year in the Garden')
            ->set('tag', 'garden')
            ->assertSee('A Year in the Garden')
            ->assertSee('Spring')
            ->assertDontSee('Summer')
            ->call('remember');

        $this->assertSame('garden', $this->nur->fresh()->preferences['print_book']['tag'], 'remembered for next time');
        Livewire::test(PrintBookPage::class)->assertSet('title', 'A Year in the Garden');
    }

    public function test_the_typeset_book(): void
    {
        $this->get(route('admin.print.book', ['collection' => 'posts', 'size' => 'trade', 'title' => 'Seasons', 'byline' => 'N. Aisyah']))
            ->assertOk()
            ->assertSee('@page { size: 152.4mm 228.6mm; margin: 0; }', false)
            ->assertSee('Seasons')
            ->assertSee('N. Aisyah')
            ->assertSee('<strong>Spring</strong>', false)
            ->assertSee('data-target="ch-0"', false)
            ->assertDontSee('Unfinished winter')
            ->assertSee('booklet/print.js', false);
    }

    public function test_signed_out_people_cannot_print(): void
    {
        auth()->logout();
        $this->get(route('admin.print.book'))->assertRedirect();
    }
}
