<?php

namespace App\Booklet\Support;

/**
 * A small word-level diff for the Editions screen: which words were added
 * and which were removed between two versions of a text.
 *
 *   WordDiff::compare('the quick fox', 'the slow fox')
 *   → [['same', 'the '], ['removed', 'quick '], ['added', 'slow '], ['same', 'fox']]
 */
class WordDiff
{
    /** Above this many words on either side, compare whole paragraphs instead. */
    protected const WORD_LIMIT = 3000;

    /**
     * @return array<int, array{0: 'same'|'added'|'removed', 1: string}>
     */
    public static function compare(string $old, string $new): array
    {
        $a = static::tokens($old);
        $b = static::tokens($new);

        if (count($a) > static::WORD_LIMIT || count($b) > static::WORD_LIMIT) {
            $a = static::paragraphs($old);
            $b = static::paragraphs($new);
        }

        return static::merge(static::diff($a, $b));
    }

    /**
     * @return array{added: int, removed: int} counts of words
     */
    public static function stats(array $segments): array
    {
        $count = fn (string $text) => str_word_count(strip_tags($text));
        $stats = ['added' => 0, 'removed' => 0];

        foreach ($segments as [$type, $text]) {
            if ($type !== 'same') {
                $stats[$type] += $count($text);
            }
        }

        return $stats;
    }

    /**
     * Words with their trailing whitespace, so joining them gives the text back.
     *
     * @return string[]
     */
    protected static function tokens(string $text): array
    {
        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }

    /**
     * @return string[]
     */
    protected static function paragraphs(string $text): array
    {
        return preg_split('/(?<=\n\n)/', $text) ?: [];
    }

    /**
     * Longest-common-subsequence diff over tokens.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected static function diff(array $a, array $b): array
    {
        // Trim the shared start and end first: edits are usually small.
        $prefix = [];
        while ($a && $b && $a[0] === $b[0]) {
            $prefix[] = ['same', array_shift($a)];
            array_shift($b);
        }

        $suffix = [];
        while ($a && $b && end($a) === end($b)) {
            array_unshift($suffix, ['same', array_pop($a)]);
            array_pop($b);
        }

        $n = count($a);
        $m = count($b);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $a[$i] === $b[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $middle = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $middle[] = ['same', $a[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $middle[] = ['removed', $a[$i++]];
            } else {
                $middle[] = ['added', $b[$j++]];
            }
        }
        while ($i < $n) {
            $middle[] = ['removed', $a[$i++]];
        }
        while ($j < $m) {
            $middle[] = ['added', $b[$j++]];
        }

        return array_merge($prefix, $middle, $suffix);
    }

    /**
     * Join neighbouring tokens of the same kind into one segment.
     */
    protected static function merge(array $tokens): array
    {
        $segments = [];

        foreach ($tokens as [$type, $text]) {
            $last = array_key_last($segments);

            if ($last !== null && $segments[$last][0] === $type) {
                $segments[$last][1] .= $text;
            } else {
                $segments[] = [$type, $text];
            }
        }

        return $segments;
    }
}
