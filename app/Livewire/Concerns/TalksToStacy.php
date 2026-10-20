<?php

namespace App\Livewire\Concerns;

use App\Booklet\Facades\Booklet;
use App\Models\StacyMessage;
use App\Stacy\Chat;
use App\Stacy\Stacy;
use App\Stacy\StacyException;
use App\Stacy\Transcriber;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;

/**
 * What both the Ask Stacy page and the floating orb can do: ask (typed or
 * spoken), confirm or cancel a proposed change, and hear voice notes.
 * After every reply a browser event "stacy-said" carries the words to speak.
 */
trait TalksToStacy
{
    use WithFileUploads;

    public ?int $conversationId = null;

    public string $draft = '';

    /** Where the person is in the book, so "publish this" makes sense. */
    #[\Livewire\Attributes\Locked]
    public array $context = [];

    /** A recorded voice note, when the browser can't recognise speech. */
    public $voiceNote = null;

    #[Computed]
    public function enabled(): bool
    {
        // On for the book (Settings), and for this person (Settings › How Stacy behaves › Who).
        return (bool) (Booklet::globals('site')?->get('stacy', true) ?? true)
            && \App\Stacy\StacyConfig::canUse(request()->user());
    }

    #[Computed]
    public function brain(): ?array
    {
        return Stacy::brainFor(request()->user());
    }

    #[Computed]
    public function messages()
    {
        return $this->conversationId
            ? StacyMessage::where('conversation_id', $this->conversationId)->orderBy('id')->get()
            : collect();
    }

    /**
     * Voice settings for the browser: speak replies, which voice, how fast,
     * hands-free wake word, language, and whether voice notes can be heard.
     */
    #[Computed]
    public function voice(): array
    {
        $p = (array) (request()->user()->preferences ?? []);

        return [
            'speak' => (bool) ($p['stacy_speak'] ?? true),
            'voice' => (string) ($p['stacy_voice'] ?? ''),
            'rate' => (float) ($p['stacy_rate'] ?? 1.0),
            'wake' => (bool) ($p['stacy_wake'] ?? false),
            'lang' => (string) ($p['stacy_lang'] ?? str_replace('_', '-', config('booklet.sites.'.config('booklet.default_site').'.locale', 'en-US'))),
            'canRecord' => Transcriber::available(),
            'commands' => \App\Stacy\Commands::menu(),
            // A natural AI voice when OpenRouter or OpenAI can make one, otherwise the browser's.
            'natural' => $natural = \App\Stacy\Speaker::available(),
            'engine' => $natural && ($p['stacy_engine'] ?? 'natural') === 'natural' ? 'natural' : 'browser',
            'endpoint' => route('admin.stacy.voice'),
        ];
    }

    public function ask(?string $text = null, string $via = 'typed'): void
    {
        $text = trim($text ?? $this->draft);

        if ($text === '' || ! $this->enabled) {
            return;
        }
        if (mb_strlen($text) > 4000) {
            // Too long to send: say so instead of quietly doing nothing.
            $this->dispatch('stacy-said', id: $this->getId(), text: 'That’s a bit long for me. Could you send it in a shorter piece, under about 4,000 characters?', pending: false);
            $this->dispatch('stamp', text: 'That’s too long for Stacy. Try a shorter piece.');

            return;
        }

        // "/check", "/draft spring recipes"…: shortcuts become plain requests.
        $text = \App\Stacy\Commands::expand($text, $this->context ?? []);

        $conversation = Chat::find(request()->user(), $this->conversationId);
        $reply = Chat::ask(request()->user(), $conversation, $text, $via, $this->context);

        $this->conversationId = $reply->conversation_id;
        $this->draft = '';
        unset($this->messages);

        $this->dispatch('stacy-said',
            id: $this->getId(),
            text: Chat::speakable($reply->content),
            pending: isset($reply->meta['pending']),
            go: $reply->meta['navigate']['href'] ?? null,
        );
    }

    /**
     * A voice note uploaded by the browser: transcribe it, then ask.
     */
    public function hearVoiceNote(): void
    {
        $this->validate(['voiceNote' => ['file', 'max:25600']]);

        try {
            $text = Transcriber::transcribe($this->voiceNote, $this->voice['lang']);
        } catch (StacyException $e) {
            $this->voiceNote = null;
            $this->dispatch('stacy-said', id: $this->getId(), text: $e->getMessage(), pending: false);

            return;
        }

        $this->voiceNote = null;

        if ($text === '') {
            $this->dispatch('stacy-said', id: $this->getId(), text: 'I didn’t catch that. Try again?', pending: false);

            return;
        }

        $this->ask($text, 'voice');
    }

    public function confirm(int $messageId): void
    {
        $reply = Chat::confirm(request()->user(), $messageId);
        unset($this->messages);

        if ($reply) {
            $this->dispatch('stacy-said', id: $this->getId(), text: Chat::speakable($reply->content), pending: false);
        }
    }

    public function cancel(int $messageId): void
    {
        $reply = Chat::cancel(request()->user(), $messageId);
        unset($this->messages);

        if ($reply) {
            $this->dispatch('stacy-said', id: $this->getId(), text: Chat::speakable($reply->content), pending: false);
        }
    }

    /**
     * Spoken "yes" / "no" answers to the latest proposal.
     */
    public function answerLatest(bool $yes): void
    {
        $pending = $this->messages->reverse()->first(
            fn (StacyMessage $m) => isset($m->meta['pending']) && ! isset($m->meta['resolved'])
        );

        if ($pending) {
            $yes ? $this->confirm($pending->id) : $this->cancel($pending->id);
        }
    }

    public function retry(int $messageId): void
    {
        $question = Chat::retry(request()->user(), $messageId);
        unset($this->messages);

        if ($question !== null) {
            $this->ask($question, 'typed');
        }
    }

    public function newPage(): void
    {
        $this->conversationId = null;
        $this->draft = '';
        unset($this->messages);
    }
}
