<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Booklet\Support\PageTree;
use App\Models\Comment;
use App\Models\User;
use App\Stacy\Commands;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stacy's newer abilities: check-ups, briefings, tags, SEO text, alt text,
 * replies, richer drafts and slash shortcuts.
 */
class StacyMoreToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function book(string $role = 'admin'): User
    {
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);

        return User::factory()->create(['role' => $role, 'name' => 'Nur']);
    }

    protected function tool(Tools $tools, string $name, array $args = []): array
    {
        return json_decode($tools->run(new ToolCall('t1', $name, $args)), true);
    }

    public function test_check_up_and_brief(): void
    {
        $tools = new Tools($this->book());

        $check = $this->tool($tools, 'check_book');
        $this->assertArrayHasKey('published_without_meta_description', $check);
        $this->assertSame(1, $check['comments_waiting']);

        $brief = $this->tool($tools, 'daily_brief');
        $this->assertArrayHasKey('visits_today', $brief);
        $this->assertSame(1, $brief['comments_waiting']);
    }

    public function test_tags_and_a_new_tag_after_a_yes(): void
    {
        $user = $this->book();
        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $tools = new Tools($user);

        $result = $this->tool($tools, 'propose_tags', ['entry_id' => $post->id(), 'category' => 'Notes', 'tags' => ['Welcome', '#Getting started']]);
        $this->assertSame('waiting_for_confirmation', $result['status']);
        $this->assertStringContainsString('new: Getting started', $result['summary']);

        Tools::confirm($tools->pending, $user);
        Booklet::flush();
        $post = Booklet::entries()->find($post->id());
        $this->assertEqualsCanonicalizing(['welcome', 'getting-started'], $post->get('tags'));
        $this->assertSame(['notes'], $post->get('categories'));
        $this->assertNotNull(Booklet::terms()->findBySlug('tags', 'getting-started'));
    }

    public function test_seo_text_after_a_yes(): void
    {
        $user = $this->book();
        $page = Booklet::entries()->findBySlug('pages', 'about');
        $tools = new Tools($user);

        $this->tool($tools, 'propose_edit', ['entry_id' => $page->id(), 'meta_description' => 'Who writes here and why.']);
        Tools::confirm($tools->pending, $user);

        Booklet::flush();
        $this->assertSame('Who writes here and why.', Booklet::entries()->find($page->id())->get('meta_description'));
    }

    public function test_reply_to_a_reader_approves_their_note(): void
    {
        $user = $this->book();
        $comment = Comment::first();
        $tools = new Tools($user);

        $result = $this->tool($tools, 'propose_reply', ['comment_id' => $comment->id, 'text' => 'Thank you, lovely to hear!']);
        $this->assertSame('waiting_for_confirmation', $result['status']);
        $this->assertSame(0, $comment->replies()->count());

        Tools::confirm($tools->pending, $user);
        $this->assertSame('Thank you, lovely to hear!', $comment->replies()->first()->body);
        $this->assertSame('approved', $comment->fresh()->status);
    }

    public function test_writers_cannot_reply(): void
    {
        $writer = $this->book('writer');
        $result = $this->tool(new Tools($writer), 'propose_reply', ['comment_id' => Comment::first()->id, 'text' => 'Hi']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_a_page_draft_goes_under_its_parent_with_a_standfirst(): void
    {
        $user = $this->book();
        $about = Booklet::entries()->findBySlug('pages', 'about');
        $tools = new Tools($user);

        $this->tool($tools, 'propose_draft', ['collection' => 'pages', 'title' => 'Our team', 'standfirst' => 'The people behind it.', 'parent_id' => $about->id()]);
        $reply = Tools::confirm($tools->pending, $user);

        Booklet::flush();
        $page = Booklet::entries()->findBySlug('pages', 'our-team');
        $this->assertNotNull($page);
        $this->assertSame('The people behind it.', $page->get('excerpt'));
        $this->assertSame($about->id(), PageTree::for('pages')->parentOf($page->id()));
        $this->assertStringContainsString('Done', $reply['text']);
    }

    public function test_slash_shortcuts(): void
    {
        $this->assertSame('Brief me on today.', Commands::expand('/brief'));
        $this->assertStringContainsString('spring recipes', Commands::expand('/draft spring recipes'));
        $this->assertStringContainsString('for this', Commands::expand('/seo', ['entry_id' => 'x']));
        $this->assertSame('hello there', Commands::expand('hello there'));
        $this->assertSame('/nope', Commands::expand('/nope'));
    }
}
