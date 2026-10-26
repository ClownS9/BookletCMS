<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Calendar;
use App\Livewire\Admin\EntryEditor;
use App\Models\ActivityLog;
use App\Models\Redirect;
use App\Models\User;
use App\Support\Takedowns;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Posts and pages that come down by themselves on a date: back to being a
 * draft (nothing deleted), with readers sent on if asked.
 */
class TakedownTest extends TestCase
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

    protected function visit(string $url)
    {
        // A fresh request, as in real life (nobody signed in, nothing carried over).
        $this->app['auth']->forgetGuards();
        Booklet::flush();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        request()->attributes->remove('booklet.takedowns.settled');

        return $this->get($url);
    }

    protected function entry(string $collection, string $slug): \App\Booklet\Data\Entry
    {
        Booklet::flush();

        return Booklet::entries()->findBySlug($collection, $slug) ?? $this->fail("no {$slug}");
    }

    public function test_a_page_comes_down_on_its_date_and_readers_are_sent_on(): void
    {
        $about = $this->entry('pages', 'about');
        $editor = Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])
            ->assertSee('Take it down on a date');

        // Not in the past, and only somewhere a link may go.
        $editor->set('takedown', now()->subHour()->format('Y-m-d\TH:i'))->call('save')->assertHasErrors(['takedown']);
        $when = now()->addDays(2)->startOfMinute();
        $editor->set('takedown', $when->format('Y-m-d\TH:i'))->set('takedownTo', 'javascript:alert(1)')->call('save')
            ->assertHasErrors(['takedownTo']);
        $editor->set('takedownTo', '/contact')->call('save')->assertHasNoErrors();

        $about = $this->entry('pages', 'about');
        $this->assertSame($when->format('Y-m-d H:i:s'), $about->get('takedown_at'));
        $this->assertSame('/contact', $about->get('takedown_to'));
        $this->assertSame($when->timestamp, Takedowns::next()?->timestamp);

        // Kept for readers until then (the kept copy too).
        $this->visit('/about')->assertOk()->assertHeader('X-Booklet-Cache', 'miss');
        $this->visit('/about')->assertOk()->assertHeader('X-Booklet-Cache', 'hit');

        // Its time comes: the next visit finds it gone, and its address sends readers on (for now).
        $this->travelTo($when->copy()->addMinute());
        $this->visit('/about')->assertRedirect(url('/contact'))->assertStatus(302);
        $about = $this->entry('pages', 'about');
        $this->assertFalse($about->published());
        $this->assertNull($about->get('takedown_at'));
        $this->assertNotNull($about->get('taken_down_at'));
        $this->assertSame(302, Redirect::where('from_path', '/about')->value('status'));
        $log = ActivityLog::where('action', 'took down')->firstOrFail();
        $this->assertSame('booklet', $log->actor);
        $this->assertSame($about->id(), $log->subject_key);
        $this->assertNull(Takedowns::next());

        // The book says so, and publishing again brings it back (its own page wins over the redirect).
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])
            ->assertSee('Came down by itself on')->call('setStatus', 'published')->call('save')->assertHasNoErrors();
        $this->assertNull($this->entry('pages', 'about')->get('taken_down_at'));
        $this->visit('/about')->assertOk();
    }

    public function test_a_post_comes_down_by_the_scheduler_and_shows_on_the_calendar(): void
    {
        $post = $this->entry('posts', 'hello-from-booklet');
        $when = now()->addDay()->setTime(18, 0);
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->set('takedown', $when->format('Y-m-d\TH:i'))->call('save')->assertHasNoErrors();

        Livewire::actingAs($this->admin)->test(Calendar::class)->set('month', $when->format('Y-m'))
            ->assertSee('↓ '.$post->title())->assertSee('Comes down');

        // Nothing due yet; then the minute comes, without anyone visiting.
        $this->assertSame(0, Takedowns::settle());
        $this->travelTo($when->copy()->addMinute());
        $this->assertSame(1, Takedowns::settle());
        $this->assertFalse($this->entry('posts', 'hello-from-booklet')->published());
        $this->assertSame(0, Redirect::count(), 'nowhere was asked for');
        $this->visit('/blog/hello-from-booklet')->assertNotFound();
    }

    public function test_drafts_writers_and_the_home_page_dont_get_one(): void
    {
        $about = $this->entry('pages', 'about');

        // A draft keeps nothing of it.
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $about->id()])
            ->set('takedown', now()->addDay()->format('Y-m-d\TH:i'))->call('setStatus', 'draft')->call('save')
            ->assertDontSee('Take it down on a date');
        $this->assertNull($this->entry('pages', 'about')->get('takedown_at'));

        // The home page can't come down by itself.
        $home = $this->entry('pages', 'contact');
        $home->setAttribute('published', true)->save();
        Booklet::globals('site')->set('homepage', $home->id())->save();
        $this->app->forgetInstance(\App\Booklet\Site::class);
        Livewire::actingAs($this->admin)->test(EntryEditor::class, ['collection' => 'pages', 'entry' => $home->id()])
            ->assertDontSee('Take it down on a date')
            ->set('takedown', now()->addDay()->format('Y-m-d\TH:i'))->call('save')->assertHasErrors(['takedown']);

        // A writer's save leaves an editor's date as it was.
        $post = $this->entry('posts', 'hello-from-booklet');
        $writer = User::factory()->create(['role' => 'author']);
        $post->set('author', $writer->id)->set('takedown_at', now()->addDays(3)->format('Y-m-d H:i:s'))->save();
        Livewire::actingAs($writer)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $post->id()])
            ->assertDontSee('Take it down on a date');
        $this->assertNotNull($this->entry('posts', 'hello-from-booklet')->get('takedown_at'));
    }
}
