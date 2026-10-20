<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\AskStacy;
use App\Models\Comment;
use App\Models\StacyConversation;
use App\Models\User;
use App\Stacy\Chat;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StacyToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function book(string $role = 'admin'): User
    {
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);

        return User::factory()->create(['role' => $role]);
    }

    protected function tool(Tools $tools, string $name, array $args = []): array
    {
        return json_decode($tools->run(new ToolCall('t1', $name, $args)), true);
    }

    public function test_she_can_retitle_an_entry_after_a_yes(): void
    {
        $user = $this->book();
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $tools = new Tools($user);

        $result = $this->tool($tools, 'propose_edit', ['entry_id' => $post->id(), 'title' => 'A warmer hello']);
        $this->assertSame('waiting_for_confirmation', $result['status']);
        $this->assertSame('Hello from Booklet', Booklet::entries()->find($post->id())->title());

        Tools::confirm($tools->pending, $user);
        $this->assertSame('A warmer hello', Booklet::entries()->find($post->id())->title());
    }

    public function test_writers_cannot_edit_someone_elses_published_post(): void
    {
        $writer = $this->book('writer');
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');

        $result = $this->tool(new Tools($writer), 'propose_edit', ['entry_id' => $post->id(), 'title' => 'Mine now']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_she_lists_and_approves_waiting_comments(): void
    {
        $user = $this->book();
        $tools = new Tools($user);

        $list = $this->tool($tools, 'list_comments');
        $this->assertCount(1, $list['comments']);

        $this->tool($tools, 'propose_comment', ['comment_id' => $list['comments'][0]['id'], 'action' => 'approve']);
        Tools::confirm($tools->pending, $user);

        $this->assertSame('approved', Comment::find($list['comments'][0]['id'])->status);
    }

    public function test_she_can_unpublish_and_read_entries(): void
    {
        $user = $this->book();
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $tools = new Tools($user);

        $read = $this->tool($tools, 'read_entry', ['entry_id' => $post->id()]);
        $this->assertSame('/blog/hello-from-booklet', $read['address']);

        $this->tool($tools, 'propose_unpublish', ['entry_id' => $post->id()]);
        Tools::confirm($tools->pending, $user);
        $this->assertSame('draft', Booklet::entries()->find($post->id())->status());
    }

    public function test_try_again_asks_the_failed_question_once_more(): void
    {
        $user = $this->book();
        $conversation = StacyConversation::create(['user_id' => $user->id, 'title' => 'x']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Show me my drafts']);
        $failed = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Oops', 'meta' => ['error' => true]]);

        $this->assertSame('Show me my drafts', Chat::retry($user, $failed->id));
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_earlier_pages_can_be_opened_and_torn_out(): void
    {
        $user = $this->book();
        $old = StacyConversation::create(['user_id' => $user->id, 'title' => 'Old talk']);
        $old->messages()->create(['role' => 'user', 'content' => 'Hello']);

        Livewire::actingAs($user)->test(AskStacy::class)
            ->assertSee('Old talk')
            ->call('openConversation', $old->id)
            ->assertSet('conversationId', $old->id)
            ->call('deleteConversation', $old->id)
            ->assertSet('conversationId', null);

        $this->assertNull(StacyConversation::find($old->id));
    }
}
