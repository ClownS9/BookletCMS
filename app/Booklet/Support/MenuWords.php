<?php

namespace App\Booklet\Support;

use Illuminate\Support\Str;

/**
 * Arranging a menu in plain words, without an AI: "put Shop first and tuck
 * Prints under it", "move Contact after About", "rename Journal to Notes",
 * "remove Blog", "add the Our team page under About", "bring Food up a level".
 *
 * Names are matched against the menu's labels (whole names first, so a name
 * with "and" in it stays whole; then the start of a name; then any part of
 * one, as long as only one row fits). "it" means the row named just before.
 */
class MenuWords
{
    /**
     * @param  list<array<string, mixed>>  $rows  the menu as an outline
     * @param  callable(array): string  $labelOf  what a row is called
     * @param  (callable(string): ?string)|null  $findPage  a page's id from its title, for "add"
     * @return array{rows: list<array<string, mixed>>, done: list<string>, problems: list<string>}
     */
    public static function apply(array $rows, string $words, callable $labelOf, ?callable $findPage = null): array
    {
        $rows = MenuOutline::normalize($rows);
        $done = [];
        $problems = [];
        $it = null; // the key of the row named last

        // Web addresses are kept whole, before anything else is looked for in the words.
        $addresses = [];
        $words = (string) preg_replace_callback('#(?<![\pL\pN])(https?://[^\s,;]+|mailto:[^\s,;]+|tel:[^\s,;]+|/[A-Za-z0-9._~%/-]*)#u', function ($m) use (&$addresses) {
            $addresses[] = rtrim($m[1], '.');

            return ' ⟪'.(count($addresses) - 1).'⟫ ';
        }, $words);

        // Names said in quotes, or known labels, stand for themselves (so "Prints and posters" isn't split).
        $names = [];
        $text = preg_replace_callback('/[“"‘\']([^”"’\']{1,80})[”"’\']/u', function ($m) use (&$names) {
            $names[] = trim($m[1]);

            return ' ⟦'.(count($names) - 1).'⟧ ';
        }, ' '.trim($words).' ');
        $labels = collect($rows)->map(fn ($r) => $labelOf($r))->filter(fn ($l) => mb_strlen($l) > 1)->unique()->sortByDesc(fn ($l) => mb_strlen($l))->values();
        foreach ($labels as $label) {
            $text = preg_replace_callback('/(?<![\pL\pN⟦])'.preg_quote($label, '/').'(?![\pL\pN⟧])/iu', function () use (&$names, $label) {
                $names[] = $label;

                return ' ⟦'.(count($names) - 1).'⟧ ';
            }, (string) $text);
        }

        // Clauses: split at commas, "and", "then" (a full stop only at a sentence's end, not inside a web address).
        $clauses = preg_split('/\s*(?:[,;]|\.(?=\s|$)|\band then\b|\bthen\b|\band\b|\balso\b)\s*/iu', (string) $text) ?: [];
        $before = null; // the row named in the clause before this one (what "it" means)

        foreach ($clauses as $clause) {
            $clause = trim(preg_replace('/\s+/', ' ', $clause));
            if ($clause === '' || preg_match('/^(please|thanks|thank you|could you|can you)$/i', $clause)) {
                continue;
            }
            $before = $it;
            $said = preg_replace_callback('/⟦(\d+)⟧/u', fn ($m) => $names[(int) $m[1]] ?? '', $clause);
            $said = trim((string) preg_replace_callback('/\s*⟪(\d+)⟫\s*/u', fn ($m) => ' '.($addresses[(int) $m[1]] ?? '').' ', (string) $said));
            $clause = preg_replace('/\b(please|could you|can you|the menu item|menu item|in the menu|from the menu|of the menu)\b/i', '', $clause);
            $clause = trim(preg_replace('/\s+/', ' ', (string) $clause));

            $find = function (string $what) use (&$rows, $names, $labelOf, &$it, $before): ?int {
                $what = trim($what);
                if (preg_match('/^(it|that|that one|this|this one|them)$/i', $what)) {
                    $ref = $before ?? $it;

                    return $ref !== null ? self::indexOfKey($rows, $ref) : null;
                }
                $name = preg_match('/^⟦(\d+)⟧$/u', $what, $m) ? ($names[(int) $m[1]] ?? '') : preg_replace('/^(the|a|an|my|our)\s+/i', '', $what);
                $name = trim((string) preg_replace('/\s+(page|link|item|tab|menu)$/i', '', (string) $name));
                $found = self::match($rows, $name, $labelOf);
                if ($found !== null) {
                    $it = $rows[$found]['key'];
                }

                return $found;
            };
            $name = '(⟦\d+⟧|it|that(?: one)?|this(?: one)?|[^⟦⟧⟪⟫]+?)';
            $verb = '(?:put|move|place|bring|set|make|tuck|nest|hang|drag|have)?';

            // rename X to Y / call X "Y"
            if (preg_match('/^(?:rename|call|retitle|change)\s+'.$name.'\s+(?:to|as|into)?\s*(⟦\d+⟧|.+)$/iu', $clause, $m)
                || preg_match('/^'.$name.'\s+(?:should be called|is called|becomes)\s+(⟦\d+⟧|.+)$/iu', $clause, $m)) {
                $i = $find($m[1]);
                $new = trim(preg_match('/^⟦(\d+)⟧$/u', trim($m[2]), $q) ? ($names[(int) $q[1]] ?? '') : $m[2]);
                if ($i === null || $new === '') {
                    $problems[] = self::unknown($said, $m[1], $names);

                    continue;
                }
                $old = $labelOf($rows[$i]);
                $rows[$i]['title'] = Str::limit($new, 80, '');
                $done[] = 'Renamed “'.$old.'” to “'.$rows[$i]['title'].'”';

                continue;
            }

            // remove X
            if (preg_match('/^(?:remove|delete|drop|take out|take away|get rid of|hide)\s+'.$name.'$/iu', $clause, $m)
                || preg_match('/^(?:take)\s+'.$name.'\s+(?:out|away|off)$/iu', $clause, $m)) {
                $i = $find($m[1]);
                if ($i === null) {
                    $problems[] = self::unknown($said, $m[1], $names);

                    continue;
                }
                $label = $labelOf($rows[$i]);
                $rows = MenuOutline::remove($rows, $i);
                $it = null;
                $done[] = 'Took “'.$label.'” out';

                continue;
            }

            // add (the) X (page) [under|after|before Y]
            if (preg_match('/^add\s+(?:a\s+link\s+)?'.$name.'(?:\s+(?:page|link))?(?:\s+(?:linking\s+|that goes\s+|going\s+)?(?:to|at)\s+(⟪\d+⟫))?(?:\s+(under|inside|into|after|before|below|above)\s+'.$name.')?$/iu', $clause, $m)) {
                $title = trim(preg_match('/^⟦(\d+)⟧$/u', trim($m[1]), $q) ? ($names[(int) $q[1]] ?? '') : preg_replace('/^(the|a|an|our|my)\s+/i', '', $m[1]));
                $title = trim((string) preg_replace('/\s+page$/i', '', $title));
                $url = preg_match('/^⟪(\d+)⟫$/u', (string) ($m[2] ?? ''), $u) ? ($addresses[(int) $u[1]] ?? '') : '';
                $row = null;
                if ($url !== '' && preg_match('#^(https?://|/|mailto:|tel:)#i', $url)) {
                    $row = ['depth' => 0, 'url' => $url, 'title' => Str::limit($title, 80, '')];
                } elseif ($findPage && ($id = $findPage($title))) {
                    $row = ['depth' => 0, 'entry' => $id];
                }
                if (! $row) {
                    $problems[] = 'There’s no page called “'.$title.'” to add. To add a link, say “add Shop linking to https://…”.';

                    continue;
                }
                $rows = MenuOutline::normalize([...$rows, $row]);
                $new = count($rows) - 1;
                $it = $rows[$new]['key'];
                $where = '';
                if (! empty($m[3]) && ! empty($m[4])) {
                    $j = $find($m[4]);
                    $it = $rows[$new]['key'];
                    if ($j !== null) {
                        [$rows, $where] = self::placeNear($rows, $new, $j, strtolower($m[3]), $labelOf);
                    }
                }
                $done[] = 'Added “'.$title.'”'.$where;

                continue;
            }

            // bring X up a level / out from under Y / to the top level
            if (preg_match('/^'.$verb.'\s*'.$name.'\s+(?:up a level|up one level|out(?: from under(?: .+)?)?|out of .+|back up|to the top level|to the top bar|onto the bar|into the bar|up to the bar)$/iu', $clause, $m)
                || preg_match('/^(?:lift|unnest|untuck)\s+'.$name.'$/iu', $clause, $m)) {
                $i = $find($m[1]);
                if ($i === null) {
                    $problems[] = self::unknown($said, $m[1], $names);

                    continue;
                }
                $all = (bool) preg_match('/top level|top bar|onto the bar|into the bar|up to the bar/i', $clause);
                $key = $rows[$i]['key'];
                do {
                    $rows = MenuOutline::outdent($rows, $i);
                    $i = self::indexOfKey($rows, $key);
                } while ($all && $i !== null && (int) $rows[$i]['depth'] > 0);
                $done[] = 'Brought “'.$labelOf($rows[$i]).'” '.((int) $rows[$i]['depth'] === 0 ? 'onto the menu bar' : 'up a level');

                continue;
            }

            // X first / last
            if (preg_match('/^'.$verb.'\s*'.$name.'\s+(?:to\s+(?:the\s+)?|at\s+(?:the\s+)?)?(first|last|top|start|front|beginning|end|bottom|left|right)(?:\s+of\s+.+)?$/iu', $clause, $m)) {
                $i = $find($m[1]);
                if ($i === null) {
                    $problems[] = self::unknown($said, $m[1], $names);

                    continue;
                }
                $first = in_array(strtolower($m[2]), ['first', 'top', 'start', 'front', 'beginning', 'left'], true);
                $key = $rows[$i]['key'];
                $rows = self::toEnd($rows, $i, $first);
                $i = (int) self::indexOfKey($rows, $key);
                $done[] = 'Put “'.$labelOf($rows[$i]).'” '.($first ? 'first' : 'last').((int) $rows[$i]['depth'] > 0 ? ' in its drop-down' : '');

                continue;
            }

            // X under / before / after Y
            if (preg_match('/^'.$verb.'\s*'.$name.'\s+(under|underneath|inside|into|in|beneath|before|ahead of|in front of|above|after|behind|next to|below)\s+'.$name.'$/iu', $clause, $m)) {
                $i = $find($m[1]);
                $subject = $i !== null ? $rows[$i]['key'] : null;
                $j = $find($m[3]);
                $it = $subject; // "it" stays the thing being moved
                if ($i === null || $j === null) {
                    $problems[] = self::unknown($said, $i === null ? $m[1] : $m[3], $names);

                    continue;
                }
                if ($i === $j) {
                    continue;
                }
                $how = strtolower($m[2]);
                [$rows, $where] = self::placeNear($rows, $i, $j, $how, $labelOf);
                $moved = self::indexOfKey($rows, (string) $subject);
                $done[] = 'Moved “'.$labelOf($rows[(int) $moved]).'”'.$where;

                continue;
            }

            $problems[] = 'I didn’t follow “'.trim($said).'”. Try “put Shop first”, “tuck Prints under Shop”, “move Contact after About”, “rename Journal to Notes” or “remove Blog”.';
        }

        return ['rows' => MenuOutline::normalize($rows), 'done' => $done, 'problems' => $problems];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    protected static function placeNear(array $rows, int $i, int $j, string $how, callable $labelOf): array
    {
        $target = $labelOf($rows[$j]);
        $depth = (int) $rows[$j]['depth'];
        $key = $rows[$i]['key'];

        if (in_array($how, ['under', 'underneath', 'inside', 'into', 'in', 'beneath'], true)) {
            if ($depth >= MenuOutline::MAX_DEPTH) {
                return [$rows, ' (a menu only goes three levels deep, so it stayed where it was)'];
            }
            $rows = MenuOutline::place($rows, $i, MenuOutline::blockEnd($rows, $j), $depth + 1);
            $at = self::indexOfKey($rows, $key);
            $nested = $at !== null && (int) $rows[$at]['depth'] === $depth + 1;

            return [$rows, $nested ? ' under “'.$target.'”' : ' (it has too much under it to go that deep)'];
        }
        if (in_array($how, ['before', 'ahead of', 'in front of', 'above'], true)) {
            return [MenuOutline::place($rows, $i, $j, $depth), ' before “'.$target.'”'];
        }

        return [MenuOutline::place($rows, $i, MenuOutline::blockEnd($rows, $j), $depth), ' after “'.$target.'”'];
    }

    /** First or last among its siblings (with what's under it). */
    protected static function toEnd(array $rows, int $i, bool $first): array
    {
        $depth = (int) $rows[$i]['depth'];
        // Its parent's branch: from just after the parent to the end of the parent's block.
        $parent = null;
        for ($k = $i - 1; $k >= 0; $k--) {
            if ((int) $rows[$k]['depth'] < $depth) {
                $parent = $k;
                break;
            }
        }
        $start = $parent === null ? 0 : $parent + 1;
        $end = $parent === null ? count($rows) : MenuOutline::blockEnd($rows, $parent);

        return MenuOutline::place($rows, $i, $first ? $start : $end, $depth);
    }

    /** One row whose label fits the name: whole, then the start, then any part. */
    protected static function match(array $rows, string $name, callable $labelOf): ?int
    {
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return null;
        }
        foreach ([
            fn ($l) => $l === $name,
            fn ($l) => str_starts_with($l, $name),
            fn ($l) => str_contains($l, $name),
        ] as $fits) {
            $hits = [];
            foreach ($rows as $i => $row) {
                if ($fits(mb_strtolower($labelOf($row)))) {
                    $hits[] = $i;
                }
            }
            if (count($hits) === 1) {
                return $hits[0];
            }
            if (count($hits) > 1) {
                return null; // more than one fits: better to ask
            }
        }

        return null;
    }

    protected static function indexOfKey(array $rows, string $key): ?int
    {
        foreach ($rows as $i => $row) {
            if (($row['key'] ?? null) === $key) {
                return $i;
            }
        }

        return null;
    }

    protected static function unknown(string $said, string $what, array $names): string
    {
        $name = preg_match('/^⟦(\d+)⟧$/u', trim($what), $m) ? ($names[(int) $m[1]] ?? $what) : trim($what);

        return preg_match('/^(it|that|this)/i', $name)
            ? 'In “'.trim($said).'”, I couldn’t tell what “'.$name.'” means. Say its name.'
            : 'There’s no “'.$name.'” in this menu (or more than one thing fits). Say its name as it shows in the list.';
    }
}
