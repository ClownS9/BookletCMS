<?php

namespace App\Stacy\Providers;

use App\Stacy\Reply;
use App\Stacy\ToolCall;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Claude, through Anthropic's Messages API.
 */
class AnthropicProvider extends Provider
{
    protected string $system = '';

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->config->baseUrl())
            ->withHeaders([
                'x-api-key' => (string) $this->config->api_key,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->timeout(90);
    }

    public function begin(string $system, array $history): array
    {
        $this->system = $system;
        $messages = [];

        foreach ($history as $turn) {
            $role = $turn['role'] === 'assistant' ? 'assistant' : 'user';
            $last = array_key_last($messages);

            // Anthropic wants turns to alternate; merge any repeats.
            if ($last !== null && $messages[$last]['role'] === $role && is_string($messages[$last]['content'])) {
                $messages[$last]['content'] .= "\n\n".$turn['text'];
            } else {
                $messages[] = ['role' => $role, 'content' => $turn['text']];
            }
        }

        return $messages;
    }

    public function send(array &$conversation, array $tools): Reply
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens ?? \App\Stacy\Providers\OpenAiCompatibleProvider::maxAnswer(),
            'system' => $this->system,
            'messages' => $conversation,
        ];

        // Creativity, when the admin picked one in Settings › How Stacy behaves.
        $temperature = \App\Stacy\StacyConfig::get('temperature');
        if ($temperature !== null && $temperature !== '') {
            $payload['temperature'] = (float) $temperature;
        }

        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], $tools);
        }

        $data = $this->call(fn () => $this->http()->post('/messages', $payload))->json();
        $content = (array) ($data['content'] ?? []);

        // JSON objects come back as PHP arrays; an empty tool input must go
        // back to Anthropic as {} rather than [].
        $echo = array_map(function ($block) {
            if (($block['type'] ?? null) === 'tool_use' && empty($block['input'])) {
                $block['input'] = (object) [];
            }

            return $block;
        }, $content);

        $conversation[] = ['role' => 'assistant', 'content' => $echo];

        $text = '';
        $calls = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $calls[] = new ToolCall($block['id'], $block['name'], (array) ($block['input'] ?? []));
            }
        }

        return new Reply(trim($text), $calls);
    }

    public function addToolResults(array &$conversation, array $results): void
    {
        $conversation[] = [
            'role' => 'user',
            'content' => array_map(fn ($result) => [
                'type' => 'tool_result',
                'tool_use_id' => $result['call']->id,
                'content' => $result['content'],
            ], $results),
        ];
    }

    public function listModels(): array
    {
        $data = $this->call(fn () => $this->http()->get('/models', ['limit' => 100]))->json();

        return collect($data['data'] ?? [])->pluck('id')->filter()->values()->all();
    }
}
