<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Stacy\Providers\Provider;
use App\Stacy\StacyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stacy's adapters, with the AI services faked: checks what we send and how
 * we read the answers, for both formats.
 */
class StacyProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected array $tools = [[
        'name' => 'list_drafts',
        'description' => 'List drafts',
        'parameters' => ['type' => 'object', 'properties' => []],
    ]];

    public function test_anthropic_tool_round_trip(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['content' => [
                ['type' => 'text', 'text' => 'Let me look.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_drafts', 'input' => []],
            ], 'stop_reason' => 'tool_use'])
            ->push(['content' => [['type' => 'text', 'text' => 'You have one draft.']], 'stop_reason' => 'end_turn']);

        $config = AiProvider::create(['service' => 'anthropic', 'enabled' => true, 'api_key' => 'sk-test', 'default_model' => 'claude-sonnet-5-5']);
        $provider = Provider::for($config, 'claude-sonnet-5-5');

        $conversation = $provider->begin('You are Stacy.', [['role' => 'user', 'text' => 'Drafts?']]);
        $first = $provider->send($conversation, $this->tools);

        $this->assertTrue($first->wantsTools());
        $this->assertSame('list_drafts', $first->toolCalls[0]->name);

        $provider->addToolResults($conversation, [['call' => $first->toolCalls[0], 'content' => '{"drafts":[]}']]);
        $second = $provider->send($conversation, $this->tools);

        $this->assertSame('You have one draft.', $second->text);
        $this->assertFalse($second->wantsTools());

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body());

            return $request->hasHeader('x-api-key', 'sk-test')
                && $body->system === 'You are Stacy.'
                && isset($body->tools[0]->input_schema)
                // the echoed empty tool input must stay a JSON object
                && (! isset($body->messages[1]) || $body->messages[1]->content[1]->input == new \stdClass);
        });
    }

    public function test_openai_compatible_tool_round_trip_keeps_tool_calls_as_sent(): void
    {
        $toolCall = [
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'list_drafts', 'arguments' => '{}'],
            'extra_content' => ['google' => ['thought_signature' => 'sig-123']],
        ];

        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'No drafts right now.']]]]);

        $config = AiProvider::create(['service' => 'gemini', 'enabled' => true, 'api_key' => 'g-key', 'default_model' => 'some-gemini-model']);
        $provider = Provider::for($config, 'some-gemini-model');

        $conversation = $provider->begin('You are Stacy.', [['role' => 'user', 'text' => 'Drafts?']]);
        $first = $provider->send($conversation, $this->tools);
        $provider->addToolResults($conversation, [['call' => $first->toolCalls[0], 'content' => '{"drafts":[]}']]);
        $second = $provider->send($conversation, $this->tools);

        $this->assertSame('No drafts right now.', $second->text);

        $requests = Http::recorded();
        $secondBody = json_decode($requests[1][0]->body(), true);

        $this->assertSame('system', $secondBody['messages'][0]['role']);
        $this->assertSame('sig-123', $secondBody['messages'][2]['tool_calls'][0]['extra_content']['google']['thought_signature']);
        $this->assertSame('tool', $secondBody['messages'][3]['role']);
        $this->assertSame('call_1', $secondBody['messages'][3]['tool_call_id']);
        $this->assertTrue($requests[0][0]->hasHeader('Authorization', 'Bearer g-key'));
    }

    public function test_a_rejected_key_becomes_a_friendly_message(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

        $config = AiProvider::create(['service' => 'groq', 'enabled' => true, 'api_key' => 'bad', 'default_model' => 'any']);

        $this->expectException(StacyException::class);
        $this->expectExceptionMessage('turned the API key down');

        Provider::for($config, 'any')->listModels();
    }

    public function test_answers_have_a_length_limit_so_openrouter_does_not_reserve_16k(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello']]]])]);
        $config = AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'api_key' => 'k', 'default_model' => 'deepseek/deepseek-chat']);

        $conversation = [['role' => 'user', 'content' => 'hi']];
        Provider::for($config, 'deepseek/deepseek-chat')->send($conversation, $this->tools);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openrouter.ai')
            && $r['max_tokens'] === \App\Stacy\Providers\OpenAiCompatibleProvider::MAX_ANSWER);
    }

    public function test_running_out_of_credit_is_explained(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'This request requires more credits, or fewer max_tokens.', 'code' => 402]], 402)]);
        $config = AiProvider::create(['service' => 'openrouter', 'enabled' => true, 'api_key' => 'k', 'default_model' => 'deepseek/deepseek-chat']);

        $this->expectException(StacyException::class);
        $this->expectExceptionMessage('out of credit');

        $conversation = [['role' => 'user', 'content' => 'hi']];
        Provider::for($config, 'deepseek/deepseek-chat')->send($conversation, $this->tools);
    }

    public function test_keys_are_encrypted_at_rest(): void
    {
        AiProvider::create(['service' => 'openai', 'api_key' => 'sk-secret-value']);

        $raw = \DB::table('ai_providers')->where('service', 'openai')->value('api_key');

        $this->assertNotSame('sk-secret-value', $raw);
        $this->assertSame('sk-secret-value', AiProvider::where('service', 'openai')->first()->api_key);
    }
}
