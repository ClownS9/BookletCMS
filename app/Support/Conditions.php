<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Show-this-field-when: conditions on blueprint fields, in Statamic's format.
 *
 *   button_link:
 *     type: link
 *     if: { has_button: true }               # shown when the toggle is on
 *   video_url:
 *     if: { kind: 'equals video' }           # when a select is "video"
 *   quiet_note:
 *     unless: { title: empty }               # hidden while the title is empty
 *
 * Keys: if / show_when (all must hold), if_any / show_when_any (one must),
 * unless / hide_when, unless_any / hide_when_any. Each condition is a value
 * (true, false, null) or "operator value": equals/is, not/isnt, contains,
 * contains_any, ===, !==, >, >=, <, <=, or just "empty" / "not empty".
 *
 * A field that's hidden isn't checked when saving and isn't kept (unless its
 * config says always_save: true), the way Statamic does it.
 */
class Conditions
{
    public const KEYS = [
        'if' => ['show', 'all'], 'show_when' => ['show', 'all'],
        'if_any' => ['show', 'any'], 'show_when_any' => ['show', 'any'],
        'unless' => ['hide', 'all'], 'hide_when' => ['hide', 'all'],
        'unless_any' => ['hide', 'any'], 'hide_when_any' => ['hide', 'any'],
    ];

    /** The simple kinds the Collections builder offers (one condition a field). */
    public const OPS = [
        'on' => 'is on', 'off' => 'is off',
        'is' => 'is', 'isnt' => 'isn’t',
        'filled' => 'is filled in', 'empty' => 'is empty',
        'contains' => 'contains',
    ];

    /** Does this field config have any conditions? */
    public static function has(array $config): bool
    {
        return array_intersect_key($config, self::KEYS) !== [];
    }

    /** The handles a field's conditions look at. */
    public static function watches(array $config): array
    {
        $handles = [];
        foreach (array_intersect_key($config, self::KEYS) as $set) {
            foreach (array_keys((array) $set) as $handle) {
                $handles[] = (string) $handle;
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * Is the field shown, given the other fields' values?
     *
     * @param  array<string, mixed>  $values  handle => value
     */
    public static function passes(array $config, array $values): bool
    {
        foreach (array_intersect_key($config, self::KEYS) as $key => $set) {
            [$kind, $mode] = self::KEYS[$key];
            $results = [];
            foreach ((array) $set as $handle => $condition) {
                $results[] = self::holds($values[$handle] ?? null, $condition);
            }
            if ($results === []) {
                continue;
            }
            $met = $mode === 'all' ? ! in_array(false, $results, true) : in_array(true, $results, true);
            if (($kind === 'show' && ! $met) || ($kind === 'hide' && $met)) {
                return false;
            }
        }

        return true;
    }

    /** One condition against one value. */
    public static function holds(mixed $value, mixed $condition): bool
    {
        if (is_bool($condition)) {
            return self::truthy($value) === $condition;
        }
        if ($condition === null) {
            return self::blank($value);
        }

        [$op, $right] = self::parse((string) $condition);

        return match ($op) {
            'empty' => self::blank($value),
            'not empty' => ! self::blank($value),
            'equals' => self::equals($value, $right),
            'not' => ! self::equals($value, $right),
            '===' => $value === self::literal($right),
            '!==' => $value !== self::literal($right),
            'contains' => self::contains($value, $right),
            'contains_any' => collect(explode(',', $right))->map(fn ($w) => trim($w))->filter()->contains(fn ($w) => self::contains($value, $w)),
            '>', '>=', '<', '<=' => is_numeric($value) && is_numeric($right) && match ($op) {
                '>' => $value + 0 > $right + 0, '>=' => $value + 0 >= $right + 0,
                '<' => $value + 0 < $right + 0, default => $value + 0 <= $right + 0,
            },
            default => self::equals($value, $right),
        };
    }

    /** "not empty" → ['not empty', '']; "equals video" → ['equals', 'video']; "video" → ['equals', 'video']. */
    public static function parse(string $condition): array
    {
        $condition = trim($condition);
        $lower = strtolower($condition);
        if (in_array($lower, ['empty', 'is empty', 'equals empty'], true)) {
            return ['empty', ''];
        }
        if (in_array($lower, ['not empty', 'isnt empty', "isn't empty", 'not equals empty'], true)) {
            return ['not empty', ''];
        }
        if (preg_match('/^(equals|is|==|not|isnt|isn\'t|!=|contains_any|contains|includes|===|!==|>=|<=|>|<)\s*(.*)$/is', $condition, $m)) {
            $op = strtolower($m[1]);
            $op = ['is' => 'equals', '==' => 'equals', 'isnt' => 'not', "isn't" => 'not', '!=' => 'not', 'includes' => 'contains'][$op] ?? $op;
            $right = trim($m[2]);
            if (in_array(strtolower($right), ['empty', 'null'], true) && in_array($op, ['equals', 'not'], true)) {
                return [$op === 'equals' ? 'empty' : 'not empty', ''];
            }

            return [$op, $right];
        }

        return ['equals', $condition];
    }

    protected static function literal(string $right): mixed
    {
        return match (strtolower($right)) {
            'true' => true, 'false' => false, 'null' => null,
            default => is_numeric($right) ? $right + 0 : $right,
        };
    }

    protected static function equals(mixed $value, string $right): bool
    {
        $literal = self::literal($right);
        if (is_bool($literal)) {
            return self::truthy($value) === $literal;
        }
        if ($literal === null) {
            return self::blank($value);
        }
        if (is_array($value)) {
            return collect($value)->flatten()->contains(fn ($v) => is_scalar($v) && mb_strtolower((string) $v) === mb_strtolower($right));
        }

        return is_scalar($value) && mb_strtolower(trim((string) $value)) === mb_strtolower($right);
    }

    protected static function contains(mixed $value, string $right): bool
    {
        if ($right === '') {
            return false;
        }
        if (is_array($value)) {
            return collect($value)->flatten()->contains(fn ($v) => is_scalar($v) && str_contains(mb_strtolower((string) $v), mb_strtolower($right)));
        }

        return is_scalar($value) && str_contains(mb_strtolower((string) $value), mb_strtolower($right));
    }

    protected static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false || (is_string($value) && trim($value) === '');
    }

    protected static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return ! self::blank($value) && $value !== 0;
    }

    // The builder's simple form ---------------------------------------------------------------

    /**
     * A field's condition as the builder shows it, when it's a simple one:
     * ['mode' => if|unless, 'field' => handle, 'op' => on|off|is|isnt|filled|empty|contains, 'value' => string].
     * Null when there's none, false when it's more than the builder can show.
     */
    public static function simple(array $config): array|false|null
    {
        $sets = array_intersect_key($config, self::KEYS);
        if ($sets === []) {
            return null;
        }
        if (count($sets) > 1) {
            return false;
        }
        $key = array_key_first($sets);
        $set = (array) $sets[$key];
        if (count($set) !== 1 || ! in_array($key, ['if', 'show_when', 'unless', 'hide_when'], true)) {
            return false;
        }
        $handle = (string) array_key_first($set);
        $condition = $set[$handle];
        $mode = self::KEYS[$key][0] === 'show' ? 'if' : 'unless';

        if (is_bool($condition)) {
            return ['mode' => $mode, 'field' => $handle, 'op' => $condition ? 'on' : 'off', 'value' => ''];
        }
        if ($condition === null) {
            return ['mode' => $mode, 'field' => $handle, 'op' => 'empty', 'value' => ''];
        }
        [$op, $right] = self::parse((string) $condition);
        $op = ['equals' => 'is', 'not' => 'isnt', 'empty' => 'empty', 'not empty' => 'filled', 'contains' => 'contains'][$op] ?? null;
        if ($op === null) {
            return false;
        }
        if (in_array($op, ['is', 'isnt'], true) && in_array(strtolower($right), ['true', 'false'], true)) {
            $on = (strtolower($right) === 'true') === ($op === 'is');

            return ['mode' => $mode, 'field' => $handle, 'op' => $on ? 'on' : 'off', 'value' => ''];
        }

        return ['mode' => $mode, 'field' => $handle, 'op' => $op, 'value' => $right];
    }

    /** The builder's simple form, back into config keys (['if' => [handle => condition]]). */
    public static function fromSimple(array $when): array
    {
        $field = (string) ($when['field'] ?? '');
        $op = (string) ($when['op'] ?? '');
        if ($field === '' || ! array_key_exists($op, self::OPS)) {
            return [];
        }
        $value = trim((string) ($when['value'] ?? ''));
        $condition = match ($op) {
            'on' => true,
            'off' => false,
            'is' => 'equals '.$value,
            'isnt' => 'not '.$value,
            'filled' => 'not empty',
            'empty' => 'empty',
            'contains' => 'contains '.$value,
        };
        if (in_array($op, ['is', 'isnt', 'contains'], true) && $value === '') {
            return [];
        }

        return [($when['mode'] ?? 'if') === 'unless' ? 'unless' : 'if' => [$field => $condition]];
    }

    /**
     * In words, for the builder and the editor: "Shown when Has button is on".
     *
     * @param  array<string, array{display: string, options?: array}>  $fields
     */
    public static function describe(array $config, array $fields = []): ?string
    {
        $simple = self::simple($config);
        if ($simple === null) {
            return null;
        }
        if ($simple === false) {
            return 'Shown only sometimes (set in its blueprint file)';
        }
        $name = $fields[$simple['field']]['display'] ?? Str::headline($simple['field']);
        $label = $simple['value'];
        $options = (array) ($fields[$simple['field']]['options'] ?? []);
        if (in_array($simple['op'], ['is', 'isnt'], true) && isset($options[$label])) {
            $label = (string) $options[$label];
        }
        $what = match ($simple['op']) {
            'on' => 'is on', 'off' => 'is off', 'filled' => 'is filled in', 'empty' => 'is empty',
            'is' => 'is “'.$label.'”', 'isnt' => 'isn’t “'.$label.'”', 'contains' => 'contains “'.$label.'”',
            default => 'is set',
        };

        return ($simple['mode'] === 'unless' ? 'Hidden when ' : 'Shown when ').$name.' '.$what;
    }

    // Plain words ------------------------------------------------------------------------------

    /**
     * "only when the button toggle is on", "hide it while Kind is video",
     * "when Price is filled in" → the builder's simple form, or null when the
     * words don't name one of the fields clearly enough.
     *
     * @param  array<string, array{display: string, type: string, options?: array}>  $fields  the other fields
     */
    public static function fromWords(string $words, array $fields): ?array
    {
        $text = ' '.mb_strtolower(trim(preg_replace('/\s+/', ' ', $words))).' ';
        if (trim($text) === '') {
            return null;
        }

        // The field it's about: the longest name (or handle) that appears.
        $found = null;
        $length = 0;
        foreach ($fields as $handle => $field) {
            foreach (array_filter([mb_strtolower((string) $field['display']), str_replace('_', ' ', mb_strtolower((string) $handle))]) as $name) {
                if (mb_strlen($name) > $length && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text)) {
                    [$found, $length] = [$handle, mb_strlen($name)];
                }
            }
        }
        if ($found === null) {
            return null;
        }

        $field = $fields[$found];
        $mode = preg_match('/\b(hide|hidden|hides|not show|don.?t show|do not show)\b/u', $text) ? 'unless' : 'if';
        $negative = (bool) preg_match('/\b(not|isn.?t|isnt|aren.?t|doesn.?t|except|other than|unless)\b/u', $text);

        if ($field['type'] === 'toggle') {
            $off = preg_match('/\b(off|unchecked|unticked|false|disabled|turned off|switched off)\b/u', $text) || ($negative && ! preg_match('/\boff\b/u', $text));

            return ['mode' => $mode, 'field' => $found, 'op' => $off ? 'off' : 'on', 'value' => ''];
        }

        $options = (array) ($field['options'] ?? []);
        foreach ($options as $key => $label) {
            foreach ([mb_strtolower((string) $label), mb_strtolower((string) $key)] as $name) {
                if ($name !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', str_replace(mb_strtolower((string) $field['display']), ' ', $text))) {
                    return ['mode' => $mode, 'field' => $found, 'op' => $negative ? 'isnt' : 'is', 'value' => (string) $key];
                }
            }
        }

        if (preg_match('/\b(contains|includes|mentions|has the word|says)\s+[“"\']?([^”"\']+?)[”"\']?\s*$/u', trim($text), $m)) {
            return ['mode' => $mode, 'field' => $found, 'op' => 'contains', 'value' => trim($m[2])];
        }
        if (preg_match('/\b(empty|blank|left out|missing|nothing in)\b/u', $text)) {
            return ['mode' => $mode, 'field' => $found, 'op' => $negative ? 'filled' : 'empty', 'value' => ''];
        }

        return ['mode' => $mode, 'field' => $found, 'op' => $negative ? 'empty' : 'filled', 'value' => ''];
    }
}
