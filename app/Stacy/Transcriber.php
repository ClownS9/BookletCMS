<?php

namespace App\Stacy;

use App\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Turns a voice note into text through a service that offers transcription
 * (OpenRouter, OpenAI or Groq). Used when the browser can't recognise speech itself.
 */
class Transcriber
{
    public static function provider(): ?AiProvider
    {
        return AiProvider::where('enabled', true)->get()
            ->filter(fn (AiProvider $p) => $p->isReady() && ! empty($p->info()['transcribe_model']))
            ->sortByDesc(fn (AiProvider $p) => $p->is_default)
            ->first();
    }

    public static function available(): bool
    {
        return static::provider() !== null;
    }

    public static function transcribe(UploadedFile $audio, ?string $language = null): string
    {
        $provider = static::provider()
            ?? throw new StacyException('Voice notes need OpenRouter, OpenAI or Groq switched on in Stacy’s brain.');

        try {
            $response = Http::baseUrl($provider->baseUrl())
                ->withToken((string) $provider->api_key)
                ->timeout(60)
                ->attach('file', fopen($audio->getRealPath(), 'r'), 'voice.'.($audio->getClientOriginalExtension() ?: 'webm'))
                ->post('/audio/transcriptions', array_filter([
                    'model' => $provider->info()['transcribe_model'],
                    'language' => $language ? substr($language, 0, 2) : null,
                    'response_format' => 'json',
                ]));
        } catch (ConnectionException) {
            throw new StacyException("I couldn’t reach {$provider->name()} to hear you.");
        }

        if (! $response->successful()) {
            throw new StacyException("{$provider->name()} couldn’t make out the recording. Try again a little closer to the mic.");
        }

        return trim((string) $response->json('text', ''));
    }
}
