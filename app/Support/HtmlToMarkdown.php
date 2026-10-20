<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * HTML (from WordPress or anywhere else) into the Markdown Booklet writes in.
 * Headings, emphasis, links, pictures, lists (nested), quotes, code, tables,
 * figures with captions and video embeds come across; scripts, styles and
 * forms are left behind. Text without paragraphs (WordPress's classic
 * editor) is split into paragraphs the way WordPress itself would show it.
 */
class HtmlToMarkdown
{
    protected const DROP = ['script', 'style', 'noscript', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'canvas', 'template', 'head'];

    protected const BLOCKS = ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'details', 'summary', 'center', 'address'];

    public static function convert(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = self::autop($html);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__md">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__md');
        if (! $root) {
            return trim(strip_tags($html));
        }

        $markdown = self::blocks($root);

        // Tidy: no space after a line break, no trailing spaces, no more than one blank line.
        $markdown = preg_replace("/\\\\\n[ \t]+/", "\\\\\n", $markdown);
        $markdown = preg_replace("/[ \t]+\n/", "\n", (string) $markdown);
        $markdown = preg_replace("/\n{3,}/", "\n\n", (string) $markdown);

        return trim((string) $markdown);
    }

    /**
     * WordPress's classic editor keeps paragraphs as blank lines and line
     * breaks as single newlines; turn those into real paragraphs first.
     */
    public static function autop(string $html): string
    {
        if (preg_match('/<(p|div|h[1-6]|ul|ol|blockquote|pre|table|figure)\b/i', $html) && ! preg_match("/\n\s*\n/", strip_tags($html))) {
            return $html;
        }

        $blockStart = '/^\s*<(\/?)(p|div|h[1-6]|ul|ol|li|blockquote|pre|table|thead|tbody|tr|td|th|figure|figcaption|hr|section|iframe|!--)/i';
        $out = [];
        foreach (preg_split("/\n\s*\n/", str_replace(["\r\n", "\r"], "\n", $html)) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $out[] = preg_match($blockStart, $chunk) ? $chunk : '<p>'.preg_replace("/\n/", "<br>\n", $chunk).'</p>';
        }

        return implode("\n\n", $out);
    }

    /** Children of a block-level element, as Markdown blocks. */
    protected static function blocks(DOMNode $parent): string
    {
        $out = '';
        $inline = '';

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && self::isBlock($node)) {
                if (trim($inline) !== '') {
                    $out .= trim($inline)."\n\n";
                }
                $inline = '';
                $out .= self::block($node);
            } else {
                $inline .= self::inline($node);
            }
        }
        if (trim($inline) !== '') {
            $out .= trim($inline)."\n\n";
        }

        return $out;
    }

    protected static function isBlock(DOMElement $el): bool
    {
        $tag = strtolower($el->tagName);

        return in_array($tag, self::BLOCKS, true)
            || in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'table', 'hr', 'iframe', 'video', 'audio', 'dl'], true)
            || in_array($tag, self::DROP, true);
    }

    protected static function block(DOMElement $el): string
    {
        $tag = strtolower($el->tagName);

        if (in_array($tag, self::DROP, true)) {
            return '';
        }

        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                // The entry's title is the page's h1, so headings in the text start at h2.
                $level = max(2, min(6, (int) $tag[1] + ($tag === 'h1' ? 1 : 0)));
                $text = trim(self::inlineChildren($el));

                return $text === '' ? '' : str_repeat('#', $level).' '.str_replace("\n", ' ', $text)."\n\n";

            case 'ul':
            case 'ol':
                return self::listBlock($el, 0)."\n";

            case 'blockquote':
                $inner = trim(self::blocks($el));

                return $inner === '' ? '' : implode("\n", array_map(fn ($line) => rtrim('> '.$line), explode("\n", $inner)))."\n\n";

            case 'pre':
                $code = rtrim($el->textContent, "\n");
                $fence = str_contains($code, '```') ? '~~~' : '```';
                $language = '';
                if (preg_match('/\blanguage-([\w+-]+)/', $el->getAttribute('class').' '.(($el->firstChild instanceof DOMElement) ? $el->firstChild->getAttribute('class') : ''), $m)) {
                    $language = $m[1];
                }

                return $fence.$language."\n".$code."\n".$fence."\n\n";

            case 'hr':
                return "---\n\n";

            case 'table':
                return self::table($el);

            case 'iframe':
            case 'video':
            case 'audio':
                return self::embed($el);

            case 'figure':
                $out = '';
                $caption = '';
                foreach ($el->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                        $caption = self::escape(trim(preg_replace('/\s+/u', ' ', $child->textContent)));
                    } elseif ($child instanceof DOMElement && self::isBlock($child)) {
                        $out .= self::block($child);
                    } else {
                        $out .= self::inline($child);
                    }
                }
                $out = trim($out);

                return ($out !== '' ? $out."\n" : '').($caption !== '' ? '_'.$caption."_\n" : '')."\n";

            case 'dl':
                $out = '';
                foreach ($el->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $text = trim(self::inlineChildren($child));
                        $out .= strtolower($child->tagName) === 'dt' ? '**'.$text."**  \n" : $text."\n\n";
                    }
                }

                return $out;

            default: // p, div and friends
                $inner = self::blocks($el);

                return trim($inner) === '' ? '' : $inner;
        }
    }

    protected static function listBlock(DOMElement $list, int $depth): string
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $n = max(1, (int) ($list->getAttribute('start') ?: 1));
        $out = '';

        foreach ($list->childNodes as $item) {
            if (! ($item instanceof DOMElement) || strtolower($item->tagName) !== 'li') {
                continue;
            }
            $marker = $ordered ? ($n++).'. ' : '- ';
            $pad = str_repeat(' ', strlen($marker));
            $text = '';
            $nested = '';

            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $nested .= self::listBlock($child, $depth + 1);
                } elseif ($child instanceof DOMElement && self::isBlock($child)) {
                    $text .= ' '.trim(self::blocks($child));
                } else {
                    $text .= self::inline($child);
                }
            }

            $lines = explode("\n", trim(preg_replace('/[ \t]+/', ' ', $text)));
            $indent = str_repeat('   ', $depth);
            $out .= $indent.$marker.array_shift($lines)."\n";
            foreach ($lines as $line) {
                $out .= $indent.$pad.trim($line)."\n";
            }
            $out .= $nested;
        }

        return $out;
    }

    protected static function table(DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = str_replace(['|', "\n"], ['\|', ' '], trim(self::inlineChildren($cell)));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $line = fn ($cells) => '| '.implode(' | ', array_pad($cells, $width, '')).' |';
        $out = $line(array_shift($rows))."\n".'| '.implode(' | ', array_fill(0, $width, '---')).' |'."\n";
        foreach ($rows as $row) {
            $out .= $line($row)."\n";
        }

        return $out."\n";
    }

    /** A video or other embed becomes a link to watch it. */
    protected static function embed(DOMElement $el): string
    {
        $src = $el->getAttribute('src');
        if ($src === '') {
            foreach ($el->getElementsByTagName('source') as $source) {
                $src = $source->getAttribute('src');
                break;
            }
        }
        if ($src === '') {
            return '';
        }
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }

        $label = 'Watch the video';
        if (preg_match('#youtube(?:-nocookie)?\.com/embed/([\w-]{6,})#', $src, $m)) {
            $src = 'https://www.youtube.com/watch?v='.$m[1];
        } elseif (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
            $src = 'https://vimeo.com/'.$m[1];
        } elseif (strtolower($el->tagName) === 'audio') {
            $label = 'Listen';
        } elseif (strtolower($el->tagName) === 'iframe') {
            $label = 'Open the embedded page';
        }

        return '['.$label.']('.$src.")\n\n";
    }

    protected static function inlineChildren(DOMNode $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $child) {
            $out .= self::inline($child);
        }

        return $out;
    }

    protected static function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return self::escape(preg_replace('/\s+/u', ' ', $node->wholeText === $node->data ? $node->data : $node->data));
        }
        if (! ($node instanceof DOMElement)) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }

        switch ($tag) {
            case 'br':
                return "\\\n"; // a hard line break (a backslash at the end of the line)

            case 'strong':
            case 'b':
                $text = trim(self::inlineChildren($node));

                return $text === '' ? '' : self::wrap(self::inlineChildren($node), '**');

            case 'cite':
                if ($node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'blockquote') {
                    return '— '.trim(self::inlineChildren($node));
                }
                // otherwise, like emphasis
            case 'em':
            case 'i':
                $text = trim(self::inlineChildren($node));

                return $text === '' ? '' : self::wrap(self::inlineChildren($node), '_');

            case 'del':
            case 's':
            case 'strike':
                return self::wrap(self::inlineChildren($node), '~~');

            case 'code':
            case 'kbd':
                $code = $node->textContent;

                return str_contains($code, '`') ? '`` '.$code.' ``' : '`'.$code.'`';

            case 'a':
                $href = trim($node->getAttribute('href'));
                $text = trim(self::inlineChildren($node));
                if ($href === '' || preg_match('#^(javascript|data|vbscript):#i', $href)) {
                    return $text;
                }
                if ($text === '') {
                    return '';
                }

                return '['.$text.']('.self::url($href).')';

            case 'img':
                $src = trim($node->getAttribute('src'));
                if ($src === '' || preg_match('#^(javascript|data):#i', $src)) {
                    return '';
                }

                return '!['.self::escape(trim($node->getAttribute('alt'))).']('.self::url($src).')';

            case 'sup':
            case 'sub':
            case 'span':
            case 'small':
            case 'mark':
            case 'abbr':
            case 'u':
            case 'font':
            case 'label':
            case 'time':
            default:
                return self::isBlock($node) ? ' '.trim(self::blocks($node)).' ' : self::inlineChildren($node);
        }
    }

    /** "**word** " rather than "** word**" (Markdown needs the marks against the words). */
    protected static function wrap(string $text, string $mark): string
    {
        if (trim($text) === '') {
            return $text;
        }
        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);

        return $m[1].$mark.$m[2].$mark.$m[3];
    }

    protected static function trimEmphasis(string $text): string
    {
        return trim(preg_replace('/^_+|_+$/', '', $text));
    }

    protected static function url(string $url): string
    {
        $url = str_replace(' ', '%20', $url);

        return str_contains($url, ')') ? '<'.$url.'>' : $url;
    }

    /** Keep Markdown's own characters from turning ordinary text into formatting. */
    protected static function escape(string $text): string
    {
        $text = str_replace(['\\', '*', '`', '[', ']'], ['\\\\', '\*', '\`', '\[', '\]'], $text);
        // Underscores only matter at the edge of a word (snake_case stays as it is).
        $text = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\_', $text);

        // A line that would read as a heading, quote or list item.
        return preg_replace('/^(\s*)(#{1,6}\s|>|[-+]\s|\d+\.\s)/', '$1\\\\$2', $text);
    }
}
