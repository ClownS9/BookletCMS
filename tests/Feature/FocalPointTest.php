<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Livewire\Admin\Media;
use App\Models\User;
use App\Support\Focus;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Focal points: the spot that matters stays in view wherever a picture is cropped.
 */
class FocalPointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Storage::disk('public')->putFileAs('photos', UploadedFile::fake()->image('cake.jpg', 1200, 800), 'cake.jpg');
        (new AssetUploader('media'))->record('photos/cake.jpg');
    }

    public function test_reading_and_writing_focus(): void
    {
        $this->assertSame('30-20-1', Focus::store(30.4, 19.6));
        $this->assertSame([30, 20], Focus::parse('30-20-1'));
        $this->assertSame('100% 0%', Focus::css(Focus::store(140, -5)), 'kept inside the picture');
        $this->assertSame('50% 50%', Focus::css(null));
        $this->assertSame('50% 50%', Focus::css('nonsense'));
    }

    public function test_setting_it_on_media_and_putting_it_back_in_the_middle(): void
    {
        $media = Livewire::test(Media::class)
            ->call('pick', 'photos/cake.jpg')
            ->assertSee('Focal point')
            ->call('setFocus', 72, 18);

        $this->assertSame('72-18-1', Booklet::assets()->find(['container' => 'media', 'path' => 'photos/cake.jpg'])->get('focus'));

        $media->call('clearFocus');
        $this->assertNull(Booklet::assets()->find(['container' => 'media', 'path' => 'photos/cake.jpg'])->get('focus'));
    }

    public function test_the_site_keeps_the_focal_point_in_view(): void
    {
        $asset = Booklet::assets()->find(['container' => 'media', 'path' => 'photos/cake.jpg']);
        $asset->set('focus', '72-18-1')->save();

        $post = Booklet::entries()->whereCollection('posts')->first(fn ($e) => $e->status() === 'published');
        $post->set('cover', ['photos/cake.jpg'])->save();

        $this->get($post->uri())->assertOk()->assertSee('object-position: 72% 18%', false);
        $this->get('/blog')->assertOk()->assertSee('object-position: 72% 18%', false);
    }
}
