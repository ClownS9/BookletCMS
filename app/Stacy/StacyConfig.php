<?php

namespace App\Stacy;

use App\Booklet\Facades\Booklet;
use App\Models\User;

/**
 * How Stacy behaves, set by an admin in Settings › How Stacy behaves.
 * Kept in the "stacy" global set (database or files, like the site's own
 * settings), with sensible defaults for anything not set.
 */
class StacyConfig
{
    public const DEFAULTS = [
        // Answers
        'max_tokens' => 1500,       // longest answer, in tokens (about ¾ of a word each)
        'temperature' => null,      // null = the model's own default
        'tone' => 'friendly',       // friendly · professional · brief · playful
        'language' => '',           // '' = reply in the language they write in
        'instructions' => '',       // house style, in plain words

        // Thinking
        'memory' => 20,             // earlier messages she remembers in a conversation
        'steps' => 6,               // look-ups she may take before answering

        // What she may change (always after the person's "Yes")
        'can_change' => true,       // master switch for every change below
        'can_publish' => true,      // publish, schedule, take off the site
        'can_edit' => true,         // titles, standfirsts, SEO text, tags, alt text
        'can_draft' => true,        // start drafts and pages
        'can_comments' => true,     // approve, spam, delete, reply
        'can_inserts' => true,      // changes that inserts teach her (adding an event, say)

        // Who and how much
        'who' => 'everyone',        // everyone · editors · admins
        'daily_limit' => 0,         // questions per person per day; 0 = no limit
        'natural_voice' => true,    // let her use the natural (paid) voice
        'show_model' => true,       // show which model answered, under answers

        // Greetings
        'greet' => true,            // say hello when people sign in or come back
        'greet_sound' => true,      // with a soft chime as she appears
        'greet_aloud' => true,      // and say it out loud (for people who have spoken answers on)
    ];

    public const TONES = [
        'friendly' => ['Friendly', 'Warm and plain-spoken, like a helpful colleague.'],
        'professional' => ['Professional', 'Polite and precise, no jokes.'],
        'brief' => ['Brief', 'As few words as possible. Straight to the point.'],
        'playful' => ['Playful', 'Light and cheerful, with the odd bit of humour.'],
    ];

    public const LENGTHS = [
        600 => ['Short', 'A few sentences. Cheapest.'],
        1500 => ['Normal', 'Room for an outline or a full reply.'],
        3000 => ['Long', 'Longer drafts and detailed answers.'],
    ];

    public const CREATIVITY = [
        '' => ['Model default', 'Leave it to the model.'],
        '0.2' => ['Precise', 'Sticks closely to the facts.'],
        '0.6' => ['Balanced', 'A little variety in wording.'],
        '0.9' => ['Creative', 'More ideas, more variety.'],
    ];

    /** Which tools each switch covers. */
    public const TOOL_GROUPS = [
        'can_publish' => ['propose_publish', 'propose_schedule', 'propose_unpublish'],
        'can_edit' => ['propose_edit', 'propose_tags', 'propose_alt'],
        'can_draft' => ['propose_draft', 'propose_translation'],
        'can_comments' => ['propose_comment', 'propose_reply'],
    ];

    /** Pending actions, by switch (for a "Yes" pressed after a setting changed). */
    public const ACTION_GROUPS = [
        'can_publish' => ['publish', 'schedule', 'unpublish'],
        'can_edit' => ['edit', 'tags', 'alt'],
        'can_draft' => ['create_draft', 'translate'],
        'can_comments' => ['comment', 'reply'],
        'can_inserts' => ['insert'],
    ];

    public static function all(): array
    {
        $stored = rescue(fn () => (array) (Booklet::globals('stacy')?->data() ?? []), [], false);

        return array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function save(array $values): void
    {
        $clean = array_intersect_key($values, self::DEFAULTS);
        $site = config('booklet.default_site');

        if (! Booklet::globalSets()->find('stacy')) {
            Booklet::globalSets()->make(['handle' => 'stacy', 'title' => 'Stacy'])->save();
        }

        $globals = Booklet::globals('stacy') ?? Booklet::globalVariables()->make(['handle' => 'stacy', 'locale' => $site]);
        $globals->merge($clean)->save();
    }

    // Questions the rest of Booklet asks -----------------------------------

    public static function canUse(?User $user): bool
    {
        if (! $user || ! $user->mayUseStacy()) {
            return false; // their role's card leaves Stacy out
        }

        return match (self::get('who')) {
            'admins' => $user->level() === 'admin',
            'editors' => in_array($user->level(), ['admin', 'editor'], true),
            default => true,
        };
    }

    /** May she offer this tool at all? */
    public static function allowsTool(string $tool): bool
    {
        if (str_contains($tool, '__')) {
            // An insert's skill: looking up is fine; a change follows "Use inserts".
            $skill = rescue(fn () => app(\App\Inserts\InsertManager::class)->skills()[$tool]['skill'] ?? null, null, false);

            return $skill && (! $skill['changes'] || (self::get('can_change') && self::get('can_inserts')));
        }

        foreach (self::TOOL_GROUPS as $switch => $tools) {
            if (in_array($tool, $tools, true)) {
                return self::get('can_change') && self::get($switch);
            }
        }

        return true; // looking things up is always fine
    }

    /** May a confirmed change of this kind go ahead? */
    public static function allowsAction(string $action): bool
    {
        foreach (self::ACTION_GROUPS as $switch => $actions) {
            if (in_array($action, $actions, true)) {
                return self::get('can_change') && self::get($switch);
            }
        }

        return true;
    }

    /** Questions this person has asked today. */
    public static function askedToday(User $user): int
    {
        return \App\Models\StacyMessage::query()
            ->where('role', 'user')
            ->where('created_at', '>=', today())
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    /** Lines for her instructions, from the tone, language and house style. */
    public static function promptLines(): string
    {
        $c = self::all();
        $lines = [];

        $lines[] = match ($c['tone']) {
            'professional' => 'Tone: polite, precise and professional. No jokes or exclamation marks.',
            'brief' => 'Tone: as brief as possible. One or two short sentences unless more is truly needed.',
            'playful' => 'Tone: light, cheerful and a little playful, while staying helpful and clear.',
            default => 'Tone: warm, friendly and plain-spoken.',
        };

        if (trim((string) $c['language']) !== '') {
            $lines[] = 'Always answer in '.trim((string) $c['language']).', whatever language they write in.';
        } else {
            $lines[] = 'Answer in the language they write in.';
        }

        if (trim((string) $c['instructions']) !== '') {
            $lines[] = "House style from the site's admin (follow it unless it conflicts with safety or the rules above):\n".trim((string) $c['instructions']);
        }

        return implode("\n        ", $lines);
    }
}
