<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use App\Livewire\Admin\EntryEditor;
use App\Models\User;
use App\Support\SearchCard;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Search & sharing: the Google result and share cards for an entry, the
 * gentle notes about it, and the site using the description you write.
 */
class SearchCardTest extends TestCase
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

    protected function entry(array $data = [], string $slug = 'kuih-lapis'): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(['collection' => 'posts', 'slug' => $slug], $data + ['title' => 'Kuih lapis, nine layers', 'content' => 'Steam each layer for five minutes before the next.'])->save();
    }

    protected function card(\App\Booklet\Data\Entry $entry): array
    {
        return SearchCard::for($entry, app(Site::class)->url($entry));
    }

    protected function hints(array $card): string
    {
        return implode(' | ', array_column($card['hints'], 'text'));
    }

    public function test_a_post_as_google_and_a_share_show_it(): void
    {
        $card = $this->card($this->entry());
        $site = app(Site::class)->name();

        $this->assertSame('Kuih lapis, nine layers — '.$site, $card['google']['title']);
        $this->assertStringContainsString('Steam each layer', $card['google']['description']);
        $this->assertStringContainsString('› blog › kuih-lapis', $card['google']['crumbs']);
        $this->assertSame('Kuih lapis, nine layers', $card['share']['title']);
        $this->assertStringContainsString('No search description of its own', $this->hints($card));
    }

    public function test_it_notices_what_could_be_better(): void
    {
        $this->entry(['title' => 'Pandan chiffon'], 'pandan');
        $card = $this->card($this->entry([
            'title' => 'Pandan chiffon',
            'meta_description' => str_repeat('Soft, green and tall. ', 10),
            'content' => "Here it is:\n\n![](media/chiffon.jpg)\n\n![A slice on a plate](media/slice.jpg)",
            'cover' => ['media/not-described.jpg'],
        ], 'pandan-again'));
        $hints = $this->hints($card);

        $this->assertStringContainsString('“Pandan chiffon” has the same title', $hints);
        $this->assertStringContainsString('Google cuts it at about 160', $hints);
        $this->assertStringContainsString('2 pictures have no description', $hints, 'the cover, and one picture in the text');

        $long = $this->card($this->entry(['title' => 'A very long title about kuih lapis, nine layers of patience and butter'], 'long'));
        $this->assertStringContainsString('gets cut off', $this->hints($long));
        $this->assertStringEndsWith('…', $long['google']['title']);
    }

    public function test_the_editor_shows_it_live_while_typing(): void
    {
        $post = $this->entry();

        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->assertSee('Search &amp; sharing', false)
            ->assertSee('Kuih lapis, nine layers — ')
            ->set('values.meta_title', 'Kuih lapis: the nine-layer cake, step by step, with patience and plenty of butter')
            ->assertSee('Kuih lapis: the nine-layer cake')
            ->assertSee('gets cut off')
            ->set('values.meta_description', 'How to steam kuih lapis one layer at a time, so every stripe comes out clean and even.')
            ->assertSee('How to steam kuih lapis one layer at a time')
            ->assertDontSee('No search description of its own');
    }

    public function test_the_site_uses_the_description_you_wrote(): void
    {
        $post = $this->entry(['meta_description' => 'Nine thin layers, steamed one by one.']);

        $this->get(app(Site::class)->url($post))
            ->assertOk()
            ->assertSee('<meta name="description" content="Nine thin layers, steamed one by one.">', false)
            ->assertSee('<meta property="og:description" content="Nine thin layers, steamed one by one.">', false);
    }
}
