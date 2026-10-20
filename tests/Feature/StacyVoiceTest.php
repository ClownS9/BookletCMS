<?php

namespace Tests\Feature;

use App\Livewire\Admin\StacyOrb;
use App\Models\AiProvider;
use App\Models\User;
use App\Stacy\Chat;
use App\Stacy\StacyException;
use App\Stacy\Transcriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StacyVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_spoken_text_drops_markdown(): void
    {
        $this->assertSame(
            'You have two drafts: Opening hours and Summer menu.',
            Chat::speakable("You have **two drafts**:\n[Opening hours](/admin/x) and _Summer menu_.")
        );
    }

    public function test_voice_notes_need_a_service_that_can_hear(): void
    {
        AiProvider::create(['service' => 'anthropic', 'enabled' => true, 'api_key' => 'sk-test', 'default_model' => 'claude-sonnet-5-5']);

        $this->assertFalse(Transcriber::available());
        $this->expectException(StacyException::class);
        Transcriber::transcribe(UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'));
    }

    public function test_groq_transcribes_a_voice_note(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['text' => ' Show me my drafts. '])]);
        AiProvider::create(['service' => 'groq', 'enabled' => true, 'api_key' => 'gsk-test', 'default_model' => 'llama-3.3-70b-versatile']);

        $text = Transcriber::transcribe(UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'), 'en-GB');

        $this->assertSame('Show me my drafts.', $text);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/audio/transcriptions')
            && $r->hasHeader('Authorization', 'Bearer gsk-test'));
    }

    public function test_the_orb_shows_on_admin_pages_but_not_on_the_stacy_page(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('admin.contents'))->assertSeeLivewire(StacyOrb::class);
        $this->actingAs($user)->get(route('admin.stacy'))->assertDontSeeLivewire(StacyOrb::class);
    }

    public function test_voice_preferences_reach_the_browser(): void
    {
        $user = User::factory()->create(['role' => 'writer', 'preferences' => ['stacy_rate' => 1.3, 'stacy_wake' => true, 'stacy_lang' => 'en-GB']]);

        /** @var StacyOrb $orb */
        $orb = Livewire::actingAs($user)->test(StacyOrb::class)->instance();
        $voice = $orb->voice();

        $this->assertSame(1.3, $voice['rate']);
        $this->assertTrue($voice['wake']);
        $this->assertSame('en-GB', $voice['lang']);
        $this->assertFalse($voice['canRecord']);
    }
}
