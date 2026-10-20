<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Inserts\InsertManager;
use App\Livewire\Admin\InsertPage;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two inserts that come with Booklet: Gallery and Events.
 */
class GalleryAndEventsTest extends TestCase
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
        config(['booklet.inserts_path' => base_path('inserts')]);
        $this->app->forgetInstance(InsertManager::class);
    }

    protected function switchOn(string $key): void
    {
        app(InsertManager::class)->enable($key);
        app(InsertManager::class)->reboot();
    }

    protected function render(string $content): string
    {
        Booklet::entries()->findBySlug('posts', 'hello-from-booklet')->set('content', $content)->save();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);

        return $this->get('/blog/hello-from-booklet')->assertOk()->getContent();
    }

    public function test_gallery_albums_from_media_show_in_a_post(): void
    {
        $this->switchOn('gallery');
        $paths = [];
        foreach (['Kuih lapis', 'Pandan chiffon', 'No words'] as $i => $alt) {
            $asset = (new AssetUploader('media'))->store(UploadedFile::fake()->image("cake-$i.jpg", 1200, 900), 'blog');
            if ($i < 2) {
                $asset->set('alt', $alt)->save();
            }
            $paths[] = $asset->path();
        }

        $page = Livewire::actingAs($this->admin)->test(InsertPage::class, ['insert' => 'gallery'])
            ->call('act', 'create')->assertHasErrors('form.title')
            ->set('form.title', 'Kitchen days')->call('act', 'create')->assertHasNoErrors()
            ->assertSee('[[gallery: kitchen-days]]')->assertSee('No description');
        $page->call('act', 'picture', $paths[1])->call('act', 'picture', $paths[0])->call('act', 'picture', 'not/in/media.jpg');
        $page->call('act', 'move', ['path' => $paths[0], 'step' => -1]);

        $album = app(InsertManager::class)->find('gallery')->records('albums')->findBySlug('kitchen-days');
        $this->assertSame([$paths[0], $paths[1]], $album->field('pictures'), 'only Media pictures, in the chosen order');

        $html = $this->render("Look:\n\n[[gallery: Kitchen days]]");
        $this->assertSame(2, substr_count($html, 'class="gallery__open"'));
        $this->assertStringContainsString('alt="Kuih lapis"', $html);
        $this->assertStringContainsString('data-gallery', $html);
        $this->assertStringContainsString('/inserts/gallery/assets/gallery.js', $html);
        $this->assertStringNotContainsString('[[gallery', $this->render('[[gallery: no-such-album]]'), 'a missing album shows nothing');
    }

    public function test_events_have_a_page_calendar_files_and_a_slip(): void
    {
        $this->switchOn('events');
        $page = Livewire::actingAs($this->admin)->test(InsertPage::class, ['insert' => 'events']);

        $page->call('act', 'save')->assertHasErrors(['form.title', 'form.starts_date']);
        $page->set('form.title', 'Backwards')->set('form.starts_date', now()->addDays(3)->toDateString())->set('form.ends_date', now()->addDay()->toDateString())
            ->call('act', 'save')->assertHasErrors('form.ends_date');

        $page->set('form', ['title' => 'Kuih stall, Sunday market', 'starts_date' => now()->addDays(5)->toDateString(), 'starts_time' => '07:30', 'ends_date' => '', 'ends_time' => '12:00',
            'all_day' => false, 'venue' => 'Gadong, Bandar', 'address' => '', 'about' => 'Fresh; early', 'link' => ''])->call('act', 'save')->assertHasNoErrors();
        $page->set('form', ['title' => 'Open house', 'starts_date' => now()->subDays(20)->toDateString(), 'starts_time' => '', 'ends_date' => '', 'ends_time' => '',
            'all_day' => true, 'venue' => '', 'address' => '', 'about' => '', 'link' => ''])->call('act', 'save')->assertHasNoErrors();

        $this->get('/events')->assertOk()->assertSee('What’s on')->assertSee('Kuih stall, Sunday market')->assertSee('Been and gone')->assertSee('Open house')
            ->assertSee('Add to my calendar');

        $ics = $this->get('/events/kuih-stall-sunday-market.ics')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Kuih stall\, Sunday market', $ics, 'commas escaped as calendars expect');
        $this->assertStringContainsString('DTSTART:'.now()->addDays(5)->setTime(7, 30)->utc()->format('Ymd\THis\Z'), $ics);
        $this->assertStringContainsString('DESCRIPTION:Fresh\; early', $ics);
        $this->assertSame(1, substr_count($this->get('/events.ics')->getContent(), 'BEGIN:VEVENT'), 'the feed is what’s coming up');

        $html = $this->render("Come and see us:\n\n[[events]]");
        $this->assertStringContainsString('events-slip', $html);
        $this->assertStringContainsString('Kuih stall, Sunday market', $html);
        $this->assertStringNotContainsString('Open house', $html);
    }

    public function test_switching_events_off_takes_it_out_of_posts(): void
    {
        $this->switchOn('events');
        app(InsertManager::class)->find('events')->records('events')->create(['title' => 'Market day', 'starts' => now()->addDay()->format('Y-m-d').' 08:00', 'all_day' => false]);
        $this->assertStringContainsString('Market day', $this->render('[[events]]'));

        app(InsertManager::class)->disable('events');
        app(InsertManager::class)->reboot();
        $html = $this->render('[[events]]');
        $this->assertStringNotContainsString('Market day', $html);
        $this->assertStringNotContainsString('[[events]]', $html, 'its slip quietly goes, rather than showing as text');
        $this->assertStringNotContainsString('/inserts/events/assets/', $html);
    }
}
