<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\PageTree;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Print as a book": which entries go in, in what order, and how the pages
 * are cut. Shared by the options page and the typeset view.
 */
class PrintBook
{
    /** Most entries in one book (a browser lays out every page, so keep it sane). */
    public const LIMIT = 400;

    /**
     * key => [name, page width, page height, words on a full page (for estimates)]
     */
    public const SIZES = [
        'a5' => ['A5 · 148 × 210 mm', '148mm', '210mm', 300],
        'trade' => ['Paperback · 6 × 9 in', '152.4mm', '228.6mm', 340],
        'a4' => ['A4 · 210 × 297 mm', '210mm', '297mm', 560],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $site = app(\App\Booklet\Site::class);

        return [
            'collection' => Booklet::collections()->find('posts') ? 'posts' : (string) Booklet::collections()->all()->first()?->handle(),
            'from' => '',
            'to' => '',
            'tag' => '',
            'author' => '',
            'order' => 'oldest',
            'size' => 'a5',
            'covers' => true,
            'recto' => true,
            'toc' => true,
            'title' => $site->name(),
            'subtitle' => (string) $site->tagline(),
            'byline' => '',
            'lang' => '',
        ];
    }

    /**
     * Options from a form or a query string, tidied and checked.
     *
     * @return array<string, mixed>
     */
    public static function normalise(array $in): array
    {
        $o = self::defaults();
        $handles = Booklet::collections()->all()->map->handle()->all();
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : '';
        $text = fn ($v, $max) => mb_substr(trim((string) $v), 0, $max);
        $flag = fn ($v, $default) => $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);

        return [
            'collection' => in_array($in['collection'] ?? null, $handles, true) ? $in['collection'] : $o['collection'],
            'from' => $date($in['from'] ?? ''),
            'to' => $date($in['to'] ?? ''),
            'tag' => preg_match('/^[\w-]{1,120}$/u', (string) ($in['tag'] ?? '')) ? (string) $in['tag'] : '',
            'author' => ctype_digit((string) ($in['author'] ?? '')) ? (string) $in['author'] : '',
            'order' => ($in['order'] ?? '') === 'newest' ? 'newest' : 'oldest',
            'size' => array_key_exists($in['size'] ?? '', self::SIZES) ? $in['size'] : 'a5',
            'covers' => $flag($in['covers'] ?? null, true),
            'recto' => $flag($in['recto'] ?? null, true),
            'toc' => $flag($in['toc'] ?? null, true),
            'title' => $text($in['title'] ?? '', 120) ?: $o['title'],
            'subtitle' => $text($in['subtitle'] ?? $o['subtitle'], 200),
            'byline' => $text($in['byline'] ?? '', 160),
            'lang' => isset(\App\Stacy\Writer::LANGUAGES[$in['lang'] ?? '']) ? (string) $in['lang'] : '',
        ];
    }

    /**
     * The entries that go in, in reading order (published ones only).
     *
     * @return Collection<int, Entry>
     */
    public static function entries(array $o, bool $limit = true): Collection
    {
        $collection = Booklet::collections()->find($o['collection']);
        if (! $collection) {
            return collect();
        }

        $from = $o['from'] ? Carbon::parse($o['from'])->startOfDay() : null;
        $to = $o['to'] ? Carbon::parse($o['to'])->endOfDay() : null;

        $entries = Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->collectionHandle() === $collection->handle()
                && $e->status() === 'published'
                && $e->site() === config('booklet.default_site'))
            ->filter(fn (Entry $e) => ! $from || ($e->date() && $e->date()->gte($from)))
            ->filter(fn (Entry $e) => ! $to || ($e->date() && $e->date()->lte($to)))
            ->filter(fn (Entry $e) => $o['tag'] === '' || in_array($o['tag'], (array) $e->get('tags', []), true))
            ->filter(fn (Entry $e) => $o['author'] === '' || (string) $e->get('author') === $o['author'])
            // The originals, or the translations in one language ("the Malay edition").
            ->filter(fn (Entry $e) => ($o['lang'] ?? '') === ''
                ? (string) $e->get('translation_of', '') === ''
                : (string) $e->get('translation_of', '') !== '' && $e->get('lang') === $o['lang']);

        if ($collection->dated()) {
            $entries = $entries->sortBy(fn (Entry $e) => $e->date()?->timestamp ?? 0);
        } elseif ($collection->structured()) {
            $order = array_flip(rescue(fn () => PageTree::for($collection->handle())->ids(), [], false) ?: []);
            $entries = $entries->sortBy(fn (Entry $e) => [$order[$e->id()] ?? PHP_INT_MAX, $e->title()]);
        } else {
            $entries = $entries->sortBy(fn (Entry $e) => mb_strtolower($e->title()));
        }

        if ($o['order'] === 'newest') {
            $entries = $entries->reverse();
        }

        return $limit ? $entries->values()->take(self::LIMIT) : $entries->values();
    }

    /** Everything that matches, before the limit (to say when some were left out). */
    public static function total(array $o): int
    {
        return rescue(fn () => self::entries($o, false)->count(), 0, false);
    }

    /**
     * A rough page count: front matter, then each entry from a fresh page.
     */
    public static function estimatePages(Collection $entries, array $o): int
    {
        $perPage = self::SIZES[$o['size']][3];
        $pages = 2 + ($o['toc'] ? max(1, (int) ceil($entries->count() / 22)) : 0);

        foreach ($entries as $entry) {
            $words = Milestones::words($entry->get('content'));
            $pictures = $o['covers'] && $entry->get('cover') ? 0.4 : 0;
            $n = max(1, (int) ceil(($words + $perPage * (0.35 + $pictures)) / $perPage));
            $pages += $o['recto'] && $pages % 2 === 1 ? $n + 1 : $n;
        }

        return $pages;
    }

    /** "Nur Aisyah and Hana" from the people who wrote these. */
    public static function authorsOf(Collection $entries): string
    {
        $ids = $entries->map(fn (Entry $e) => $e->get('author'))->filter()->unique();
        $names = User::whereIn('id', $ids)->get()->map(fn (User $u) => $u->byline ?: $u->name)->values();

        return match ($names->count()) {
            0 => '',
            1 => $names[0],
            2 => $names[0].' and '.$names[1],
            default => $names->slice(0, -1)->implode(', ').' and '.$names->last(),
        };
    }

    /**
     * Languages there are published translations in, for this chapter: code => name.
     *
     * @return array<string, string>
     */
    public static function languagesIn(string $collection): array
    {
        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->collectionHandle() === $collection && $e->status() === 'published' && (string) $e->get('translation_of', '') !== '')
            ->map(fn (Entry $e) => (string) $e->get('lang'))
            ->filter(fn ($code) => isset(\App\Stacy\Writer::LANGUAGES[$code]))
            ->unique()
            ->mapWithKeys(fn ($code) => [$code => \App\Stacy\Writer::LANGUAGES[$code][1]])
            ->sort()
            ->all();
    }

    /** The query string for the typeset view. */
    public static function query(array $o): array
    {
        return array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v, $o);
    }
}
