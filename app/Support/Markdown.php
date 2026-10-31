<?php

namespace App\Support;

use Illuminate\Support\Str;
use League\CommonMark\Extension\Highlight\HighlightExtension;

/**
 * Markdown for writing: GitHub-flavoured (Laravel's own: ~~crossed out~~,
 * tables, - [ ] checklists), and ==highlighted== words on top. Every
 * place that turns a post's words into HTML goes through here, so the site,
 * the feed, the API, the print book and word counts read them the same way.
 */
class Markdown
{
    public static function html(?string $text, array $options = []): string
    {
        return (string) Str::markdown((string) $text, $options + [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ], [new HighlightExtension]);
    }
}
