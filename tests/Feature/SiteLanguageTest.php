<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Site\Comments;
use App\Livewire\Site\Subscribe;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Readers' pages speak the site's language (Settings), and a translated
 * post speaks its own: the words around the writing, dates, and messages.
 */
class SiteLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin', 'name' => 'Nur']);
    }

    protected function siteIn(string $code): void
    {
        Booklet::globals('site')->merge(['language' => $code, 'per_page' => 1])->save();
    }

    protected function livePost(string $title, array $data = [], string $date = '2026-03-04 09:00:00')
    {
        return Booklet::entries()->make(
            ['collection' => 'posts', 'published' => true, 'date' => $date],
            ['title' => $title, 'content' => 'Words words words.', 'author' => $this->nur->id] + $data,
        )->save();
    }

    public function test_a_malay_site_speaks_malay_to_readers(): void
    {
        $this->siteIn('ms');
        $older = $this->livePost('Older one', [], '2026-02-01 09:00:00');
        $post = $this->livePost('Kopi pagi');

        $this->get('/blog')->assertOk()
            ->assertSee('<html lang="ms"', false)
            ->assertSee('Halaman 1 daripada 3')->assertSee('Selak halaman')
            ->assertSee('min bacaan')
            ->assertDontSee('Turn the page')->assertDontSee('min read');
        $this->get($post->uri())->assertOk()->assertSee('4 Mac 2026')->assertSee('oleh Nur')->assertSee('Langkau ke tulisan');
        $this->get('/search?q=zzzz')->assertOk()->assertSee('Tiada apa-apa ditemui untuk “zzzz”.', false);
        $this->get('/search?q=kopi')->assertOk()->assertSee('1 hasil untuk “kopi”', false);
        $this->get('/no-such-page')->assertNotFound()->assertSee('Halaman tidak ditemui');

        // What readers are told after they do something.
        Livewire::test(Subscribe::class)->set('email', 'not-an-email')->call('subscribe')->assertSee('alamat e-mel');
        $this->assertNotNull($older);
    }

    public function test_an_english_site_is_as_it_was(): void
    {
        $this->siteIn('en');
        $this->livePost('Older one', [], '2026-02-01 09:00:00');
        $post = $this->livePost('Morning coffee');

        $this->get('/blog')->assertOk()->assertSee('Page 1 of 3')->assertSee('Turn the page')->assertSee('min read')->assertDontSee('Halaman');
        $this->get($post->uri())->assertOk()->assertSee('4 March 2026')->assertSee('by Nur');
        $this->get('/search?q=coffee')->assertOk()->assertSee('1 result for “coffee”', false);
        $this->get('/search?q=zz')->assertOk()->assertSee('Try a shorter word, or browse <a href="', false);
    }

    public function test_a_malay_translation_on_an_english_site_speaks_malay(): void
    {
        $original = $this->livePost('Spring in the garden');
        $malay = Booklet::entries()->make(
            ['collection' => 'posts', 'published' => true, 'date' => '2026-03-04 09:00:00'],
            ['title' => 'Musim bunga di taman', 'content' => 'Pokok ara.', 'author' => $this->nur->id, 'translation_of' => $original->id(), 'lang' => 'ms'],
        )->setAttribute('slug', 'spring-in-the-garden-ms')->save();

        $this->get($malay->uri())->assertOk()->assertSee('4 Mac 2026')->assertSee('min bacaan')->assertDontSee('min read');
        $this->get($original->uri())->assertOk()->assertSee('4 March 2026')->assertSee('min read');

        // A note left under the translation is thanked in Malay too.
        app()->setLocale('en');
        Booklet::globals('site')->merge(['comments' => true, 'moderation' => true])->save();
        Livewire::test(Comments::class, ['entryId' => $malay->id()])
            ->set('name', 'Hana')->set('body', 'Cantik sekali.')->call('post')
            ->assertSee('Terima kasih — nota anda akan muncul setelah diluluskan.');
    }

    public function test_a_sections_starting_words_speak_the_sites_language(): void
    {
        app()->setLocale('ms');
        $start = ['recent_title' => 'Recently', 'about_title' => 'About this blog'];
        $f = new \App\PageBuilder\Fields(['recent_title' => 'Recently', 'about_title' => 'Our kitchen', 'eyebrow' => 'The blog'], false, '', [], $start);
        $this->assertSame('Baru-baru ini', $f->say('recent_title'));
        $this->assertSame('Our kitchen', $f->say('about_title'), 'words someone typed stay as typed');
        $this->assertSame('Blog', $f->say('eyebrow'));
        $this->assertStringContainsString('Baru-baru ini', (string) $f->text('recent_title', 'h2'));

        // While editing, the words are the ones in the box.
        $live = new \App\PageBuilder\Fields(['recent_title' => 'Recently'], true, '', [], $start);
        $this->assertSame('Recently', $live->say('recent_title'));
    }

    public function test_the_page_cache_is_emptied_when_the_language_changes(): void
    {
        $this->assertStringContainsString('PageCache::flush()', (string) file_get_contents(app_path('Livewire/Admin/Settings.php')));
    }
}
