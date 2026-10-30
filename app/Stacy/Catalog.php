<?php

namespace App\Stacy;

use InvalidArgumentException;

/**
 * The AI services Stacy can use. "anthropic" has its own adapter; every
 * other service speaks the OpenAI chat-completions format.
 */
class Catalog
{
    public const SERVICES = [
        'anthropic' => [
            'name' => 'Claude (Anthropic)',
            'kind' => 'anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'needs_key' => true,
            'key_url' => 'https://console.anthropic.com/settings/keys',
            'note' => 'Strong at following instructions and using tools. Pay as you go.',
            'suggested_models' => ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'],
        ],
        'openai' => [
            'name' => 'OpenAI',
            'kind' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'needs_key' => true,
            'key_url' => 'https://platform.openai.com/api-keys',
            'note' => 'Pay as you go.',
            'suggested_models' => [],
            'prefer' => ['gpt-5-mini', 'gpt-5', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini', 'gpt-4o'],
            'transcribe_model' => 'whisper-1',
            'speech_model' => 'gpt-4o-mini-tts',
        ],
        'gemini' => [
            'name' => 'Google Gemini',
            'kind' => 'openai',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'needs_key' => true,
            'key_url' => 'https://aistudio.google.com/apikey',
            'note' => 'Has a free tier with daily limits.',
            'suggested_models' => [],
            'prefer' => ['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.0-flash', 'gemini-2.5-pro'],
        ],
        'groq' => [
            'name' => 'Groq',
            'kind' => 'openai',
            'base_url' => 'https://api.groq.com/openai/v1',
            'needs_key' => true,
            'key_url' => 'https://console.groq.com/keys',
            'note' => 'Very fast open models, with a free tier.',
            'suggested_models' => [],
            'prefer' => ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b', 'moonshotai/kimi-k2-instruct', 'llama-3.1-8b-instant'],
            'transcribe_model' => 'whisper-large-v3-turbo',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'kind' => 'openai',
            'base_url' => 'https://openrouter.ai/api/v1',
            'needs_key' => true,
            'key_url' => 'https://openrouter.ai/keys',
            'note' => 'One key for many models, including some free ones.',
            'suggested_models' => [],
            'transcribe_model' => 'openai/whisper-large-v3',
            'speech_model' => 'openai/gpt-4o-mini-tts-2025-12-15',
        ],
        'ollama' => [
            'name' => 'Ollama (on your computer)',
            'kind' => 'openai',
            'base_url' => 'http://localhost:11434/v1',
            'needs_key' => false,
            'key_url' => 'https://ollama.com/download',
            'note' => 'Free and private. Runs models on your own machine; pick one that supports tools.',
            'suggested_models' => [],
        ],
        'custom' => [
            'name' => 'Another OpenAI-compatible service',
            'kind' => 'openai',
            'base_url' => '',
            'needs_key' => false,
            'key_url' => null,
            'note' => 'LM Studio, vLLM, a company gateway… anything with /chat/completions.',
            'suggested_models' => [],
        ],
    ];

    /** Voices for the natural speech models (OpenAI's set, also served through OpenRouter). */
    public const VOICES = [
        'coral' => 'Coral · warm, bright',
        'nova' => 'Nova · friendly, clear',
        'shimmer' => 'Shimmer · soft, gentle',
        'sage' => 'Sage · calm, thoughtful',
        'alloy' => 'Alloy · even, neutral',
        'ballad' => 'Ballad · expressive',
        'fable' => 'Fable · storyteller',
        'verse' => 'Verse · lively',
        'ash' => 'Ash · low, relaxed',
        'echo' => 'Echo · light, steady',
        'onyx' => 'Onyx · deep',
    ];

    public const SPEAKING_STYLE = 'Speak like a warm, relaxed person who works in a small bookshop and enjoys helping: natural pace, gentle smile in the voice, light pauses between thoughts, never robotic or announcer-like.';

    public static function get(string $service): array
    {
        $one = self::SERVICES[$service] ?? throw new InvalidArgumentException("Unknown AI service [{$service}].");

        return ['name' => __($one['name'])] + $one;
    }

    /**
     * Models that can't chat (embeddings, speech, images…) have no place in Stacy's list.
     */
    public static function canChat(string $model): bool
    {
        return ! preg_match('/embed|tts|whisper|transcribe|dall-e|gpt-image|imagen|veo|image-|audio|realtime|moderation|davinci|babbage|aqa|guard|rerank|search-preview|computer-use|-live/i', $model);
    }

    /**
     * A sensible model to start with: a suggested one, then a preferred family, then the first.
     *
     * @param  string[]  $models
     */
    public static function pickDefault(string $service, array $models): ?string
    {
        $info = static::get($service);

        foreach (array_merge($info['suggested_models'] ?? [], $info['prefer'] ?? []) as $wanted) {
            if (in_array($wanted, $models, true)) {
                return $wanted;
            }
        }

        foreach ($info['prefer'] ?? [] as $wanted) {
            foreach ($models as $model) {
                if (str_starts_with($model, $wanted)) {
                    return $model;
                }
            }
        }

        return $models[0] ?? null;
    }

    public static function names(): array
    {
        return array_map(fn ($s) => __($s['name']), self::SERVICES);
    }
}
