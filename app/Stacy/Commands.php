<?php

namespace App\Stacy;

/**
 * Shortcuts typed into Stacy's box: "/check", "/draft a post about spring",
 * "/seo" while editing, and so on. Each one becomes a plain request for her.
 */
class Commands
{
    /**
     * name => [what it does (shown in the menu), takes words after it?]
     */
    public const LIST = [
        'brief' => ['A quick briefing for today', false],
        'check' => ['Check the book for things that need attention', false],
        'draft' => ['Start a post about something: /draft spring recipes', true],
        'page' => ['Start a new page: /page Our team', true],
        'title' => ['Suggest a better title for what you’re editing', false],
        'seo' => ['Write a search title and description', false],
        'tags' => ['Suggest a category and tags', false],
        'alt' => ['Write alt text for pictures that have none', false],
        'comments' => ['Read waiting comments and help reply', false],
        'drafts' => ['Show your drafts', false],
        'menu' => ['Arrange the menu in words: /menu put Shop first', true],
        'go' => ['Go somewhere: /go media', true],
        'help' => ['What Stacy can do', false],
    ];

    /**
     * For the menu that opens on "/".
     *
     * @return array<int, array{name: string, hint: string, args: bool}>
     */
    public static function menu(): array
    {
        return collect(self::LIST)->map(fn ($c, $name) => ['name' => $name, 'hint' => $c[0], 'args' => $c[1]])->values()->all();
    }

    /**
     * Turn "/command words" into what to ask. Anything else comes back as it was.
     */
    public static function expand(string $text, array $context = []): string
    {
        if (! preg_match('#^/([a-z]+)\b\s*(.*)$#is', trim($text), $m)) {
            return $text;
        }

        $name = strtolower($m[1]);
        $words = trim($m[2]);
        $editing = isset($context['entry_id']);

        return match ($name) {
            'brief' => 'Brief me on today.',
            'check' => 'Give my book a check-up. What needs attention?',
            'draft' => $words !== ''
                ? "Start a draft post about: {$words}. Give it a good title, a one-line standfirst and a short outline to write from."
                : 'Help me start a new post. Ask me what it’s about.',
            'page' => $words !== '' ? "Start a new page called “{$words}”." : 'Help me start a new page. Ask me what it’s called.',
            'title' => $editing ? 'Suggest a better title for this.' : 'Which of my drafts could use a better title? Suggest one.',
            'seo' => $editing
                ? 'Write a search title and meta description for this, and offer to save them.'
                : 'Find a published post or page with no meta description and write one for it.',
            'tags' => $editing
                ? 'Suggest a category and a few tags for this, using existing ones where they fit.'
                : 'Find a published post with no tags and suggest some.',
            'alt' => 'Find pictures without alt text and suggest alt text for the first one.',
            'comments' => 'Any comments waiting? Show me the newest and help me reply.',
            'drafts' => 'Show me my drafts.',
            'menu' => $words !== ''
                ? "Arrange the header menu: {$words}. Read the menu first, then propose it."
                : 'Show me the header menu, and anything wrong with it.',
            'go' => $words !== '' ? "Take me to {$words}." : 'Where can you take me?',
            'help' => 'What can you help me with? Tell me in a few short sentences.',
            default => $text,
        };
    }
}
