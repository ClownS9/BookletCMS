<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Support\Milestones;
use App\Support\PrintBook;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Translations on the public site: one of each post in the lists, a
 * language switcher on every version, and the right hreflang links.
 */
class SiteTranslationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
    }

    protected function pair(bool $translationLive = true): array
    {
        $nur = User::factory()->create(['role' => 'admin']);
        $original = Booklet::entries()->make(
            ['collection' => 'posts', 'published' => true, 'date' => now()->subDays(2)->toDateTimeString()],
            ['title' => 'Spring in the garden', 'content' => 'The fig tree is out.', 'author' => $nur->id, 'tags' => ['garden']],
        )->save();
        $malay = Booklet::entries()->make(
            ['collection' => 'posts', 'published' => $translationLive, 'date' => now()->subDays(2)->toDateTimeString()],
            ['title' => 'Musim bunga di taman', 'content' => 'Pokok ara sudah keluar.', 'author' => $nur->id, 'tags' => ['garden'], 'translation_of' => $original->id(), 'lang' => 'ms'],
        )->setAttribute('slug', 'spring-in-the-garden-ms')->save();

        return [$nur, $original, $malay];
    }

    public function test_lists_show_each_post_once(): void
    {
        [, $original, $malay] = $this->pair();

        $this->get('/blog')->assertOk()->assertSee('Spring in the garden')->assertDontSee('Musim bunga di taman');
        $this->get(route('site.feed'))->assertOk()->assertDontSee('Musim bunga di taman');
        $this->get('/search?q=pokok')->assertOk()->assertSee('Musim bunga di taman'); // still findable by its own words
    }

    public function test_each_version_links_to_the_others(): void
    {
        [, $original, $malay] = $this->pair();

        $this->get($original->uri())->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('hreflang="ms" href="'.url($malay->uri()).'"', false)
            ->assertSee('hreflang="x-default" href="'.url($original->uri()).'"', false)
            ->assertSee('Bahasa Melayu');

        $this->get($malay->uri())->assertOk()
            ->assertSee('<html lang="ms"', false)
            ->assertSee('Baca dalam')
            ->assertSee('hreflang="en"', false);
    }

    public function test_a_translation_still_in_draft_isnt_offered(): void
    {
        [, $original] = $this->pair(translationLive: false);

        $this->get($original->uri())->assertOk()->assertDontSee('hreflang="ms"', false)->assertDontSee('Bahasa Melayu');
    }

    public function test_the_sitemap_pairs_the_languages(): void
    {
        [, $original, $malay] = $this->pair();

        $this->get(route('site.sitemap'))->assertOk()
            ->assertSee('xmlns:xhtml', false)
            ->assertSee('<xhtml:link rel="alternate" hreflang="ms" href="'.url($malay->uri()).'"/>', false);
    }

    public function test_translations_are_not_new_posts_for_stickers_but_can_be_printed(): void
    {
        [$nur] = $this->pair();

        $this->assertSame(1, Milestones::for($nur)->firstWhere('key', 'post-10')['have']);

        $this->assertSame(['ms' => 'Bahasa Melayu'], PrintBook::languagesIn('posts'));
        $titles = PrintBook::entries(PrintBook::normalise(['collection' => 'posts', 'lang' => 'ms']))->map->title()->all();
        $this->assertSame(['Musim bunga di taman'], $titles);
        $this->assertNotContains('Musim bunga di taman', PrintBook::entries(PrintBook::normalise(['collection' => 'posts']))->map->title()->all());
    }
}
