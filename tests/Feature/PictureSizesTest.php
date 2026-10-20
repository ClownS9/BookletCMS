<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\AssetUploader;
use App\Livewire\Admin\Media;
use App\Models\User;
use App\Support\Crops;
use App\Support\Focus;
use App\Support\Pictures;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Named picture sizes (Statamic's Glide presets), cut around the focal point.
 */
class PictureSizesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Pictures::available()) {
            $this->markTestSkipped('GD with WebP is needed to cut pictures.');
        }
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** A 1200×600 picture: red on the left half, blue on the right. */
    protected function redBlue(): string
    {
        $image = imagecreatetruecolor(1200, 600);
        imagefilledrectangle($image, 0, 0, 599, 599, imagecolorallocate($image, 220, 30, 30));
        imagefilledrectangle($image, 600, 0, 1199, 599, imagecolorallocate($image, 30, 60, 220));
        $file = tempnam(sys_get_temp_dir(), 'rb').'.png';
        imagepng($image, $file);
        imagedestroy($image);

        return (new AssetUploader('media'))->store(new UploadedFile($file, 'red-blue.png', 'image/png', null, true), 'blog')->path();
    }

    protected function focus(string $path, int $x, int $y): void
    {
        Booklet::assets()->find(['container' => 'media', 'path' => $path])->set('focus', Focus::store($x, $y))->save();
        Booklet::flush();
    }

    /** Fetch a crop the way a browser would, and say which colour its middle is. */
    protected function middleOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $file = Storage::disk('public')->path(substr($path, strlen('/storage/')));
        $image = imagecreatefromwebp($file);
        $rgb = imagecolorat($image, (int) (imagesx($image) / 2), (int) (imagesy($image) / 2));

        return (($rgb >> 16) & 0xFF) > (($rgb) & 0xFF) ? 'red' : 'blue';
    }

    public function test_sizes_have_sensible_defaults_and_names(): void
    {
        $this->assertSame(['card', 'hero', 'square', 'tall', 'share'], array_keys(Crops::sizes()));
        $this->assertSame('4:3', Crops::ratio(1200, 900));
        $this->assertSame('1:1', Crops::ratio(800, 800));
        $this->assertSame('1.91:1', Crops::ratio(1200, 630));
        $this->assertSame('21:9', Crops::ratio(700, 300));
        $this->assertSame('5:3', Crops::ratio(1000, 600));

        $clean = Crops::clean(['Big Banner' => ['width' => 1600, 'height' => 400], 'tiny' => ['width' => 4, 'height' => 4], 'huge' => ['width' => 9000, 'height' => 10]]);
        $this->assertSame(['big_banner' => ['label' => 'Big Banner', 'width' => 1600, 'height' => 400]], $clean);
    }

    public function test_a_crop_is_cut_around_the_focal_point(): void
    {
        $path = $this->redBlue();

        // A square from a 2:1 picture: as big as the height allows, never enlarged.
        $this->assertSame('600x600-50-50', Crops::spec($path, 'square'));

        $this->focus($path, 0, 50);
        $left = Crops::url($path, 'square');
        $this->assertStringContainsString('/storage/cropped/square/600x600-0-50/blog/red-blue', $left);
        $this->assertSame('red', $this->middleOf($left));

        // Moving the dot gives a new address (so browsers don't keep the old crop) and a new crop.
        $this->focus($path, 100, 50);
        $right = Crops::url($path, 'square');
        $this->assertNotSame($left, $right);
        $this->assertSame('blue', $this->middleOf($right));

        // A wide shape fits the width and loses height instead.
        $this->assertSame('1200x513-100-50', Crops::spec($path, 'hero'));
        $this->assertStringContainsString('600w', Crops::srcset($path, 'hero'));
    }

    public function test_only_addresses_the_site_hands_out_are_cut(): void
    {
        $path = $this->redBlue();

        $this->get('/storage/cropped/square/333x333-50-50/'.$path.'.webp')->assertRedirect(); // not a size it offers: the original
        $this->get('/storage/cropped/square/600x600-10-10/'.$path.'.webp')->assertRedirect(); // not where the dot is
        $this->get('/storage/cropped/poster/600x600-50-50/'.$path.'.webp')->assertRedirect(); // no such size
        $this->get('/storage/cropped/square/600x600-50-50/nope.jpg.webp')->assertNotFound();
        $this->assertSame([], Storage::disk('public')->allFiles(Crops::FOLDER));

        // Thrown away with the picture.
        $this->get(parse_url(Crops::url($path, 'square'), PHP_URL_PATH))->assertOk();
        $this->assertNotEmpty(Storage::disk('public')->allFiles(Crops::FOLDER));
        Pictures::forget($path);
        $this->assertSame([], Storage::disk('public')->allFiles(Crops::FOLDER));
    }

    public function test_the_site_uses_them(): void
    {
        $path = $this->redBlue();
        Booklet::entries()->findBySlug('posts', 'hello-from-booklet')->set('cover', [$path])->save();
        // A newer post takes the big "featured" spot, so Hello is an ordinary card.
        Booklet::entries()->make(['collection' => 'posts', 'blueprint' => 'post', 'slug' => 'newer', 'published' => true, 'date' => now()], ['title' => 'Newer', 'cover' => [$path]])->save();
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $about->set('blocks', [
            ['id' => 'a', 'type' => 'picture', 'picture' => [$path], 'shape' => 'square'],
            ['id' => 'b', 'type' => 'picture', 'picture' => [$path], 'shape' => ''],
        ])->save();
        Booklet::flush();

        $blog = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('/storage/cropped/card/', $blog); // cards: the card size

        $post = $this->get('/blog/hello-from-booklet')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#og:image" content="[^"]*/storage/cropped/share/#', $post);

        $page = $this->get('/about')->assertOk()->getContent();
        $this->assertStringContainsString('/storage/cropped/square/', $page);
        $this->assertStringContainsString('aspect-ratio: 800 / 800', $page);
        $this->assertStringContainsString('/storage/blog/red-blue', $page); // "as it is"

        $this->assertSame(600, \App\Booklet\Facades\Booklet::entries()->findBySlug('posts', 'hello-from-booklet') ? app(\App\Booklet\Site::class)->crop(Booklet::entries()->findBySlug('posts', 'hello-from-booklet'), 'square')['width'] : null);
    }

    public function test_admins_change_the_sizes_in_media(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);
        Livewire::actingAs($writer)->test(Media::class)->call('openSizes')->assertForbidden();

        $media = Livewire::actingAs($this->admin)->test(Media::class)
            ->assertSee('Picture sizes')
            ->call('openSizes')
            ->assertSee('Booklet’s own sizes')
            ->call('addSize');
        $last = count($media->get('sizeRows')) - 1;
        $media->set("sizeRows.$last.label", 'Square')->call('saveSizes')->assertHasErrors("sizeRows.$last.label") // same name twice
            ->set("sizeRows.$last.label", 'Banner')->set("sizeRows.$last.width", 1600)->set("sizeRows.$last.height", 10)
            ->call('saveSizes')->assertHasErrors("sizeRows.$last.height")
            ->set("sizeRows.$last.height", 400)
            ->call('removeSize', 3) // Tall
            ->call('saveSizes')->assertHasNoErrors();

        $this->assertSame(['card', 'hero', 'square', 'share', 'banner'], array_keys(Crops::sizes()));
        $this->assertSame(['label' => 'Banner', 'width' => 1600, 'height' => 400], Crops::sizes()['banner']);
        $this->assertDatabaseHas('activity_log', ['action' => 'changed the picture sizes']);

        $media->call('resetSizes');
        $this->assertSame(array_keys(Crops::DEFAULTS), array_keys(Crops::sizes()));
    }

    public function test_choosing_a_picture_shows_every_size(): void
    {
        $path = $this->redBlue();

        Livewire::actingAs($this->admin)->test(Media::class)
            ->call('pick', $path)
            ->assertSee('Card · 4:3')
            ->assertSee('Share · 1.91:1')
            ->assertSee('Copy a size');
    }
}
