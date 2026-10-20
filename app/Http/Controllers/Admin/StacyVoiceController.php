<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Stacy\Catalog;
use App\Stacy\Speaker;
use App\Stacy\StacyException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stacy's natural voice: words in, MP3 out, in the person's chosen voice.
 */
class StacyVoiceController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.Speaker::MAX_CHARACTERS * 2],
            'voice' => ['nullable', Rule::in(array_keys(Catalog::VOICES))],
            'style' => ['nullable', 'string', 'max:400'],
        ]);

        // Switched off by an admin, or Stacy isn't for this person: the browser's voice takes over.
        if (! Speaker::available() || ! \App\Stacy\StacyConfig::canUse($request->user())) {
            return response()->json(['message' => 'The natural voice is switched off.'], 422);
        }

        $preferences = (array) ($request->user()->preferences ?? []);

        try {
            $audio = Speaker::speak(
                $data['text'],
                $data['voice'] ?? ($preferences['stacy_natural_voice'] ?? null),
                $data['style'] ?? ($preferences['stacy_style'] ?? null),
            );
        } catch (StacyException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
