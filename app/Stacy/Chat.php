<?php

namespace App\Stacy;

use App\Models\StacyConversation;
use App\Models\StacyMessage;
use App\Models\User;
use Illuminate\Support\HtmlString;
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
                'content' => trim($answer['text']), // as she wrote it: drawn with html(), copied and read aloud with tidy()
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
     * Stacy's answer, drawn. She's asked for plain sentences, but models
     * slip into Markdown: rather than lose it (a list run together into one
     * paragraph), paragraphs, lists, a heading line, a quote, **bold**,
     * *italic* and `code` are drawn as such. Links aren't: the places she
     * found show underneath, and one she wrote herself may lead nowhere, so
     * only its words stay (as when she's read aloud). Nothing she writes
     * becomes HTML of its own.
     */
    public static function html(string $text): HtmlString
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/!?\[([^\]]+)\]\([^)]*\)/u', '$1', $text);                       // [Pages](go_to_pages) → Pages
        $text = (string) preg_replace('/https?:\/\/\S+/u', '', $text);                                     // bare URLs
        $text = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text); // emoji
        // Each line, as what it is: ['ul'|'ol', item], ['p', line], ['h', words], ['q', words] or ['gap'].
        $parts = [];
        foreach (explode("\n", trim($text)) as $line) {
            $last = $parts === [] ? null : $parts[array_key_last($parts)];
            if (trim($line) === '') {
                $parts[] = ['gap', ''];
            } elseif (preg_match('/^\s{0,3}([-*•]|\d{1,3}[.)])\s+(.*)$/u', $line, $m)) {
                $parts[] = [ctype_digit($m[1][0]) ? 'ol' : 'ul', $m[2]];
            } elseif ($last !== null && in_array($last[0], ['ul', 'ol'], true) && preg_match('/^\s{2,}\S/u', $line)) {
                $parts[array_key_last($parts)][1] .= ' '.trim($line); // a list item carrying on
            } elseif (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/u', $line, $m)) {
                $parts[] = ['h', $m[1]];
            } elseif (preg_match('/^\s{0,3}>\s?(.*)$/u', $line, $m)) {
                $parts[] = ['q', $m[1]];
            } else {
                $parts[] = ['p', $line];
            }
        }

        // Runs of the same kind together: a list, or a paragraph's lines.
        $out = '';
        $run = [];
        $kind = null;
        foreach ([...$parts, ['end', '']] as [$is, $words]) {
            if ($is !== $kind || in_array($is, ['h', 'q', 'gap', 'end'], true)) {
                $out .= self::drawRun($kind, $run);
                $run = [];
            }
            $kind = $is;
            $run[] = $words;
        }

        return new HtmlString($out);
    }

    /** One run of lines, drawn. */
    protected static function drawRun(?string $kind, array $lines): string
    {
        if ($lines === [] || in_array($kind, [null, 'gap', 'end'], true)) {
            return '';
        }
        $inline = array_map(function (string $words): string {
            $html = \App\PageBuilder\Fields::format(trim((string) preg_replace('/[ \t]+/u', ' ', $words)));
            $html = (string) preg_replace('/`([^`\n]+)`/u', '<code>$1</code>', $html);

            return (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '<strong>$1</strong>', $html);
        }, $lines);

        return match ($kind) {
            'ul', 'ol' => '<'.$kind.'>'.implode('', array_map(fn ($i) => '<li>'.$i.'</li>', $inline)).'</'.$kind.'>',
            'h' => '<p class="chat__heading">'.implode(' ', $inline).'</p>',
            'q' => '<blockquote>'.implode('<br>', $inline).'</blockquote>',
            default => '<p>'.implode('<br>', $inline).'</p>',
        };
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
