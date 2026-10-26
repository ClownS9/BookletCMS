<?php

namespace App\Stacy;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Stacy\Providers\Provider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Stacy as a writing partner: rewrite a passage in place (tighter, simpler,
 * warmer…), keep writing from where you stopped, or translate a whole
 * entry. One straight request to the model, no tools: words in, words out.
 * Nothing changes in the book until the person takes the suggestion.
 */
class Writer
{
    /** Most characters to rewrite at once. */
    public const MAX_PASSAGE = 6000;

    /** Translations go in pieces about this long (a long post is several requests). */
    public const CHUNK = 5000;

    /**
     * key => [label in the menu, what to ask for]
     */
    public const HOW = [
        'tighten' => ['Tighten', 'Make it tighter: cut filler, hedging and repetition, keep every idea and the writer’s voice. Aim for about two thirds of the length.'],
        'simpler' => ['Say it simply', 'Rewrite it in plainer words and shorter sentences, so anyone can follow it. Keep the meaning and the writer’s voice.'],
        'warmer' => ['Make it warmer', 'Make it warmer and friendlier, as if talking to a reader you like. Natural, not gushing; no exclamation marks unless the original has them.'],
        'formal' => ['More formal', 'Make it more formal and polished, without becoming stiff. Keep the meaning.'],
        'fix' => ['Fix spelling & grammar', 'Correct spelling, grammar and punctuation only. Change nothing else: keep the wording, tone, line breaks and Markdown exactly as they are.'],
        'expand' => ['Expand', 'Expand it with a little more detail, an example or a clearer explanation, in the same voice. At most about twice the length. Don’t invent facts, names or numbers.'],
        'continue' => ['Keep writing', 'Write what comes next: one or two paragraphs that follow naturally from the text so far, in the same voice and language. Don’t repeat what’s already written, and don’t invent facts, names or numbers; keep to what the text implies.'],
    ];

    /**
     * Languages offered for translation: code => name (in English, and in itself).
     */
    public const LANGUAGES = [
        'ms' => ['Malay', 'Bahasa Melayu'],
        'en' => ['English', 'English'],
        'id' => ['Indonesian', 'Bahasa Indonesia'],
        'zh' => ['Chinese (Simplified)', '简体中文'],
        'ta' => ['Tamil', 'தமிழ்'],
        'ar' => ['Arabic', 'العربية'],
        'ja' => ['Japanese', '日本語'],
        'ko' => ['Korean', '한국어'],
        'th' => ['Thai', 'ไทย'],
        'tl' => ['Filipino', 'Filipino'],
        'vi' => ['Vietnamese', 'Tiếng Việt'],
        'hi' => ['Hindi', 'हिन्दी'],
        'fr' => ['French', 'Français'],
        'es' => ['Spanish', 'Español'],
        'de' => ['German', 'Deutsch'],
        'it' => ['Italian', 'Italiano'],
        'pt' => ['Portuguese', 'Português'],
        'nl' => ['Dutch', 'Nederlands'],
    ];

    /** Fields carried into a translation (the rest are copied as they are). */
    public const TRANSLATED_FIELDS = ['title', 'excerpt', 'content', 'meta_title', 'meta_description', 'cover_caption'];

    /** Is Stacy on, for this person, with a brain to think with? */
    public static function available(?User $user): bool
    {
        return $user
            && (bool) (Booklet::globals('site')?->get('stacy', true) ?? true)
            && StacyConfig::canUse($user)
            && Stacy::brainFor($user) !== null;
    }

    /**
     * A new version of a passage. $before is the text leading up to it (for
     * context, and for "keep writing"); $title is the entry's title.
     */
    public static function rewrite(User $user, string $how, string $text, string $before = '', string $title = ''): string
    {
        if (! isset(self::HOW[$how])) {
            throw new StacyException('I don’t know that one.');
        }
        $text = mb_substr($text, 0, self::MAX_PASSAGE);
        if ($how !== 'continue' && trim($text) === '') {
            throw new StacyException('Select some words first, and I’ll have a go at them.');
        }
        if ($how === 'continue' && trim($before.$text) === '') {
            throw new StacyException('Write a line or two first, so I know where we’re going.');
        }

        self::slowDown($user);

        $system = self::system('You are Stacy, a careful editor helping someone write for their website. You change only what you are asked to change.');
        $context = trim(mb_substr($before, -2500));
        $prompt = ($title !== '' ? "The piece is called “{$title}”.\n\n" : '')
            .($how === 'continue'
                ? "The text so far:\n<text>\n".trim($context."\n".$text)."\n</text>\n\n"
                : ($context !== '' ? "For context, the text just before the passage (do not rewrite this):\n<before>\n{$context}\n</before>\n\n" : '')
                    ."The passage:\n<passage>\n{$text}\n</passage>\n\n")
            .self::HOW[$how][1]."\n\n"
            .'Write in the same language as the original. Keep any Markdown (links, bold, lists, headings) working. '
            .'Reply with only the '.($how === 'continue' ? 'new paragraphs' : 'new version of the passage')
            .', nothing before or after it: no quotes around it, no notes, no “Here is”.';

        $words = max(1, mb_strlen($context.$text) / 4);
        $out = self::complete($user, $system, $prompt, (int) min(4000, max(300, $words * ($how === 'expand' ? 3 : 2) + 200)));

        return self::clean($out, $text);
    }

    /**
     * The entry's words in another language: [field => translated text].
     *
     * @return array<string, string>
     */
    public static function translateEntry(User $user, Entry $entry, string $language): array
    {
        $name = self::languageName($language);
        $out = [];

        foreach (self::TRANSLATED_FIELDS as $field) {
            $value = $entry->get($field);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $out[$field] = $field === 'content'
                ? self::translateLong($user, $value, $name)
                : self::translate($user, $value, $name, $field);
        }

        return $out;
    }

    /** One short piece (a title, a line), or a chunk of a long one. */
    public static function translate(User $user, string $text, string $language, string $what = 'text'): string
    {
        self::slowDown($user, 120);

        $system = self::system('You are Stacy, a skilled translator for a website. You translate faithfully and naturally, the way a native writer would put it, not word for word.');
        $kind = match ($what) {
            'title' => 'a title',
            'excerpt' => 'a one-line standfirst',
            'meta_title' => 'a search-results title (keep it under 60 characters)',
            'meta_description' => 'a search-results description (keep it under 160 characters)',
            'cover_caption' => 'a picture caption',
            default => 'part of an article in Markdown',
        };
        $prompt = "Translate {$kind} into {$language}.\n\n<text>\n{$text}\n</text>\n\n"
            .'Keep the meaning, tone and Markdown exactly (links, emphasis, lists, headings, line breaks); translate the link text but never the link address. '
            .'Leave names of people, places, brands and code as they are. '
            ."Reply with only the {$language} text, nothing before or after it.";

        return self::clean(self::complete($user, $system, $prompt, (int) min(8000, max(200, mb_strlen($text) / 2 + 200))), $text);
    }

    /** A long body, a few paragraphs at a time. */
    protected static function translateLong(User $user, string $text, string $language): string
    {
        @set_time_limit(600);

        $pieces = [];
        $current = '';
        foreach (preg_split("/(\n{2,})/", $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ($current !== '' && mb_strlen($current.$part) > self::CHUNK && trim($part) !== '') {
                $pieces[] = $current;
                $current = '';
            }
            $current .= $part;
        }
        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return implode("\n\n", array_map(fn ($piece) => self::translate($user, trim($piece), $language), $pieces));
    }

    /**
     * A show-this-field-when rule from plain words, for when Booklet's own
     * reading of them didn't find a field: ['mode', 'field', 'op', 'value'] or null.
     *
     * @param  array<string, array{display: string, type: string, options?: array}>  $fields
     */
    public static function condition(User $user, string $words, string $for, array $fields): ?array
    {
        self::slowDown($user);
        $list = collect($fields)->map(fn ($f, $h) => '- '.$h.' ("'.$f['display'].'", '.$f['type']
            .(! empty($f['options']) ? ', choices: '.collect($f['options'])->map(fn ($l, $k) => $k.'='.$l)->implode(', ') : '').')')->implode("\n");
        $prompt = "A form has these fields:\n{$list}\n\nThe field \"{$for}\" should be shown or hidden depending on one of them. The person said:\n<words>{$words}</words>\n\n"
            .'Reply with only JSON, no other words: {"mode": "if" (show when) or "unless" (hide when), "field": the handle, '
            .'"op": one of on, off (for toggles), is, isnt (for choices: use the choice key), filled, empty, contains, "value": the value or ""}. '
            .'If it doesn’t clearly name one of the fields, reply {"field": null}.';

        $out = self::complete($user, self::system('You turn plain descriptions into form rules, exactly.'), $prompt, 200);
        $json = json_decode((string) preg_replace('/^[^{]*|[^}]*$/s', '', $out), true);
        if (! is_array($json) || ! is_string($json['field'] ?? null) || ! isset($fields[$json['field']])
            || ! array_key_exists((string) ($json['op'] ?? ''), \App\Support\Conditions::OPS)) {
            return null;
        }

        return [
            'mode' => ($json['mode'] ?? 'if') === 'unless' ? 'unless' : 'if',
            'field' => $json['field'],
            'op' => (string) $json['op'],
            'value' => is_scalar($json['value'] ?? '') ? trim((string) ($json['value'] ?? '')) : '',
        ];
    }

    /**
     * The words for one section of a built page, as asked: its text fields
     * (and the things in its lists), in the same shape it's given. Nothing
     * but words: pictures, addresses and choices stay as they are.
     *
     * @param  array<string, array<string, mixed>>  $fields  the section's text fields: name => ['label', 'type' (text|words|items), 'max', 'fields' for a list, 'max_items']
     * @param  array<string, mixed>  $current  what's there now (the same shape)
     * @return array<string, mixed>
     */
    public static function section(User $user, string $kind, string $about, array $fields, array $current, string $ask, string $page = '', string $around = ''): array
    {
        $ask = trim(mb_substr($ask, 0, 1000));
        if ($ask === '') {
            throw new StacyException('Say what it should be about, and I’ll write it.');
        }
        self::slowDown($user, 10);

        $describe = function (array $fields, string $indent = '') use (&$describe): string {
            $out = '';
            foreach ($fields as $name => $f) {
                if (($f['type'] ?? '') === 'items') {
                    $out .= $indent.'- "'.$name.'": a list of up to '.(int) ($f['max_items'] ?? 8).' '.($f['one'] ?? 'item').'s, each an object with:'."\n".$describe((array) ($f['fields'] ?? []), $indent.'    ');
                } else {
                    $out .= $indent.'- "'.$name.'" ('.(string) ($f['label'] ?? $name).'): '.(($f['type'] ?? '') === 'words' ? 'a few sentences (an empty line between paragraphs)' : 'one short line')
                        .', at most '.(int) ($f['max'] ?? (($f['type'] ?? '') === 'words' ? 600 : 120))." characters\n";
                }
            }

            return $out;
        };
        $prompt = ($page !== '' ? "The page is called “{$page}”.\n" : '')
            .($around !== '' ? "What else is on the page, for context (don't repeat it):\n<page>\n".mb_substr($around, 0, 2500)."\n</page>\n\n" : "\n")
            ."Write the words for one section of it: a {$kind} ({$about}).\nIts fields:\n".$describe($fields)
            ."\nWhat's in it now:\n".json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nWhat the person asked for:\n<ask>\n{$ask}\n</ask>\n\n"
            .'Write in the language they asked in. You may use **bold**, *italic* and [a link](/address) in the few-sentences fields only. '
            .'Don’t invent facts, prices, times, names or numbers you weren’t given: where one is needed, write what goes there in [square brackets] for them to fill in. '
            .'Reply with only one JSON object with exactly those field names (and, for a list, the list of objects), nothing before or after it.';

        $out = self::complete($user, self::system('You are Stacy, writing the words for a section of someone’s web page: clear, warm and to the point, in their voice.'), $prompt, 1800);
        $json = json_decode((string) preg_replace('/^[^{]*|[^}]*$/s', '', $out), true);
        if (! is_array($json)) {
            throw new StacyException('I wrote something, but not in a shape I could put on the page. Try again?');
        }

        return $json;
    }

    public static function languageName(string $code): string
    {
        return self::LANGUAGES[$code][0] ?? throw new StacyException('I can’t translate into that language yet.');
    }

    // Underneath --------------------------------------------------------------

    protected static function system(string $role): string
    {
        $style = trim((string) StacyConfig::get('instructions'));

        return $role.($style !== '' ? "\n\nThe site's house style (follow it where it applies to writing):\n".$style : '');
    }

    /** Thirty goes a minute is plenty for a person; more is probably a stuck key. */
    protected static function slowDown(User $user, int $perMinute = 30): void
    {
        if (! RateLimiter::attempt('stacy-writer:'.$user->id, $perMinute, fn () => true, 60)) {
            throw new StacyException('That’s a lot of rewriting in one minute. Give me a moment, then try again.');
        }
    }

    protected static function complete(User $user, string $system, string $prompt, int $maxTokens): string
    {
        $brain = Stacy::brainFor($user)
            ?? throw new StacyException($user->isAdmin()
                ? 'I don’t have a brain yet. Turn on an AI service in Settings › Stacy’s brain.'
                : 'I don’t have a brain yet. Ask an admin to turn on an AI service in Settings.');

        [$config, $model] = $brain;
        $provider = Provider::for($config, $model);
        $provider->maxTokens = $maxTokens;

        $conversation = $provider->begin($system, [['role' => 'user', 'text' => $prompt]]);
        $reply = $provider->send($conversation, []);

        if (trim($reply->text) === '') {
            throw new StacyException('I came back empty-handed that time. Try again?');
        }

        return $reply->text;
    }

    /**
     * Just the words: no code fences, no quotes around it, no "Here is…".
     */
    public static function clean(string $out, string $original = ''): string
    {
        $out = trim($out);
        $out = preg_replace('/^```[a-z]*\s*\n(.*)\n```$/su', '$1', $out);
        $out = preg_replace('/^<(passage|text)>\s*(.*?)\s*<\/\1>$/su', '$2', (string) $out);
        $out = preg_replace('/^(here(’|\')?s|here is|sure[,!.]?|okay[,!.]?)[^\n]*:\s*\n+/iu', '', (string) $out);

        // Quotes around the whole reply, when the original had none.
        foreach (['“' => '”', '"' => '"', '«' => '»', '「' => '」'] as $open => $close) {
            if (Str::startsWith($out, $open) && Str::endsWith($out, $close) && ! Str::startsWith(trim($original), $open) && mb_strlen($out) > 2) {
                $inner = mb_substr($out, 1, -1);
                if (! str_contains($inner, $open)) {
                    $out = $inner;
                }
            }
        }

        return trim((string) $out);
    }
}
