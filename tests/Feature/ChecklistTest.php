<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Contents;
use App\Models\User;
use App\Support\Checklist;
use App\Support\Reviews;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * First steps on Contents: ticked off by doing, not by hand.
 */
class ChecklistTest extends TestCase
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

    protected function done(User $user): array
    {
        return collect(Checklist::for($user))->filter(fn ($i) => $i['done'])->pluck('key')->values()->all();
    }

    public function test_a_new_book_starts_with_everything_to_do(): void
    {
        $this->assertSame([], $this->done($this->admin));
        Livewire::actingAs($this->admin)->test(Contents::class)
            ->assertSee('First steps')->assertSee('0 of 10')->assertSee('Write your About page');
    }

    public function test_things_tick_themselves_off(): void
    {
        $site = Booklet::globals('site');
        $site->merge(['tagline' => 'Notes from a small kitchen', 'site_url' => 'https://kopikuih.test'])->save();

        $about = Booklet::entries()->findBySlug('pages', 'about');
        $about->set('content', 'We bake on weekends.')->save();

        $sample = Booklet::entries()->findBySlug('posts', Checklist::STARTER_POST);
        $sample->setAttribute('published', false)->save();

        Booklet::entries()->make(['collection' => 'posts', 'published' => true, 'date' => now()->subHour()->toDateTimeString()], ['title' => 'Pandan chiffon'])->save();
        User::factory()->create(['role' => 'writer']);
        Booklet::flush();

        $this->assertEqualsCanonicalizing(['tagline', 'address', 'about', 'post', 'sample', 'people'], $this->done($this->admin));
    }

    public function test_writers_get_their_own_list(): void
    {
        $writer = User::factory()->create(['role' => 'writer', 'bio' => 'I bake.']);
        $this->assertSame(['picture', 'bio', 'draft', 'review'], array_column(Checklist::for($writer), 'key'));
        $this->assertSame(['bio'], $this->done($writer));

        $draft = Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Lapis', 'author' => $writer->id])->save();
        Reviews::ask($writer, $draft);
        $this->assertSame(['bio', 'draft', 'review'], $this->done($writer));

        $editor = User::factory()->create(['role' => 'editor']);
        $this->assertSame(['picture', 'bio', 'draft', 'publish'], array_column(Checklist::for($editor), 'key'));
    }

    public function test_it_can_be_put_away_and_brought_back(): void
    {
        Livewire::actingAs($this->admin)->test(Contents::class)->call('hideChecklist')->assertDontSee('First steps');
        $this->assertFalse(Checklist::wanted($this->admin->fresh()));

        Livewire::actingAs($this->admin->fresh())->test(\App\Livewire\Admin\Account::class)->call('togglePreference', 'checklist')->call('saveProfile')->assertHasNoErrors();
        $this->assertTrue(Checklist::wanted($this->admin->fresh()));
    }
}
