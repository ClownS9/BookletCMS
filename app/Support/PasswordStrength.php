<?php

namespace App\Support;

/**
 * How hard a password is to guess, from 0 (one of the first guesses anyone
 * would try) to 4 (very strong). The same reckoning runs in the browser for
 * the meter (bookletPassword in booklet.js), so what the meter shows is what
 * the server accepts. Change one, change the other (tests hold them together).
 *
 * Roughly: well-known passwords score 0; your name, your email and the
 * site's name count for next to nothing; so do runs like "aaaa", "1234",
 * "qwerty", years and the words people always reach for. What's left is
 * measured by length and the kinds of characters used. Long passphrases of
 * plain words do well, which is the point.
 */
class PasswordStrength
{
    public const MIN_LENGTH = 10;

    /** Bcrypt only reads the first 72 bytes, so anything longer would be silently cut. */
    public const MAX_BYTES = 72;

    /** The score a new password needs (Fair or better). */
    public const MIN_SCORE = 2;

    public const LABELS = ['Too easy to guess', 'Weak', 'Fair', 'Strong', 'Very strong'];

    /** Words people reach for: each counts as a single character wherever it appears. */
    public const WORDS = ['password', 'passw0rd', 'p@ssword', 'admin', 'welcome', 'letmein', 'login', 'qwerty', 'monkey', 'dragon', 'master', 'sunshine', 'princess', 'football', 'baseball', 'iloveyou', 'secret', 'summer', 'winter', 'spring', 'autumn', 'hello', 'shadow', 'superman', 'batman', 'freedom', 'whatever', 'booklet', 'circas', 'love', 'sayang', 'cinta'];

    /** Runs along these count as one character (forwards or backwards). */
    public const ROWS = ['abcdefghijklmnopqrstuvwxyz', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890', '0123456789'];

    protected const LEET = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i'];

    protected static ?array $common = null;

    /**
     * @param  array<int, ?string>  $personal  name, email, site name… (anything the password shouldn't lean on)
     * @return array{score: int, label: string, bits: float, warnings: string[]}
     */
    public static function check(string $password, array $personal = []): array
    {
        $chars = mb_str_split($password);
        if ($chars === []) {
            return self::result(0, 0.0, ['short']);
        }

        $warnings = [];
        $lower = mb_strtolower($password);
        $bare = preg_replace('/[\d!?.@#$*_\-]+$/u', '', $lower);

        if (self::isCommon($lower) || self::isCommon($bare) || self::isCommon(self::leet($lower)) || self::isCommon(self::leet($bare))) {
            return self::result(0, 0.0, ['common']);
        }

        // What's left once the guessable parts count as one character each.
        // (Matched with letters for look-alike digits too: "B00klet" is still "booklet".)
        $s = $lower;
        foreach (self::personalTokens($personal) as $token) {
            if (self::swap($s, $token, "\x02")) {
                $warnings[] = 'personal';
            }
        }
        foreach (self::sortedWords() as $word) {
            if (self::swap($s, $word, "\x01")) {
                $warnings[] = 'pattern';
            }
        }
        $s = preg_replace('/(19|20)\d{2}/u', "\x03", $s, -1, $years);
        if ($years) {
            $warnings[] = 'pattern';
        }

        [$tokens, $runs] = self::tokens(mb_str_split($s));
        if ($runs) {
            $warnings[] = 'pattern';
        }

        $bits = $tokens * log(self::pool($password), 2);
        if (count(array_unique($chars)) < 4) {
            $bits = min($bits, 20.0);
        }
        if (count($chars) < self::MIN_LENGTH) {
            $warnings[] = 'short';
        }

        $score = match (true) {
            $bits < 28 => 0,
            $bits < 36 => 1,
            $bits < 50 => 2,
            $bits < 64 => 3,
            default => 4,
        };

        return self::result($score, $bits, array_values(array_unique($warnings)));
    }

    /**
     * Why it isn't good enough, in a sentence; null when it's fine.
     */
    public static function problem(string $password, array $personal = []): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Use at least '.self::MIN_LENGTH.' characters. Three or four unrelated words work well.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'That’s longer than passwords can be here ('.self::MAX_BYTES.' letters, or fewer with accents or emoji).';
        }

        $check = self::check($password, $personal);
        if ($check['score'] >= self::MIN_SCORE) {
            return null;
        }

        return match (true) {
            in_array('common', $check['warnings'], true) => 'That’s one of the most used passwords, so it’s among the first anyone would try. Pick something only you would think of.',
            in_array('personal', $check['warnings'], true) => 'Leave your name, your email and the site’s name out of it; they’re the first things someone would try.',
            in_array('pattern', $check['warnings'], true) => 'Patterns like 1234, aaaa, qwerty, a year or the word “password” are easy to guess. Add a few unrelated words.',
            default => 'That’s too easy to guess. Make it longer: a few unrelated words, with a number or symbol between them.',
        };
    }

    /**
     * Pieces of someone's details worth keeping out of a password.
     *
     * @return string[] longest first
     */
    public static function personalTokens(array $personal): array
    {
        $tokens = [];
        foreach ($personal as $value) {
            $value = mb_strtolower(trim((string) $value));
            if ($value === '') {
                continue;
            }
            if (str_contains($value, '@')) {
                $tokens[] = $value; // the whole address, too
                $local = explode('@', $value)[0];
                $tokens[] = $local;
                $value = $local;
            }
            $tokens[] = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $tokens[] = $part;
            }
        }

        $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t) >= 3)));
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));

        return $tokens;
    }

    /** @return string[] */
    public static function common(): array
    {
        if (self::$common === null) {
            $js = (string) @file_get_contents(public_path('booklet/password-words.js'));
            $list = json_decode(substr($js, (int) strpos($js, '['), (int) strrpos($js, ']') - (int) strpos($js, '[') + 1), true);
            self::$common = array_flip(is_array($list) ? $list : []);
        }

        return self::$common;
    }

    protected static function isCommon(string $value): bool
    {
        return $value !== '' && isset(self::common()[$value]);
    }

    protected static function leet(string $value): string
    {
        return strtr($value, self::LEET);
    }

    /**
     * Swap every place $needle appears (reading look-alike digits as letters)
     * for a single placeholder character. True if there were any.
     */
    protected static function swap(string &$s, string $needle, string $placeholder): bool
    {
        $needle = self::leet($needle);
        $length = mb_strlen($needle);
        $found = false;

        while (($at = mb_strpos(self::leet($s), $needle)) !== false) {
            $s = mb_substr($s, 0, $at).$placeholder.mb_substr($s, $at + $length);
            $found = true;
        }

        return $found;
    }

    /** @return string[] longest first */
    protected static function sortedWords(): array
    {
        $words = self::WORDS;
        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $words;
    }

    /**
     * How many characters really count: runs of three or more ("aaa", "abc",
     * "321", "qwe") count once.
     *
     * @param  string[]  $c
     * @return array{0: int, 1: bool}
     */
    protected static function tokens(array $c): array
    {
        $tokens = 0;
        $runs = false;
        $n = count($c);

        for ($i = 0; $i < $n;) {
            $length = self::runAt($c, $i);
            if ($length >= 3) {
                $runs = true;
                $tokens++;
                $i += $length;
            } else {
                $tokens++;
                $i++;
            }
        }

        return [$tokens, $runs];
    }

    protected static function runAt(array $c, int $i): int
    {
        $n = count($c);

        $same = 1;
        while ($i + $same < $n && $c[$i + $same] === $c[$i]) {
            $same++;
        }

        $best = $same;
        foreach (self::ROWS as $row) {
            foreach ([$row, strrev($row)] as $line) {
                $k = 1;
                while ($i + $k < $n) {
                    $at = strpos($line, $c[$i + $k - 1]);
                    if ($at === false || $at + 1 >= strlen($line) || $line[$at + 1] !== $c[$i + $k]) {
                        break;
                    }
                    $k++;
                }
                $best = max($best, $k);
            }
        }

        return $best;
    }

    /** How many different characters each position could be. */
    protected static function pool(string $password): int
    {
        $pool = 0;
        $pool += preg_match('/[a-z]/', $password) ? 26 : 0;
        $pool += preg_match('/[A-Z]/', $password) ? 26 : 0;
        $pool += preg_match('/[0-9]/', $password) ? 10 : 0;
        $pool += preg_match('/[\x20-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/', $password) ? 33 : 0;
        $pool += preg_match('/[^\x00-\x7E]/u', $password) ? 100 : 0;

        return max($pool, 10);
    }

    protected static function result(int $score, float $bits, array $warnings): array
    {
        return ['score' => $score, 'label' => self::LABELS[$score], 'bits' => round($bits, 1), 'warnings' => $warnings];
    }
}
