<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use App\Stacy\Chat;
use App\Stacy\Speaker;
use App\Stacy\Transcriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StacyNaturalVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function openRouter(): void
    {
        AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'is_default' => true, 'api_key' => 'sk-or-test', 'default_model' => 'deepseek/deepseek-chat']);
    }

    public function test_openrouter_gives_stacy_a_natural_voice(): void
    {
        Storage::fake('local');
        $this->openRouter();
        Http::fake(['openrouter.ai/*' => Http::response('ID3-fake-mp3', 200, ['Content-Type' => 'audio/mpeg'])]);

        $user = User::factory()->create(['preferences' => ['stacy_natural_voice' => 'nova']]);

        $this->actingAs($user)
            ->postJson(route('admin.stacy.voice'), ['text' => 'Hello there, it’s Stacy.'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/audio/speech')
            && $r['model'] === 'openai/gpt-4o-mini-tts-2025-12-15'
            && $r['voice'] === 'nova'
            && $r['response_format'] === 'mp3'
            && filled($r['instructions']));

        // The same words again come from the saved clip, not a second paid request.
        $this->actingAs($user)->postJson(route('admin.stacy.voice'), ['text' => 'Hello there, it’s Stacy.'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_without_a_voice_service_the_browser_voice_is_used(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Speaker::available());
        $this->actingAs($user)->postJson(route('admin.stacy.voice'), ['text' => 'Hi'])->assertStatus(422);
    }

    public function test_openrouter_can_also_hear_voice_notes(): void
    {
        $this->openRouter();

        $this->assertSame('openrouter', Transcriber::provider()?->service);
    }

    public function test_spoken_text_reads_separators_naturally(): void
    {
        $this->assertSame('Draft, 17 Oct', Chat::speakable('Draft · 17 Oct'));
    }
}
