<?php

namespace App\Inserts;

/**
 * Reads what an insert says about itself (name, version, author…) from its
 * insert.php WITHOUT running it: only plain written-out values at the top
 * of the array it returns are picked up. Used to show what's in an insert
 * before anyone agrees to add it, and to check for newer versions.
 */
class ManifestReader
{
    /** The values worth reading before running anything. */
    public const KEYS = ['key', 'name', 'about', 'version', 'author', 'sticker', 'needs', 'homepage'];

    /**
     * @return array<string, string>
     *
     * @throws \ParseError when the PHP isn't valid
     */
    public static function read(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code, TOKEN_PARSE),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        // The `return [` (or `return array(`) that isn't inside a function.
        $start = null;
        $braces = 0;
        foreach ($tokens as $i => $t) {
            $text = is_array($t) ? $t[1] : $t;
            if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $braces++;
            } elseif ($text === '}') {
                $braces--;
            } elseif ($braces === 0 && is_array($t) && $t[0] === T_RETURN) {
                $next = $tokens[$i + 1] ?? null;
                if ($next === '[') {
                    $start = $i + 2;
                } elseif (is_array($next) && $next[0] === T_ARRAY && ($tokens[$i + 2] ?? null) === '(') {
                    $start = $i + 3;
                }
            }
        }
        if ($start === null) {
            return [];
        }

        $found = [];
        $depth = 1;
        for ($i = $start, $n = count($tokens); $i < $n && $depth > 0; $i++) {
            $t = $tokens[$i];
            $text = is_array($t) ? $t[1] : $t;
            if (in_array($text, ['[', '(', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }
            if (in_array($text, [']', ')', '}'], true)) {
                $depth--;

                continue;
            }
            // At the top: 'key' => 'plain value' followed by , or the end.
            if ($depth !== 1 || ! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $arrow = $tokens[$i + 1] ?? null;
            $value = $tokens[$i + 2] ?? null;
            $after = $tokens[$i + 3] ?? null;
            if (! is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW || ! is_array($value) || ! in_array($after, [',', ']', ')'], true)) {
                continue;
            }
            $key = self::literal($t[1]);
            if (! in_array($key, self::KEYS, true) || isset($found[$key])) {
                continue;
            }
            if ($value[0] === T_CONSTANT_ENCAPSED_STRING) {
                $found[$key] = self::literal($value[1]);
            } elseif (in_array($value[0], [T_LNUMBER, T_DNUMBER], true)) {
                $found[$key] = $value[1];
            }
        }

        return array_map(fn ($v) => mb_substr(trim($v), 0, 500), $found);
    }

    protected static function literal(string $token): string
    {
        $quote = $token[0];
        $inner = substr($token, 1, -1);

        return $quote === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner) : stripcslashes($inner);
    }
}
