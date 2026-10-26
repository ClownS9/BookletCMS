<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\EntryEditor;
use App\Models\AiProvider;
use App\Models\User;
use App\Stacy\Chat;
use App\Stacy\StacyConfig;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use App\Stacy\Translations;
use App\Stacy\Writer;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stacy at the desk (rewriting picked words, keeping on writing),
 * translating an entry, and the fixes to her tools.
 */
class StacyWritingTest extends TestCase
{
    use RefreshDatabase;

    protected User $nur;

    protected function setUp(): void
    {
        parent::setUp();
        Booklet::flush();
        $this->seed(BookletStarterSeeder::class);
        $this->nur = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->nur);
        AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'is_default' => true, 'api_key' => 'k', 'default_model' => 'deepseek/deepseek-chat']);
    }

    protected function answers(string ...$texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push(['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]]);
        }
        Http::fake(['openrouter.ai/*' => $sequence]);
    }

    protected function draft(): \App\Booklet\Data\Entry
    {
        return Booklet::entries()->make(
            ['collection' => 'posts', 'published' => true, 'date' => now()->subDay()->toDateTimeString()],
            ['title' => 'Spring in the garden', 'excerpt' => 'The fig tree woke up.', 'content' => "The fig tree is out.\n\nThe tulips too.", 'author' => $this->nur->id, 'cover' => ['photos/fig.jpg']],
        )->save();
    }

    public function test_her_answers_keep_their_lists_and_bold_but_nothing_unsafe(): void
    {
        $html = (string) \App\Stacy\Chat::html("Three **drafts** wait, the newest *Spring*:\n- Spring — today\n- Kuih lapis — yesterday\n\n1. One\n2. Two\n\nSee [Pages](go_to_pages) or https://evil.example/x. <script>alert(1)</script> `code` 🎉");

        $this->assertStringContainsString('Three <strong>drafts</strong> wait, the newest <em>Spring</em>:', $html);
        $this->assertStringContainsString('<ul><li>Spring — today</li><li>Kuih lapis — yesterday</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>One</li><li>Two</li></ol>', $html);
        $this->assertStringContainsString('See Pages or', $html, 'a link she wrote keeps only its words');
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringNotContainsString('🎉', $html);
        // Read aloud and copied: still plain.
        $this->assertSame('Three drafts wait', \Illuminate\Support\Str::before(\App\Stacy\Chat::tidy('Three **drafts** wait, x'), ','));
    }

    public function test_rewriting_picked_words_sends_only_them_and_returns_clean_text(): void
    {
        $this->answers("Here's a tighter version:\n\n“The fig tree woke up.”");

        $out = Writer::rewrite($this->nur, 'tighten', 'The fig tree finally, after all this time, woke up.', 'Earlier words.', 'Spring');

        $this->assertSame('The fig tree woke up.', $out, 'no preamble, no quotes round it');
        Http::assertSent(function (Request $request) {
            $prompt = $request['messages'][1]['content'];

            return str_contains($prompt, '<passage>') && str_contains($prompt, 'after all this time')
                && ! isset($request['tools']) && $request['max_tokens'] >= 300;
        });
    }

    public function test_the_editor_hands_back_a_suggestion_or_a_reason(): void
    {
        $this->answers('Shorter words.');
        $entry = $this->draft();

        Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->assertSee('Stacy')
            ->call('stacyWrite', 'simpler', 'Longer words here.', '')
            ->assertReturned(['text' => 'Shorter words.']);

        Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->call('stacyWrite', 'simpler', '   ', '')
            ->assertReturned(['error' => 'Select some words first, and I’ll have a go at them.']);
    }

    public function test_no_brain_means_no_button(): void
    {
        AiProvider::query()->delete();
        $entry = $this->draft();

        Livewire::test(EntryEditor::class, ['collection' => 'posts', 'entry' => $entry->id()])
            ->assertDontSee('format-bar__stacy', false)
            ->assertDontSee('Translate with Stacy');
    }

    public function test_translating_makes_a_linked_draft_and_leaves_the_original_alone(): void
    {
        $entry = $this->draft();
        $this->answers('Musim bunga di taman', 'Pokok ara sudah bangun.', "Pokok ara sudah keluar.\n\nTulip juga.");

        $copy = Translations::make($this->nur, $entry, 'ms');

        $this->assertSame('Musim bunga di taman', $copy->title());
        $this->assertSame("Pokok ara sudah keluar.\n\nTulip juga.", $copy->get('content'));
        $this->assertSame(['photos/fig.jpg'], $copy->get('cover'), 'pictures carried over');
        $this->assertFalse($copy->published());
        $this->assertSame($entry->id(), $copy->get('translation_of'));
        $this->assertSame('ms', $copy->get('lang'));
        $this->assertStringEndsWith('-ms', (string) $copy->slug());
        $this->assertSame('Spring in the garden', Booklet::entries()->find($entry->id())->title());

        $this->assertCount(2, Translations::family($copy));
        $this->expectException(\App\Stacy\StacyException::class);
        Translations::make($this->nur, $copy, 'ms'); // one Malay version is enough
    }

    public function test_stacy_can_propose_a_translation_and_make_it_on_yes(): void
    {
        $entry = $this->draft();
        $tools = new Tools($this->nur);
        $result = json_decode($tools->run(new ToolCall('t', 'propose_translation', ['entry_id' => $entry->id(), 'language' => 'ja'])), true);

        $this->assertSame('waiting_for_confirmation', $result['status']);
        $this->assertSame('translate', $tools->pending['action']);

        $this->answers('庭の春', 'イチジクが目覚めた。', "イチジクが出た。\n\nチューリップも。");
        $done = Tools::confirm($tools->pending, $this->nur);
        $this->assertStringContainsString('Japanese draft is ready', $done['text']);

        StacyConfig::save(['can_draft' => false]);
        $this->assertArrayHasKey('error', json_decode((new Tools($this->nur))->run(new ToolCall('t', 'propose_translation', ['entry_id' => $entry->id(), 'language' => 'fr'])), true));
    }

    public function test_pages_cannot_be_scheduled_so_they_are_not_published_by_accident(): void
    {
        $page = Booklet::entries()->make(['collection' => 'pages', 'published' => false], ['title' => 'Opening hours', 'author' => $this->nur->id])->save();

        $result = json_decode((new Tools($this->nur))->run(new ToolCall('t', 'propose_schedule', ['entry_id' => $page->id(), 'when' => now()->addDay()->toDateTimeString()])), true);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('can’t be scheduled', $result['error']);

        $live = $this->draft();
        $this->assertArrayHasKey('error', json_decode((new Tools($this->nur))->run(new ToolCall('t', 'propose_publish', ['entry_id' => $live->id()])), true), 'already live');
    }

    public function test_places_some_people_cannot_open_are_not_offered_as_a_trip(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);

        $reply = Chat::ask($writer, null, 'take me to backups');
        $this->assertStringContainsString('only for admins', $reply->content);
        $this->assertArrayNotHasKey('navigate', (array) $reply->meta);

        $this->assertSame('print', Tools::placeFor('open print as a book'));
        $this->assertSame('sticker_book', Tools::placeFor('show me my stickers'));
        $reply = Chat::ask($this->nur, null, 'show me my stickers');
        $this->assertStringEndsWith('#stickers', $reply->meta['navigate']['href']);
    }
}
