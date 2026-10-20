<?php

namespace App\Stacy;

use App\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Stacy's natural voice: her words turned into speech by an AI voice model
 * (OpenRouter or OpenAI, through their /audio/speech endpoint). Audio is
 * kept for a while, so the same words aren't paid for twice.
 */
class Speaker
{
    public const MAX_CHARACTERS = 1500;

    public static function provider(): ?AiProvider
    {
        return AiProvider::where('enabled', true)->get()
            ->filter(fn (AiProvider $p) => $p->isReady() && ! empty($p->info()['speech_model']))
            ->sortByDesc(fn (AiProvider $p) => [$p->service === 'openrouter', $p->is_default])
            ->first();
    }

    public static function available(): bool
    {
        // The admin can switch the natural (paid) voice off in Settings › How Stacy behaves.
        return StacyConfig::get('natural_voice') && static::provider() !== null;
    }

    /**
     * MP3 bytes for the words, in the chosen voice and style.
     */
    public static function speak(string $text, ?string $voice = null, ?string $style = null): string
    {
        $provider = static::provider()
            ?? throw new StacyException('A natural voice needs OpenRouter or OpenAI switched on in Stacy’s brain.');

        $text = trim(mb_substr($text, 0, self::MAX_CHARACTERS));
        $voice = array_key_exists((string) $voice, Catalog::VOICES) ? $voice : 'coral';
        $style = trim((string) $style) !== '' ? mb_substr(trim($style), 0, 400) : Catalog::SPEAKING_STYLE;
        $model = $provider->info()['speech_model'];

        $key = 'stacy-voice/'.sha1(implode('|', [$provider->service, $model, $voice, $style, $text])).'.mp3';
        $disk = \App\Support\Disk::of('local');

        if ($disk->exists($key)) {
            return $disk->get($key);
        }

        try {
            $response = Http::baseUrl($provider->baseUrl())
                ->withToken((string) $provider->api_key)
                ->withHeaders($provider->service === 'openrouter' ? ['HTTP-Referer' => config('app.url'), 'X-Title' => config('app.name')] : [])
                ->accept('audio/mpeg')
                ->timeout(45)
                ->post('/audio/speech', [
                    'model' => $model,
                    'input' => $text,
                    'voice' => $voice,
                    'instructions' => $style,
                    'response_format' => 'mp3',
                ]);
        } catch (ConnectionException) {
            throw new StacyException("I couldn’t reach {$provider->name()} for my voice.");
        }

        if (! $response->successful() || ! str_contains((string) $response->header('Content-Type'), 'audio')) {
            $detail = (string) (data_get($response->json(), 'error.message') ?? '');
            throw new StacyException("{$provider->name()} couldn’t make my voice".($detail !== '' ? ': '.mb_strimwidth($detail, 0, 160, '…') : '.'));
        }

        $audio = $response->body();
        $disk->put($key, $audio);

        return $audio;
    }

    /**
     * Forget voice audio older than a week.
     */
    public static function tidy(): void
    {
        $disk = \App\Support\Disk::of('local');

        foreach ($disk->files('stacy-voice') as $file) {
            if ($disk->lastModified($file) < now()->subWeek()->timestamp) {
                $disk->delete($file);
            }
        }
    }
}
