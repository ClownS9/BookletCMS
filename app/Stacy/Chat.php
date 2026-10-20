<?php

namespace App\Stacy;

use App\Models\StacyConversation;
use App\Models\StacyMessage;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stacy conversations, shared by the Ask Stacy page and the floating orb.
 */
class Chat
{
    public static function latest(User $user): ?StacyConversation
    {
        return StacyConversation::where('user_id', $user->id)->latest('updated_at')->first();
    }

    public static function find(User $user, ?int $id): ?StacyConversation
    {
        return $id ? StacyConversation::where('user_id', $user->id)->find($id) : null;
    }

    /**
     * Ask Stacy something; returns her reply (already saved).
     */
    public static function ask(User $user, ?StacyConversation $conversation, string $text, string $via = 'typed', array $context = []): StacyMessage
    {
        // A few tool rounds with a slow service can take a while.
        @set_time_limit(150);

        $conversation ??= StacyConversation::create(['user_id' => $user->id, 'title' => Str::limit($text, 60)]);

        $earlier = $conversation->messages()->get();

        // Words like "yes" or "not now" answer the open proposal directly.
        $open = $earlier->reverse()->first(fn (StacyMessage $m) => $m->role === 'assistant'
            && isset($m->meta['pending']) && ! isset($m->meta['resolved']));

        if ($open && ($answer = static::yesOrNo($text)) !== null) {
            $conversation->messages()->create(['role' => 'user', 'content' => $text, 'meta' => $via === 'voice' ? ['via' => 'voice'] : null]);
            $conversation->touch();

            return ($answer ? static::confirm($user, $open->id) : static::cancel($user, $open->id)) ?? $open;
        }

        // Anything else moves on: an older proposal can't be confirmed later by accident.
        if ($open) {
            $open->update(['meta' => array_merge($open->meta, ['resolved' => 'expired'])]);
        }

        $history = $earlier
            ->reject(fn (StacyMessage $m) => $m->meta['error'] ?? false)
            ->map(fn (StacyMessage $m) => ['role' => $m->role, 'text' => (string) $m->content])
            ->filter(fn ($turn) => trim($turn['text']) !== '')
            ->values()
            ->all();

        $conversation->messages()->create(['role' => 'user', 'content' => $text, 'meta' => $via === 'voice' ? ['via' => 'voice'] : null]);

        // "Take me to Pages": no need to ask the AI, just go.
        if ($place = Tools::placeFor($text)) {
            $tools = new Tools($user);
            $result = $tools->goTo($place);
            $conversation->touch();

            if (! $tools->navigate) {
                // A place this person can't open: say so rather than leading them to a locked door.
                return $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => str($result['error'] ?? 'I can’t take you there.')->before('. Say so')->finish('.')->toString(),
                ]);
            }

            return $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'Taking you to '.$tools->navigate['label'].'.',
                'meta' => ['links' => $tools->links, 'navigate' => $tools->navigate],
            ]);
        }

        // The admin's daily limit per person (Settings › How Stacy behaves).
        $limit = (int) StacyConfig::get('daily_limit');
        if ($limit > 0 && StacyConfig::askedToday($user) > $limit) {
            $conversation->touch();

            return $conversation->messages()->create([
                'role' => 'assistant',
                'content' => "That's your {$limit} questions with me for today. I'll be back tomorrow. (An admin can change the limit in Settings.)",
                'meta' => ['limited' => true],
            ]);
        }

        try {
            $answer = (new Stacy($user))->answer($history, $text, $context + ['spoken' => $via === 'voice']);
            $reply = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => static::tidy($answer['text']),
                'meta' => array_filter([
                    'links' => $answer['links'] ?: null,
                    'pending' => $answer['pending'],
                    'navigate' => $answer['navigate'] ?? null,
                    'model' => $answer['model'],
                ]),
            ]);
        } catch (StacyException $e) {
            $reply = $conversation->messages()->create(['role' => 'assistant', 'content' => $e->getMessage(), 'meta' => ['error' => true]]);
        } catch (Throwable $e) {
            report($e);
            $reply = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'Something went wrong on my side, so I couldn’t answer. It’s in the error log for whoever looks after the site.',
                'meta' => ['error' => true],
            ]);
        }

        $conversation->touch();

        return $reply;
    }

    /**
     * true for a plain yes, false for a plain no, null for anything else.
     */
    public static function yesOrNo(string $text): ?bool
    {
        $text = trim(mb_strtolower($text), " \t\n.!?,");

        if (preg_match('/^(yes|yeah|yep|yup|sure|ok|okay|do it|go ahead|please do|please|confirm|confirmed|yes please|absolutely|correct|ya|boleh)$/u', $text)) {
            return true;
        }

        if (preg_match('/^(no|nope|nah|not now|cancel|don\'?t|stop|never ?mind|leave it|no thanks|tak)$/u', $text)) {
            return false;
        }

        return null;
    }

    public static function confirm(User $user, int $messageId): ?StacyMessage
    {
        $message = static::own($user, $messageId);
        $pending = $message?->meta['pending'] ?? null;

        if (! $pending || isset($message->meta['resolved'])) {
            return null;
        }

        try {
            $result = Tools::confirm($pending, $user);
        } catch (Throwable $e) {
            report($e);
            $result = ['text' => 'That didn’t work, so nothing changed. It’s in the error log for whoever looks after the site.', 'link' => null];
        }

        $message->update(['meta' => array_merge($message->meta, ['resolved' => 'done'])]);

        return $message->conversation->messages()->create([
            'role' => 'assistant',
            'content' => $result['text'],
            'meta' => array_filter(['links' => $result['link'] ? [$result['link']] : null]),
        ]);
    }

    public static function cancel(User $user, int $messageId): ?StacyMessage
    {
        $message = static::own($user, $messageId);

        if (! $message || ! isset($message->meta['pending']) || isset($message->meta['resolved'])) {
            return null;
        }

        $message->update(['meta' => array_merge($message->meta, ['resolved' => 'cancelled'])]);

        return $message->conversation->messages()->create(['role' => 'assistant', 'content' => 'No problem, I’ve left it as it is.']);
    }

    /**
     * "Try again" on a reply that went wrong: forget the failed exchange and
     * hand back the question to ask again.
     */
    public static function retry(User $user, int $messageId): ?string
    {
        $failed = static::own($user, $messageId);

        if (! $failed || ! ($failed->meta['error'] ?? false)) {
            return null;
        }

        $question = $failed->conversation->messages()
            ->where('id', '<', $failed->id)->where('role', 'user')
            ->orderByDesc('id')->first();

        $failed->delete();

        if (! $question) {
            return null;
        }

        $text = (string) $question->content;
        $question->delete();

        return $text;
    }

    /**
     * Earlier conversations, newest first.
     */
    public static function history(User $user, int $limit = 8)
    {
        return StacyConversation::where('user_id', $user->id)
            ->whereHas('messages')
            ->latest('updated_at')
            ->take($limit)
            ->get(['id', 'title', 'updated_at']);
    }

    protected static function own(User $user, int $id): ?StacyMessage
    {
        return StacyMessage::query()
            ->whereKey($id)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
            ->first();
    }

    /**
     * Her words as they appear in the book: plain sentences. Models sometimes
     * add Markdown, made-up links or emoji despite being asked not to, so
     * links become their label, formatting marks go, emoji go.
     */
    public static function tidy(string $text): string
    {
        $text = preg_replace('/!?\[([^\]]+)\]\([^)]*\)/u', '$1', $text);          // [Pages](go_to_pages) → Pages
        $text = preg_replace('/(\*\*|__)(.+?)\1/us', '$2', (string) $text);         // **bold**
        $text = preg_replace('/(?<![\w*])[*_](\S(?:.*?\S)?)[*_](?![\w*])/u', '$1', (string) $text); // *italic*
        $text = preg_replace('/^\s{0,3}(#{1,6}\s+|>\s?|[-*•]\s+)/mu', '', (string) $text);         // headings, quotes, bullets
        $text = preg_replace('/`([^`]*)`/u', '$1', (string) $text);
        $text = preg_replace('/https?:\/\/\S+/u', '', (string) $text);               // bare URLs (links show underneath)
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', (string) $text); // emoji
        $text = preg_replace('/[ \t]+/u', ' ', (string) $text);
        $text = preg_replace('/ +([.,!?])/u', '$1', (string) $text);

        return trim(preg_replace('/\n{3,}/u', "\n\n", (string) $text));
    }

    /**
     * What Stacy should say out loud: her words without Markdown symbols.
     */
    public static function speakable(string $text): string
    {
        $text = static::tidy($text);
        $text = preg_replace('/[*`#>~]+/', '', (string) $text);
        // Read the little separators the way a person would.
        $text = str_replace([' · ', '·', ' / ', '→', '&'], [', ', ', ', ' or ', ' to ', ' and '], (string) $text);

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }
}
