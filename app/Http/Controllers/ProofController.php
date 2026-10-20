<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Proof;
use App\Models\ProofNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proof copies, from the reader's side: the entry as the site will show it
 * (drafts too), with a way to leave notes in the margin. No account needed;
 * the link is the key. Never cached, counted, or shown to search engines.
 */
class ProofController extends Controller
{
    /** Kept private: the secret in the address never goes to other sites, and no copy is kept. */
    protected const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function show(Request $request, string $secret): Response
    {
        $proof = Proof::forSecret($secret);
        $entry = $proof?->entry();
        if (! $proof || ! $entry) {
            return response()->view('site.proof-closed', ['site' => app(\App\Booklet\Site::class)], 404)->withHeaders(self::HEADERS);
        }

        $proof->forceFill(['opened_at' => now(), 'opens' => $proof->opens + 1])->saveQuietly();

        $request->attributes->set('booklet_preview', $entry->status());
        $request->attributes->set('booklet_proof', [
            'secret' => $secret,
            'title' => $entry->title() ?: 'Untitled',
            'by' => $proof->creator?->name,
            'for' => $proof->label,
            'closes' => $proof->expires_at,
            'notes' => $proof->notes_allowed,
            'left' => $proof->notes_allowed ? $proof->notes()->latest()->limit(50)->get(['name', 'quote', 'body', 'created_at', 'resolved_at']) : collect(),
        ]);

        $view = app(SiteController::class)->entry($entry);
        $request->attributes->remove('booklet_entry'); // not a visit

        return response($view)->withHeaders(self::HEADERS);
    }

    public function note(Request $request, string $secret): RedirectResponse
    {
        $proof = Proof::forSecret($secret);
        $entry = $proof?->entry();
        abort_unless($proof && $proof->notes_allowed && $entry, 404);

        // A field people never see: filled in, it was a robot.
        if (filled($request->input('website'))) {
            return redirect()->route('proof.show', $secret)->with('proof_noted', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1000'],
            'quote' => ['nullable', 'string', 'max:300'],
        ], [], ['name' => 'your name', 'body' => 'note']);

        abort_if($proof->notes()->count() >= 200, 429, 'That’s a lot of notes already. Ask whoever sent the link to make a new one.');

        $note = $proof->notes()->create([
            'entry_id' => $proof->entry_id,
            'name' => Str::limit(trim(strip_tags($data['name'])), 60, ''),
            'quote' => filled($data['quote'] ?? null) ? Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($data['quote']))), 300, '') : null,
            'body' => trim(strip_tags($data['body'])),
        ]);

        ActivityLog::record('left a proof note on', 'edits', 'entry', $proof->entry_id, $entry->title(), ['by' => $note->name]);
        $request->session()->put('proof_name', $note->name);

        return redirect()->to(route('proof.show', $secret).'#proof-notes')->with('proof_noted', true);
    }
}
