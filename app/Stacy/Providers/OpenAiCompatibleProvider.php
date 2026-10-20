<?php

namespace App\Stacy\Providers;

use App\Stacy\Reply;
use App\Stacy\ToolCall;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Any service that speaks OpenAI's chat-completions format: OpenAI itself,
 * Google Gemini (its OpenAI-compatible endpoint), Groq, OpenRouter, Ollama…
 */
class OpenAiCompatibleProvider extends Provider
{
    protected function http(): PendingRequest
    {
        $request = Http::baseUrl($this->config->baseUrl())->acceptJson()->timeout(90);

        if ($this->config->api_key) {
            $request = $request->withToken((string) $this->config->api_key);
        }

        if ($this->config->service === 'openrouter') {
            $request = $request->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => config('app.name')]);
        }

        return $request;
    }

    public function begin(string $system, array $history): array
    {
        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $turn['text']];
        }

        return $messages;
    }

    /** The default longest answer: room for an outline or a full reply, no more. */
    public const MAX_ANSWER = 1500;

    /** The longest answer to ask for, as set in Settings › How Stacy behaves. */
    public static function maxAnswer(): int
    {
        return max(200, min(16000, (int) (\App\Stacy\StacyConfig::get('max_tokens') ?: self::MAX_ANSWER)));
    }

    public function send(array &$conversation, array $tools): Reply
    {
        $payload = ['model' => $this->model, 'messages' => $conversation];

        // Say how long an answer may be. Without it, services like OpenRouter assume the
        // model's maximum (16,384 for DeepSeek) and refuse when the balance couldn't cover
        // an answer that long, even though Stacy's answers are a few hundred words.
        // OpenAI's own API calls it max_completion_tokens; everyone else max_tokens.
        $payload[$this->config->service === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $this->maxTokens ?? self::maxAnswer();

        // Creativity, when the admin picked one (OpenAI's reasoning models only take their default).
        $temperature = \App\Stacy\StacyConfig::get('temperature');
        if ($temperature !== null && $temperature !== '' && ! ($this->config->service === 'openai' && preg_match('/^(o\d|gpt-5)/', $this->model))) {
            $payload['temperature'] = (float) $temperature;
        }

        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ], $tools);
        }

        $data = $this->call(fn () => $this->http()->post('/chat/completions', $payload))->json();
        $message = (array) data_get($data, 'choices.0.message', []);

        // Echo the reply back with only the standard fields, but keep each
        // tool call exactly as sent: Gemini hides reasoning signatures in
        // them that must come back unchanged.
        $echo = ['role' => 'assistant', 'content' => $message['content'] ?? null];
        if (! empty($message['tool_calls'])) {
            $echo['tool_calls'] = $message['tool_calls'];
        }
        $conversation[] = $echo;

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $arguments = data_get($call, 'function.arguments', '{}');
            $calls[] = new ToolCall(
                (string) ($call['id'] ?? Str::random(12)),
                (string) data_get($call, 'function.name', ''),
                is_array($arguments) ? $arguments : (json_decode((string) $arguments, true) ?: []),
            );
        }

        return new Reply(trim((string) ($message['content'] ?? '')), $calls);
    }

    public function addToolResults(array &$conversation, array $results): void
    {
        foreach ($results as $result) {
            $conversation[] = [
                'role' => 'tool',
                'tool_call_id' => $result['call']->id,
                'name' => $result['call']->name,
                'content' => $result['content'],
            ];
        }
    }

    public function listModels(): array
    {
        $data = $this->call(fn () => $this->http()->get('/models'))->json();

        return collect($data['data'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => Str::after((string) $id, 'models/'))
            ->filter(fn ($id) => \App\Stacy\Catalog::canChat($id))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
