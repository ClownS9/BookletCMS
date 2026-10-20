<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\Contents;
use App\Livewire\Admin\Entries;
use App\Livewire\Admin\EntryEditor;
use App\Models\EntryNote;
use App\Models\User;
use App\Notifications\ReviewNews;
use App\Support\Reviews;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review before publishing: a writer asks, an editor publishes or sends it back.
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $writer;

    protected User $editor;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        Notification::fake();
        $this->writer = User::factory()->create(['role' => 'writer', 'name' => 'Hana']);
        $this->editor = User::factory()->create(['role' => 'editor', 'name' => 'Nur']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Aisyah']);
    }

    protected function draft(): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(['collection' => 'posts', 'published' => false], ['title' => 'Kuih lapis', 'content' => 'Nine layers.', 'author' => $this->writer->id])->save();
    }

    protected function editorFor(User $user, string $id)
    {
        return Livewire::actingAs($user)->test(EntryEditor::class, ['collection' => 'posts', 'entry' => $id]);
    }

    public function test_a_writer_asks_and_editors_hear_about_it(): void
    {
        $post = $this->draft();

        $this->editorFor($this->writer, $post->id())
            ->assertSee('Ask for review')
            ->set('values.content', 'Nine layers, steamed one at a time.')
            ->set('reviewNote', 'Is the intro too long?')
            ->call('askReview')->assertHasNoErrors()
            ->assertSee('Waiting for an editor')->assertSee('Take it back');

        $fresh = Booklet::entries()->find($post->id());
        $this->assertSame('waiting', Reviews::state($fresh));
        $this->assertFalse($fresh->published(), 'still a draft');
        $this->assertSame('Nine layers, steamed one at a time.', $fresh->get('content'), 'the latest words were saved first');

        Notification::assertSentTo([$this->editor, $this->admin], ReviewNews::class, fn ($n) => $n->kind === 'asked' && $n->note === 'Is the intro too long?');
        Notification::assertNotSentTo($this->writer, ReviewNews::class);

        Livewire::actingAs($this->editor)->test(Contents::class)->assertSee('Waiting for your review')->assertSee('Kuih lapis');
        Livewire::actingAs($this->editor)->test(Entries::class, ['collection' => 'posts'])
            ->assertSeeText('In review')->set('status', 'review')->assertSee('Kuih lapis')->assertDontSee('Hello from Booklet');
    }

    public function test_publishing_ends_the_review_and_tells_the_writer(): void
    {
        $post = $this->draft();
        Reviews::ask($this->writer, $post, '');

        $this->editorFor($this->editor, $post->id())
            ->assertSee('Hana asked for a review')
            ->call('setStatus', 'published')->call('save')->assertHasNoErrors();

        $fresh = Booklet::entries()->find($post->id());
        $this->assertTrue($fresh->published());
        $this->assertNull($fresh->get('review'));
        Notification::assertSentTo($this->writer, ReviewNews::class, fn ($n) => $n->kind === 'published');
    }

    public function test_sending_back_needs_a_note_and_leaves_it_on_the_entry(): void
    {
        $post = $this->draft();
        Reviews::ask($this->writer, $post, '');

        $editor = $this->editorFor($this->editor, $post->id());
        $editor->call('sendBack')->assertHasErrors('backNote');
        $editor->set('backNote', 'Lovely. Add the pandan step, please.')->call('sendBack')->assertHasNoErrors();

        $fresh = Booklet::entries()->find($post->id());
        $this->assertSame('returned', Reviews::state($fresh));
        $this->assertSame('Lovely. Add the pandan step, please.', EntryNote::where('entry_id', $post->id())->value('body'));
        Notification::assertSentTo($this->writer, ReviewNews::class, fn ($n) => $n->kind === 'returned');

        Livewire::actingAs($this->writer)->test(Contents::class)->assertSee('Sent back to you')->assertSee('Add the pandan step');
        $this->editorFor($this->writer, $post->id())->assertSee('Sent back')->assertSee('Ask for review again')
            ->call('askReview');
        $this->assertSame('waiting', Reviews::state(Booklet::entries()->find($post->id())));
    }

    public function test_writers_cant_send_back_or_publish_and_can_take_it_back(): void
    {
        $post = $this->draft();
        Reviews::ask($this->writer, $post, '');

        $this->editorFor($this->writer, $post->id())->call('sendBack')->assertForbidden();

        $this->editorFor($this->writer, $post->id())->call('setStatus', 'published')->call('save');
        $this->assertFalse(Booklet::entries()->find($post->id())->published());

        $this->editorFor($this->writer, $post->id())->call('withdrawReview');
        $this->assertNull(Reviews::state(Booklet::entries()->find($post->id())));
    }

    public function test_nobody_hears_if_they_switched_it_off(): void
    {
        $this->editor->forceFill(['preferences' => ['notify_reviews' => false]])->save();
        Reviews::ask($this->writer, $this->draft(), '');

        Notification::assertNotSentTo($this->editor, ReviewNews::class);
        Notification::assertSentTo($this->admin, ReviewNews::class);
    }

    public function test_the_emails_read_well(): void
    {
        $post = $this->draft();
        $asked = (new ReviewNews('asked', $post, $this->writer, 'Is the intro too long?'))->toMail($this->editor);
        $this->assertSame('Hana asked you to review “Kuih lapis”', $asked->subject);
        $this->assertStringContainsString('Is the intro too long?', implode(' ', $asked->introLines));
        $this->assertSame(route('admin.entries.edit', ['posts', $post->id()]), $asked->actionUrl);

        $back = (new ReviewNews('returned', $post, $this->editor, 'Add the pandan step.'))->toMail($this->writer);
        $this->assertStringContainsString('came back with a note', $back->subject);
    }
}
