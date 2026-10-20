<?php

namespace App\Booklet\Support;

use Illuminate\Support\Str;

/**
 * A menu while it's being arranged: the saved tree (items with 'children')
 * laid out as an outline, one row per item with its depth. Moving a row
 * always moves its children with it, nothing can be dropped inside itself,
 * and a menu is never deeper than three levels (top, below, below that).
 *
 *   rows: [['key' => 'a1b2c3', 'depth' => 0, 'entry' => '…'], ['key' => …, 'depth' => 1, 'url' => '/shop', 'title' => 'Shop'], …]
 */
class MenuOutline
{
    /** Deepest level: 0 is the top bar, 1 drops down from it, 2 is tucked under that. */
    public const MAX_DEPTH = 2;

    /** @return list<array<string, mixed>> */
    public static function fromTree(array $tree, int $depth = 0): array
    {
        $rows = [];
        foreach ($tree as $branch) {
            if (! is_array($branch) || (! isset($branch['entry']) && ! isset($branch['url']))) {
                continue;
            }
            $children = (array) ($branch['children'] ?? []);
            unset($branch['children']);
            $rows[] = ['key' => Str::lower(Str::random(8)), 'depth' => min($depth, self::MAX_DEPTH)] + $branch;
            array_push($rows, ...self::fromTree($children, $depth + 1));
        }

        return $depth === 0 ? self::normalize($rows) : $rows;
    }

    /** The outline back into the saved shape (nested 'children'), without the editor's own keys. */
    public static function toTree(array $rows): array
    {
        $rows = self::normalize($rows);
        $root = [];
        $stack = [&$root];

        foreach ($rows as $row) {
            $depth = (int) $row['depth'];
            $item = array_diff_key($row, ['key' => true, 'depth' => true]);
            $item = array_filter($item, fn ($v) => $v !== null && $v !== '' && $v !== false);

            // Climb back up to this row's level.
            $stack = array_slice($stack, 0, $depth + 1, true);
            $parent = &$stack[$depth];
            $parent[] = $item;
            $last = array_key_last($parent);
            $parent[$last]['children'] ??= [];
            $stack[$depth + 1] = &$parent[$last]['children'];
            unset($parent);
        }

        return self::dropEmptyChildren($root);
    }

    protected static function dropEmptyChildren(array $items): array
    {
        foreach ($items as $i => $item) {
            if (empty($item['children'])) {
                unset($items[$i]['children']);
            } else {
                $items[$i]['children'] = self::dropEmptyChildren($item['children']);
            }
        }

        return array_values($items);
    }

    /** Each row at most one level deeper than the one above, none deeper than allowed, the first at the top. */
    public static function normalize(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        $prev = -1;
        foreach ($rows as $i => $row) {
            $depth = max(0, min((int) ($row['depth'] ?? 0), $prev + 1, self::MAX_DEPTH));
            $rows[$i]['depth'] = $depth;
            $rows[$i]['key'] = is_string($row['key'] ?? null) && $row['key'] !== '' ? $row['key'] : Str::lower(Str::random(8));
            $prev = $depth;
        }

        return $rows;
    }

    /** Where a row's branch ends (exclusive): the row and everything nested under it. */
    public static function blockEnd(array $rows, int $i): int
    {
        $depth = (int) $rows[$i]['depth'];
        $j = $i + 1;
        while (isset($rows[$j]) && (int) $rows[$j]['depth'] > $depth) {
            $j++;
        }

        return $j;
    }

    /**
     * Move a row (with everything under it) to just before row $before (or
     * the end), at $depth, as near to it as the rows around allow.
     */
    public static function place(array $rows, int $from, int $before, int $depth): array
    {
        $rows = self::normalize($rows);
        if (! isset($rows[$from])) {
            return $rows;
        }
        $end = self::blockEnd($rows, $from);
        if ($before > $from && $before < $end) {
            return $rows; // not inside itself
        }

        $block = array_slice($rows, $from, $end - $from);
        $rest = array_merge(array_slice($rows, 0, $from), array_slice($rows, $end));
        $at = $before >= $end ? $before - ($end - $from) : ($before <= $from ? $before : $from);
        $at = max(0, min($at, count($rest)));

        $base = (int) $block[0]['depth'];
        $height = max(array_map(fn ($r) => (int) $r['depth'], $block)) - $base;
        $above = $rest[$at - 1] ?? null;
        $deepest = min($above ? (int) $above['depth'] + 1 : 0, self::MAX_DEPTH - $height);
        $depth = max(0, min($depth, $deepest));

        $shift = $depth - $base;
        foreach ($block as $k => $row) {
            $block[$k]['depth'] = (int) $row['depth'] + $shift;
        }

        // Rows that were nested under the row above stay with it, not with the moved branch.
        $after = array_slice($rest, $at);
        $moved = array_merge(array_slice($rest, 0, $at), $block, $after);

        return self::normalize($moved);
    }

    /** Tuck a row under the one above it (as its last child). */
    public static function indent(array $rows, int $i): array
    {
        if ($i <= 0 || ! isset($rows[$i])) {
            return $rows;
        }

        return self::place($rows, $i, $i, (int) $rows[$i]['depth'] + 1);
    }

    /** Lift a row out a level: it comes after the branch it was in. */
    public static function outdent(array $rows, int $i): array
    {
        if (! isset($rows[$i]) || (int) $rows[$i]['depth'] === 0) {
            return $rows;
        }
        $depth = (int) $rows[$i]['depth'];
        $parent = $i - 1;
        while ($parent > 0 && (int) $rows[$parent]['depth'] >= $depth) {
            $parent--;
        }

        return self::place($rows, $i, self::blockEnd($rows, $parent), $depth - 1);
    }

    /** Swap with the sibling above (same level, same parent). */
    public static function up(array $rows, int $i): array
    {
        if (! isset($rows[$i])) {
            return $rows;
        }
        $depth = (int) $rows[$i]['depth'];
        for ($j = $i - 1; $j >= 0; $j--) {
            $d = (int) $rows[$j]['depth'];
            if ($d < $depth) {
                return $rows; // first under its parent
            }
            if ($d === $depth) {
                return self::place($rows, $i, $j, $depth);
            }
        }

        return $rows;
    }

    /** Swap with the sibling below. */
    public static function down(array $rows, int $i): array
    {
        if (! isset($rows[$i])) {
            return $rows;
        }
        $depth = (int) $rows[$i]['depth'];
        $next = self::blockEnd($rows, $i);
        if (! isset($rows[$next]) || (int) $rows[$next]['depth'] !== $depth) {
            return $rows; // last under its parent
        }

        return self::place($rows, $i, self::blockEnd($rows, $next), $depth);
    }

    /** Take a row out; whatever was under it moves up a level, where it was. */
    public static function remove(array $rows, int $i): array
    {
        if (! isset($rows[$i])) {
            return $rows;
        }
        $end = self::blockEnd($rows, $i);
        for ($j = $i + 1; $j < $end; $j++) {
            $rows[$j]['depth'] = (int) $rows[$j]['depth'] - 1;
        }
        unset($rows[$i]);

        return self::normalize($rows);
    }

    /** Is this row first or last among its siblings? (For greying out the arrows.) */
    public static function isFirst(array $rows, int $i): bool
    {
        return self::up($rows, $i) === self::normalize($rows);
    }

    public static function isLast(array $rows, int $i): bool
    {
        return self::down($rows, $i) === self::normalize($rows);
    }
}
