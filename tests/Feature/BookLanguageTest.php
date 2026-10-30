<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Account;
use App\Models\User;
use App\Support\BookLanguage;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The book in Malay (or English): each person chooses; the sign-in page
 * follows the browser; readers' pages are never changed.
 */
class BookLanguageTest extends TestCase
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

    public function test_a_person_chooses_malay_and_the_book_speaks_it(): void
    {
        $this->actingAs($this->admin)->get(route('admin.contents'))->assertOk()->assertSee('Table of contents')->assertSee('<html lang="en">', false);

        Livewire::actingAs($this->admin)->test(Account::class)->assertSee('The book’s language')
            ->set('language', 'ms')->assertRedirect(route('admin.account'));
        $this->assertSame('ms', $this->admin->fresh()->preferences['locale']);

        $this->actingAs($this->admin->fresh())->get(route('admin.contents'))->assertOk()
            ->assertSee('<html lang="ms">', false)
            ->assertSee('Senarai kandungan')->assertSee('Halaman berlipat')->assertSee('Log keluar')
            ->assertDontSee('Table of contents');
        $this->actingAs($this->admin->fresh())->get(route('admin.entries', 'posts'))->assertOk()->assertSee('Cari dalam bab ini')->assertSee('Diterbitkan');
        $this->actingAs($this->admin->fresh())->get(route('admin.inbox'))->assertOk()->assertSee('Peti Masuk')->assertSee('Papar borang hubungi');

        // A word with nothing to translate it stays English, rather than breaking.
        app()->setLocale('ms');
        $this->assertSame('Something nobody translated', __('Something nobody translated'));
        $this->assertSame(':count draf sedang menunggu anda.', trans_choice(':count draft is waiting for you.|:count drafts are waiting for you.', 2, ['count' => ':count']));
        $this->assertSame('Medan e-mel wajib diisi.', __('validation.required', ['attribute' => __('validation.attributes.email')]));
    }

    public function test_sign_in_follows_the_browser_and_readers_pages_stay_as_they_are(): void
    {
        $this->get('/login', ['Accept-Language' => 'ms-BN,ms;q=0.9,en;q=0.5'])->assertOk()->assertSee('Buka buku anda')->assertSee('Lupa kata laluan?');
        $this->get('/login', ['Accept-Language' => 'en-GB,en;q=0.9'])->assertOk()->assertSee('Open your book');
        // The last choice in this browser wins over the browser's language.
        $this->withUnencryptedCookie(BookLanguage::COOKIE, 'en')->get('/login', ['Accept-Language' => 'ms'])->assertOk()->assertSee('Open your book');

        // A reader's page, even for someone who reads the book in Malay.
        $this->admin->update(['preferences' => ['locale' => 'ms']]);
        $this->actingAs($this->admin)->get('/about')->assertOk()->assertDontSee('<html lang="ms"', false);
        $this->assertFalse(BookLanguage::isBook(\Illuminate\Http\Request::create('/about')));
        $this->assertTrue(BookLanguage::isBook(\Illuminate\Http\Request::create('/admin/media')));
        $update = \Illuminate\Http\Request::create('/livewire/update', 'POST', server: ['HTTP_REFERER' => url('/admin/inbox')]);
        $this->assertTrue(BookLanguage::isBook($update), 'a change made on a book page');
        $this->assertFalse(BookLanguage::isBook(\Illuminate\Http\Request::create('/livewire/update', 'POST', server: ['HTTP_REFERER' => url('/contact')])));
    }

    public function test_settings_the_appendices_and_the_page_builder_speak_malay(): void
    {
        $this->admin->update(['preferences' => ['locale' => 'ms']]);
        $me = $this->admin->fresh();

        $this->actingAs($me)->get(route('admin.settings'))->assertOk()->assertSee('Asas tentang laman anda.')->assertDontSee('The basics about your site.');
        $this->actingAs($me)->get(route('admin.settings.health'))->assertOk()->assertSee('Setiap semakan')->assertSee('Pelayan')
            ->assertSee('semakan, dibuat sebentar tadi.')->assertDontSee('checks, made just now')->assertDontSee('thing to fix');
        $this->actingAs($me)->get(route('admin.backups'))->assertOk()->assertSee('Buat sandaran sekarang');
        $this->actingAs($me)->get(route('admin.activity'))->assertOk()->assertDontSee('Every change, newest first');
        $this->actingAs($me)->get(route('admin.settings.version-control'))->assertOk()->assertSee('Kandungan anda disimpan dalam pangkalan data');

        // Counts say their number the Malay way, not “3 things”.
        app()->setLocale('ms');
        $this->assertSame('3 perkara untuk dibaiki', trans_choice(':count thing to fix|:count things to fix', 3));
        $this->assertSame('Bersambung (SQLite).', __('Connected (:name).', ['name' => 'SQLite']));
        app()->setLocale('en');
        $this->assertSame('1 thing to fix', trans_choice(':count thing to fix|:count things to fix', 1));

        // The page builder: its drawer and bar answer in the book's language; the page itself stays the site's.
        $edit = $this->actingAs($me)->getJson(route('builder.edit', 'blog'))->assertOk();
        $this->assertSame('Mengikut Kulit Buku', $edit->json('bar.words'));
        $this->assertStringContainsString('Semua bahagian', (string) $edit->json('drawer'));
        $this->assertStringNotContainsString('All sections', (string) $edit->json('drawer'));

        // The kit's names and choices, in the drawer's language; what a section starts with is left alone.
        app()->setLocale('ms');
        $builder = app(\App\PageBuilder\Builder::class);
        $this->assertSame('Blog', $builder->types()['blog-posts']['group']);
        $this->assertSame('Kiriman', $builder->types()['blog-posts']['name']);
        $this->assertSame('Telefon sahaja', $builder->looks()['show']['options']['phone']);
        $this->assertSame('About this blog', $builder->types()['blog-posts']['start']['about_title'], 'starting words are the site’s');
        app()->setLocale('en');
        $this->assertSame('Phones only', $builder->looks()['show']['options']['phone']);

        // The editor's script gets its words, in Malay, with the page.
        $words = \App\Support\ScriptWords::for('builder-edit.js', 'ms');
        $this->assertSame('Buat asal', $words['Undo']);
        $this->assertSame('Laci', $words['Drawer']);
        $this->assertSame([], \App\Support\ScriptWords::for('builder-edit.js', 'en'));
    }

    public function test_every_word_on_the_translated_pages_has_its_malay(): void
    {
        $ms = json_decode((string) file_get_contents(lang_path('ms.json')), true);
        $missing = [];
        $odd = [];
        $files = array_merge(glob(resource_path('views/livewire/admin/*.blade.php')), glob(resource_path('views/livewire/admin/*/*.blade.php')),
            glob(resource_path('views/livewire/auth/*.blade.php')), glob(resource_path('views/components/**/*.blade.php')), glob(resource_path('views/components/*/*.blade.php')),
            [app_path('Support/AdminNav.php'), app_path('Support/SearchIndex.php'), app_path('Livewire/Auth/Login.php')]);
        foreach ($files as $file) {
            preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $key = str_replace("\\'", "'", $key);
                if (! array_key_exists($key, $ms) && ! in_array($key, ['booklet', 'abcd-efgh', 'en-GB', 'https://'], true)) {
                    $missing[] = basename($file).': '.$key;
                }
            }
        }
        foreach ($ms as $key => $words) {
            preg_match_all('/:[a-z]+/', $key, $a);
            preg_match_all('/:[a-z]+/', $words, $b);
            if (array_unique($a[0]) != array_unique($b[0]) && collect($a[0])->diff($b[0])->isNotEmpty()) {
                $odd[] = $key;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'every word on these pages has its Malay');
        $this->assertSame([], $odd, 'every :placeholder is kept in the Malay');
    }
}
