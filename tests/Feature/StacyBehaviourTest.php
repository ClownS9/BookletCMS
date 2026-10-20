<?php

namespace Tests\Feature;

use App\Booklet\Facades\Booklet;
use App\Livewire\Admin\StacyBehaviour;
use App\Models\AiProvider;
use App\Models\User;
use App\Stacy\Chat;
use App\Stacy\Providers\Provider;
use App\Stacy\Speaker;
use App\Stacy\StacyConfig;
use App\Stacy\ToolCall;
use App\Stacy\Tools;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings › How Stacy behaves: every setting reaches Stacy without touching code.
 */
class StacyBehaviourTest extends TestCase
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

    public function test_only_admins_open_the_page_and_saving_works(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->get(route('admin.settings.stacy.behaviour'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.settings.stacy.behaviour'))->assertOk()->assertSee('How Stacy behaves');

        Livewire::test(StacyBehaviour::class)
            ->set('length', 'custom')->set('customTokens', 800)
            ->set('temperature', '0.2')
            ->set('tone', 'brief')
            ->set('language', 'Malay')
            ->set('instructions', 'Use British spelling.')
            ->set('dailyLimit', 25)
            ->call('toggle', 'canPublish')
            ->call('save')
            ->assertHasNoErrors();

        Booklet::flush();
        $this->assertSame(800, StacyConfig::get('max_tokens'));
        $this->assertSame(0.2, StacyConfig::get('temperature'));
        $this->assertFalse(StacyConfig::get('can_publish'));
        $this->assertSame(25, StacyConfig::get('daily_limit'));
        $this->assertStringContainsString('Always answer in Malay', StacyConfig::promptLines());
        $this->assertStringContainsString('British spelling', StacyConfig::promptLines());
    }

    public function test_length_and_creativity_are_sent_to_the_service(): void
    {
        StacyConfig::save(['max_tokens' => 600, 'temperature' => 0.9]);
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]]])]);
        $config = AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'api_key' => 'k', 'default_model' => 'deepseek/deepseek-chat']);

        $conversation = [['role' => 'user', 'content' => 'hi']];
        Provider::for($config, 'deepseek/deepseek-chat')->send($conversation, []);

        Http::assertSent(fn (Request $r) => $r['max_tokens'] === 600 && $r['temperature'] === 0.9);
    }

    public function test_switched_off_changes_are_not_offered_or_done(): void
    {
        StacyConfig::save(['can_publish' => false]);
        $tools = new Tools($this->admin);
        $names = array_column($tools->definitions(), 'name');

        $this->assertNotContains('propose_publish', $names);
        $this->assertContains('propose_edit', $names);
        $this->assertArrayHasKey('error', json_decode($tools->run(new ToolCall('t', 'propose_publish', ['entry_id' => 'x'])), true));

        $post = Booklet::entries()->findBySlug('posts', 'hello-from-booklet');
        $reply = Tools::confirm(['action' => 'publish', 'entry_id' => $post->id()], $this->admin);
        $this->assertStringContainsString('switched off', $reply['text']);

        StacyConfig::save(['can_publish' => true, 'can_change' => false]);
        $this->assertSame([], array_values(array_filter(array_column($tools->definitions(), 'name'), fn ($n) => str_starts_with($n, 'propose_'))));
    }

    public function test_who_can_use_her(): void
    {
        StacyConfig::save(['who' => 'admins']);
        $this->assertTrue(StacyConfig::canUse($this->admin));
        $this->assertFalse(StacyConfig::canUse(User::factory()->create(['role' => 'writer'])));
    }

    public function test_daily_limit(): void
    {
        StacyConfig::save(['daily_limit' => 1]);
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello!']]]])]);
        AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'is_default' => true, 'api_key' => 'k', 'default_model' => 'deepseek/deepseek-chat']);

        $first = Chat::ask($this->admin, null, 'hi');
        $this->assertSame('Hello!', $first->content);

        $second = Chat::ask($this->admin, $first->conversation, 'and again');
        $this->assertTrue($second->meta['limited'] ?? false);
        Http::assertSentCount(1);
    }

    public function test_natural_voice_can_be_switched_off(): void
    {
        AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'api_key' => 'k', 'default_model' => 'x']);
        StacyConfig::save(['natural_voice' => false]);
        $this->assertFalse(Speaker::available());
    }
}
