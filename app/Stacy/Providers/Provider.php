<?php

namespace App\Stacy\Providers;

use App\Models\AiProvider;
use App\Stacy\Reply;
use App\Stacy\StacyException;
use App\Stacy\ToolCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Talks to one AI service. Conversations are kept in the service's own
 * format, and the model's replies are appended exactly as received, so
 * anything the service needs echoed back (like reasoning signatures) is kept.
 *
 * Tools are described once, provider-neutrally:
 *   ['name' => 'search_content', 'description' => '…', 'parameters' => JSON schema object]
 */
abstract class Provider
{
    /** Longest answer for this request; null = the admin's setting (Settings › How Stacy behaves). */
    public ?int $maxTokens = null;

    public function __construct(
        protected AiProvider $config,
        protected string $model,
    ) {}

    public static function for(AiProvider $config, string $model): self
    {
        return match ($config->info()['kind']) {
            'anthropic' => new AnthropicProvider($config, $model),
            default => new OpenAiCompatibleProvider($config, $model),
        };
    }

    /**
     * @param  array<int, array{role: string, text: string}>  $history
     */
    abstract public function begin(string $system, array $history): array;

    abstract public function send(array &$conversation, array $tools): Reply;

    /**
     * @param  array<int, array{call: ToolCall, content: string}>  $results
     */
    abstract public function addToolResults(array &$conversation, array $results): void;

    /**
     * @return string[]
     */
    abstract public function listModels(): array;

    /**
     * Run an HTTP call and turn failures into messages people understand.
     */
    protected function call(callable $request): Response
    {
        $name = $this->config->name();

        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new StacyException("I couldn’t reach {$name}. Check the address in Stacy’s settings, or that the service is running.");
        }

        if ($response->successful()) {
            return $response;
        }

        // Services shape errors differently ({error: {message}}, {error: "…"}, or a list of them).
        $json = $response->json();
        $detail = data_get($json, 'error.message') ?? data_get($json, '0.error.message') ?? data_get($json, 'message') ?? data_get($json, 'error');
        $detail = is_string($detail) ? mb_strimwidth(trim($detail), 0, 200, '…') : '';

        if ($response->status() === 400 && preg_match('/tool|function/i', $detail)) {
            throw new StacyException("The model “{$this->model}” on {$name} can’t use tools, and Stacy needs them. Pick a newer model in your account or in Stacy’s settings.");
        }

        throw new StacyException(match (true) {
            in_array($response->status(), [401, 403], true) => "{$name} turned the API key down. An admin can update it in Settings › Stacy’s brain.",
            $response->status() === 404 || ($response->status() === 400 && preg_match('/model/i', $detail)) => "{$name} doesn’t know the model “{$this->model}”. Pick another one in your account.",
            $response->status() === 402 || preg_match('/more credits|insufficient (credits|balance|quota)|afford/i', $detail) === 1
                => "{$name} says the account is out of credit (or nearly). Top it up, or pick a cheaper or free model in Stacy’s settings.",
            $response->status() === 429 => "{$name} says we’re going too fast (or the free allowance is used up). Try again in a little while.",
            $response->status() >= 500 => "{$name} is having trouble right now. Try again in a moment.",
            default => "{$name} couldn’t answer".($detail !== '' ? ": {$detail}" : '.'),
        });
    }
}
