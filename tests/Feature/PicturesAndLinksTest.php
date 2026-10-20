<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Livewire\Admin\Redirects;
use App\Models\User;
use App\Stacy\Nudges;
use App\Support\LinkCheck;
use App\Support\Pictures;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pictures at the right size (sized WebP copies in srcset) and the weekly
 * check for links that lead nowhere.
 */
class PicturesAndLinksTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin']);
    }

    protected function picture(string $name = 'cake.jpg', int $width = 2400, int $height = 1600): string
    {
        return (new AssetUploader('media'))->store(UploadedFile::fake()->image($name, $width, $height), 'photos')->path();
    }

    public function test_big_pictures_get_sized_copies_in_srcset(): void
    {
        $this->skipUnless();
        $path = $this->picture();
        $post = Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->subDay()->toDateTimeString()],
            ['title' => 'Cake', 'cover' => [$path], 'content' => "Look:\n\n![A cake](/storage/{$path})"])->save();

        $this->get($post->uri())->assertOk()
            ->assertSee('/storage/sized/640/'.$path.'.webp 640w', false)
            ->assertSee('/storage/'.$path.' 2400w', false)
            ->assertSee('loading="lazy" decoding="async" srcset=', false);

        $this->assertSame('', Pictures::srcset($this->picture('tiny.png', 200, 120)), 'small pictures are left alone');
    }

    public function test_a_copy_is_made_the_first_time_its_asked_for(): void
    {
        $this->skipUnless();
        $path = $this->picture();

        $this->get('/storage/sized/640/'.$path.'.webp')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertTrue(Storage::disk('public')->exists('sized/640/'.$path.'.webp'));
        [$w] = getimagesize(Storage::disk('public')->path('sized/640/'.$path.'.webp'));
        $this->assertSame(640, $w);

        // Never bigger than the original, and nothing outside Media.
        $small = $this->picture('small.jpg', 500, 400);
        $this->get('/storage/sized/960/'.$small.'.webp')->assertRedirect('/storage/'.$small);
        $this->get('/storage/sized/640/../../.env.webp')->assertNotFound();
        $this->get('/storage/sized/123/'.$path.'.webp')->assertRedirect();

        // Into the bin: its copies go too.
        \App\Support\Bin::asset(Booklet::assets()->find(['container' => 'media', 'path' => $path]));
        $this->assertFalse(Storage::disk('public')->exists('sized/640/'.$path.'.webp'));
    }

    public function test_media_doesnt_pick_up_booklets_own_folders(): void
    {
        Storage::disk('public')->put('sized/640/photos/x.jpg.webp', 'x');
        Storage::disk('public')->put('avatars/1-abc.jpg', 'x');
        Storage::disk('public')->put('photos/found-by-ftp.txt', 'hello');

        $this->actingAs($this->nur);
        Livewire::test(\App\Livewire\Admin\Media::class)->call('sync');

        $paths = Booklet::assets()->all()->map->path()->all();
        $this->assertContains('photos/found-by-ftp.txt', $paths);
        $this->assertNotContains('sized/640/photos/x.jpg.webp', $paths);
        $this->assertNotContains('avatars/1-abc.jpg', $paths);
    }

    public function test_links_that_lead_nowhere_are_found(): void
    {
        $live = Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->subDays(3)->toDateTimeString()], ['title' => 'Live one'])->save();
        $draft = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Not yet'])->save();
        \App\Models\Redirect::create(['from_path' => '/old-hours', 'to_path' => $live->uri(), 'status' => 301]);

        Http::fake([
            'https://gone.example/*' => Http::response('', 404),
            'https://fine.example/*' => Http::response('ok', 200),
            'https://shy.example/*' => Http::response('no robots', 403),
        ]);

        Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->subDay()->toDateTimeString()], [
            'title' => 'Full of links',
            'content' => "[live]({$live->uri()}) [moved](/old-hours) [draft]({$draft->uri()}) [nowhere](/no-such-page) [search](/search)\n\n"
                ."[gone](https://gone.example/page) [fine](https://fine.example/) [shy](https://shy.example/x) [mail](mailto:a@b.c)",
        ])->save();

        $report = LinkCheck::run();
        $bad = collect($report['broken'])->pluck('url')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$draft->uri(), '/no-such-page', 'https://gone.example/page'], $bad);

        // Stacy mentions it, and Redirects shows it with a way to forward the missing address.
        $this->assertNotEmpty(collect(Nudges::for($this->nur))->filter(fn ($n) => str_starts_with($n['key'], 'links-')));
        $this->actingAs($this->nur);
        Livewire::test(Redirects::class)->assertSee('Broken links')->assertSee('no-such-page')
            ->call('forward', '/no-such-page')->assertSet('from', '/no-such-page');
    }

    protected function skipUnless(): void
    {
        if (! Pictures::available()) {
            $this->markTestSkipped('This PHP has no GD with WebP.');
        }
    }
}
