<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Backups as BackupsPage;
use App\Models\AiProvider;
use App\Models\Comment;
use App\Models\Redirect;
use App\Models\User;
use App\Support\Backups;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Backups: the whole book in one file, and back again.
 */
class BackupsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'nur@example.com']);
        $this->actingAs($this->admin);
    }

    protected function open(string $name): ZipArchive
    {
        $zip = new ZipArchive;
        $zip->open(Backups::path($name));

        return $zip;
    }

    public function test_a_backup_holds_the_book_but_never_the_ai_keys(): void
    {
        AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'api_key' => 'sk-or-secret', 'default_model' => 'deepseek/deepseek-chat']);
        Storage::disk('public')->put('photos/cake.jpg', 'pretend jpeg');
        (new \App\Booklet\Support\AssetUploader('media'))->record('photos/cake.jpg');

        $made = Backups::create();
        $zip = $this->open($made['name']);

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame(Backups::FORMAT, $manifest['format']);
        $this->assertGreaterThan(0, $manifest['counts']['content']['entries']);

        $this->assertNotFalse($zip->getFromName('content/entries.json'));
        $this->assertSame('pretend jpeg', $zip->getFromName('files/public/photos/cake.jpg'));
        $users = json_decode($zip->getFromName('tables/users.json'), true);
        $this->assertArrayNotHasKey('remember_token', $users[0]);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString('sk-or-secret', (string) $zip->getFromIndex($i));
        }
        $this->assertFalse($zip->getFromName('tables/ai_providers.json'));
    }

    public function test_restoring_brings_the_book_back_and_keeps_a_safety_copy(): void
    {
        $post = Booklet::entries()->whereCollection('posts')->first();
        $made = Backups::create();

        // Then things change…
        Booklet::entries()->delete($post);
        Comment::create(['entry_id' => 'x', 'author_name' => 'Late', 'body' => 'After the backup', 'status' => 'approved']);
        Redirect::create(['from_path' => '/later', 'to_path' => '/']);

        $done = Backups::restore(Backups::path($made['name']));

        Booklet::flush();
        $this->assertNotNull(Booklet::entries()->find($post->id()));
        $this->assertSame(0, Comment::where('author_name', 'Late')->count());
        $this->assertFalse(Redirect::where('from_path', '/later')->exists());
        $this->assertTrue(Backups::list()->contains('name', $done['safety']));
        $this->assertSame('before-restore', Backups::list()->firstWhere('name', $done['safety'])['kind']);
    }

    public function test_people_are_matched_by_email_and_keep_their_bylines(): void
    {
        $hana = User::factory()->create(['role' => 'writer', 'email' => 'hana@example.com', 'name' => 'Hana']);
        $post = Booklet::entries()->whereCollection('posts')->first();
        $post->set('author', $hana->id)->save();
        $made = Backups::create();

        $hana->delete();
        Backups::restore(Backups::path($made['name']));

        $back = User::where('email', 'hana@example.com')->first();
        $this->assertNotNull($back, 'Hana is back');
        Booklet::flush();
        $this->assertSame((string) $back->id, (string) Booklet::entries()->find($post->id())->get('author'));
        $this->assertSame(1, User::where('email', 'nur@example.com')->count(), 'the admin is still one person');
    }

    public function test_the_page_restores_only_after_typing_the_word(): void
    {
        $made = Backups::create();

        Livewire::test(BackupsPage::class)
            ->call('look', $made['name'])
            ->assertSee('Restore from this backup?')
            ->set('confirmWord', 'yes')->call('restore')
            ->assertHasErrors('confirmWord')
            ->set('confirmWord', 'restore')->call('restore')
            ->assertRedirect(route('admin.contents'));
    }

    public function test_a_backup_that_is_gone_goes_back_to_the_list(): void
    {
        Livewire::test(BackupsPage::class)
            ->set('restoring', '2020-01-01-000000-gone-manual.zip')
            ->assertSet('restoring', '')
            ->assertDontSee('Restore from this backup?');
    }

    public function test_only_admins_get_in(): void
    {
        $made = Backups::create();
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->get(route('admin.backups'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.backups.download', $made['name']))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.backups.download', $made['name']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.backups.download', '..%2F..%2F.env.zip'))->assertNotFound();
    }

    public function test_an_upload_that_isnt_a_backup_is_turned_away(): void
    {
        $this->post(route('admin.backups.upload'), ['backup' => UploadedFile::fake()->create('holiday.zip', 10, 'application/zip')])
            ->assertRedirect(route('admin.backups'))
            ->assertSessionHasErrors('backup');
    }

    public function test_nightly_backups_keep_the_newest_seven(): void
    {
        for ($i = 0; $i < 9; $i++) {
            Backups::nightly();
            $this->travel(1)->days();
        }

        $this->assertCount(Backups::KEEP_NIGHTLY, Backups::list()->where('kind', 'nightly'));
    }
}
