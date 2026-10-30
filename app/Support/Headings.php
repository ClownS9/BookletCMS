<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Headings in a post, in order: screen readers (and search engines) find
 * their way by them, so each should be one step below the last, starting
 * under the title (## first, then ###…). The writing page points out a
 * jump, kindly; nothing is changed for the writer.
 */
class Headings
{
    /**
     * The first heading that jumps a step, or null.
     *
     * @return array{text: string, level: int, after: int}|null
     */
    public static function skip(string $body): ?array
    {
        $previous = 1; // the title
        $inCode = false;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inCode = ! $inCode;

                continue;
            }
            if ($inCode) {
                continue;
            }
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                [$level, $text] = [strlen($m[1]), $m[2]];
            } elseif (preg_match('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/i', $line, $m)) {
                [$level, $text] = [(int) $m[1], strip_tags($m[2])];
            } else {
                continue;
            }
            if ($level > $previous + 1) {
                return ['text' => Str::limit(trim($text), 60), 'level' => $level, 'after' => $previous];
            }
            $previous = $level;
        }

        return null;
    }
}
