<?php

namespace App\Stacy;

use App\Booklet\Facades\Booklet;
use App\Models\AiProvider;
use App\Models\User;
use App\Stacy\Providers\Provider;

/**
 * Stacy: works out which AI service and model a person uses, runs the
 * conversation (including tool use), and returns her answer.
 */
class Stacy
{
    // Memory and steps per question are set in Settings › How Stacy behaves (StacyConfig).

    public function __construct(protected User $user) {}

    /**
     * The service and model this person talks to: their own choice if it's
     * still available, otherwise the admin's default, otherwise any ready one.
     *
     * @return array{0: AiProvider, 1: string}|null
     */
    public static function brainFor(User $user): ?array
    {
        $ready = AiProvider::where('enabled', true)->get()->filter->isReady();

        if ($ready->isEmpty()) {
            return null;
        }

        $preferences = (array) ($user->preferences ?? []);
        $provider = $ready->firstWhere('service', $preferences['stacy_service'] ?? null)
            ?? $ready->firstWhere('is_default', true)
            ?? $ready->first();

        $model = ($preferences['stacy_service'] ?? null) === $provider->service && ! empty($preferences['stacy_model'])
            ? $preferences['stacy_model']
            : ($provider->default_model ?: ($provider->modelList()[0] ?? null));

        return $model ? [$provider, (string) $model] : null;
    }

    /**
     * @param  array<int, array{role: string, text: string}>  $history  earlier turns, oldest first
     * @return array{text: string, links: array, pending: ?array, navigate: ?array, model: ?string}
     */
    public function answer(array $history, string $message, array $context = []): array
    {
        $brain = static::brainFor($this->user);

        if (! $brain) {
            throw new StacyException($this->user->isAdmin()
                ? 'I don’t have a brain yet. Turn on an AI service in Settings › Stacy’s brain.'
                : 'I don’t have a brain yet. Ask an admin to turn on an AI service in Settings.');
        }

        [$config, $model] = $brain;
        $provider = Provider::for($config, $model);
        $tools = new Tools($this->user);

        // Keep the latest turns, starting on something the person said
        // (Anthropic refuses a conversation that opens with the assistant).
        $history = array_slice($history, -max(2, min(60, (int) StacyConfig::get('memory'))));
        while ($history !== [] && $history[0]['role'] !== 'user') {
            array_shift($history);
        }
        $history[] = ['role' => 'user', 'text' => $message];
        $conversation = $provider->begin($this->systemPrompt($context), $history);

        $text = '';
        $finished = false;

        $steps = max(1, min(12, (int) StacyConfig::get('steps')));
        for ($round = 0; $round < $steps; $round++) {
            $reply = $provider->send($conversation, $tools->definitions());
            $text = $reply->text !== '' ? $reply->text : $text;

            if (! $reply->wantsTools()) {
                $finished = true;
                break;
            }

            $results = [];
            foreach ($reply->toolCalls as $call) {
                $results[] = ['call' => $call, 'content' => $tools->run($call)];
            }
            $provider->addToolResults($conversation, $results);
        }

        if ($tools->pending && (! $finished || $text === '')) {
            $text = $tools->pending['summary'].' Shall I go ahead?';
        } elseif (! $finished) {
            // She was still looking things up when we stopped her.
            $text = $tools->links
                ? 'Here’s what I found so far.'
                : 'I went round in circles on that one. Could you ask it a little differently?';
        } elseif ($text === '') {
            $text = $tools->links ? 'Here’s what I found.' : 'I couldn’t find anything for that.';
        }

        return [
            'text' => $text,
            'links' => collect($tools->links)->unique('href')->take(8)->values()->all(),
            'pending' => $tools->pending,
            'navigate' => $tools->pending ? null : $tools->navigate,
            'model' => StacyConfig::get('show_model') ? $config->name().' · '.$model : null,
        ];
    }

    protected function systemPrompt(array $context = []): string
    {
        $here = $context['where'] ?? 'the Ask Stacy page';
        $voice = ! empty($context['spoken'])
            ? "\n        This question was spoken aloud and your answer will be read aloud in a natural voice. Talk the way a friendly person talks: contractions (it's, you've, I'll), short sentences, a light, warm touch, no lists or symbols. Say dates and numbers the way you'd say them (\"next Friday at nine\", \"about a hundred\"). Don't read out ids or addresses."
            : '';
        $entryNote = isset($context['entry_id'])
            ? " They have the entry “{$context['entry_title']}” open (id {$context['entry_id']}, {$context['entry_status']}); “this”, “it” or “this post” means that entry unless they say otherwise."
            : '';

        $site = Booklet::globals('site')?->get('site_name') ?? config('app.name');
        $collections = Booklet::collections()->all()->map(fn ($c) => "{$c->handle()} ({$c->title()})")->implode(', ');
        $now = now()->format('l j F Y, H:i').' ('.config('app.timezone').')';
        $style = StacyConfig::promptLines();
        $inserts = rescue(fn () => app(\App\Inserts\InsertManager::class)->stacyNote(), '', false);
        $length = (int) StacyConfig::get('max_tokens') >= 3000
            ? 'Keep chat answers short (one to three sentences); write at length only when asked to draft or write something.'
            : 'Keep answers short: one to three sentences of plain text.';

        $card = $this->user->card();
        $roleLine = $card
            ? $card->name.' (a custom role that acts like '.($card->base === 'editor' ? 'an editor' : 'a writer').': '.$card->summary().')'
            : $this->user->level();

        return <<<PROMPT
        You are Stacy, the helper inside Booklet, a content management system for the site "{$site}". The admin is designed like an open book: chapters are reached with sticker flags.

        You are talking with {$this->user->name}, whose role is {$roleLine}. Admins can do everything; editors can publish anyone's entries; writers can only write and edit their own drafts.
        It is now {$now}. Collections in this book: {$collections}.
        Right now they are on {$here}.{$entryNote}{$voice}

        What you can do: find entries and read them; list drafts and what's scheduled; give an overview, visits for the week and recent activity; a daily brief (daily_brief); a check-up of the whole book (check_book); list who's online; list waiting comments; find pictures in Media (including ones missing alt text); list categories and tags; open any part of the admin. With the person's "Yes" you can schedule, publish, unpublish; start a draft (with a standfirst, opening text or outline, tags, and a parent page for pages); change a title, standfirst, or the SEO meta title and description; set a post's category and tags; give a picture alt text; approve, spam or delete a comment; reply to a reader as the person; and make a translated copy of an entry as a new draft (propose_translation). In the editor, people can also pick words and press the Stacy button (or Ctrl/⌘ J) to have them tightened, simplified, warmed up, fixed or continued; mention that when they ask for help with wording in a post.{$inserts}
        When writing for them (titles, standfirsts, meta descriptions, replies, outlines), read the entry first, match its tone, and keep it short and specific. Offer one good suggestion rather than a list, unless they ask for options.

        How to help:
        - Use the tools to look things up rather than guessing. Never invent entries, ids, numbers or people.
        - Anything that changes the book (scheduling, publishing, starting a draft) must go through a propose_ tool. Changes happen only when the person presses the button under your message, so say what will happen and ask them to confirm. Never claim a change is done.
        - Links you find appear under your message automatically. Never write links, URLs or Markdown like [text](link) yourself.
        - When the person asks to go somewhere ("take me to pages", "open media"), call go_to with open=true; the page opens by itself, so reply with just a few words like "Taking you to Pages."
        - If something is outside what your tools can do, say so plainly and offer the right chapter with go_to (open=false).
        - When the person says yes to something you proposed, the button under your message does it; if they say yes in words, the change is made for them, so don't propose it again.
        - Ids come from tool results only. If several entries match, ask which one.
        - Sound like a person, not a system: contractions, no corporate phrases ("I'd be happy to assist", "Certainly!", "As an AI"). {$length} No Markdown (no asterisks, headings, lists or tables) and no emoji.
        {$style}
        PROMPT;
    }
}
