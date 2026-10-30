<?php

namespace App\Support;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Site;
use Illuminate\Support\Str;

/**
 * How an entry shows up outside the site: as a Google result, and as a card
 * when its link is shared (Facebook, WhatsApp, Slack…). Plus gentle notes on
 * what would make it better: a title that gets cut off, no description of
 * its own, two pages with the same title, pictures with no description.
 *
 * Works on unsaved entries too, so the editor can show it while typing.
 */
class SearchCard
{
    /** Roughly what Google shows before cutting with "…" (it measures in pixels; letters are close enough). */
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 160;

    public const DESCRIPTION_MIN = 50;

    /**
     * @param  Entry  $entry  the entry as it would be saved (values may be unsaved)
     * @param  string  $url  where it lives (or will)
     * @return array{google: array, share: array, counts: array, hints: list<array{level: string, text: string}>}
     */
    public static function for(Entry $entry, string $url): array
    {
        $site = app(Site::class);
        $own = trim((string) $entry->get('meta_title', ''));
        $title = $own !== '' ? $own : trim((string) $entry->title());
        $tag = ($title !== '' ? $title.' — ' : '').$site->name(); // what the page's <title> says
        $ownDescription = trim((string) $entry->get('meta_description', ''));
        $description = $ownDescription !== '' ? $ownDescription : trim(rescue(fn () => $site->excerpt($entry, 160), '', false));
        $absolute = $site->absolute($url);
        $host = (string) parse_url($absolute, PHP_URL_HOST);
        $path = trim((string) parse_url($absolute, PHP_URL_PATH), '/');

        $picture = rescue(fn () => $entry->id() ? ($site->shareImage($entry) ?? ShareImage::urlFor($entry)) : $site->shareImage($entry), null, false);
        $drawn = $picture !== null && str_contains($picture, '/'.ShareImage::FOLDER.'/');

        $hints = [];
        if ($title === '') {
            $hints[] = ['level' => 'warn', 'text' => __('No title yet, so search shows only the site’s name.')];
        } elseif (mb_strlen($tag) > self::TITLE_MAX) {
            $hints[] = ['level' => 'warn', 'text' => __('Google shows about :max letters of a title, and this one is :length (with the site’s name), so the end gets cut off. Write a shorter search title.', ['max' => self::TITLE_MAX, 'length' => mb_strlen($tag)])];
        }
        if ($ownDescription === '') {
            $hints[] = ['level' => 'note', 'text' => $entry->get('excerpt') ? __('No search description of its own, so search and shares use the first lines of the standfirst. Write one (about 150 letters) to choose what they say.') : __('No search description of its own, so search and shares use the first lines. Write one (about 150 letters) to choose what they say.')];
        } elseif (mb_strlen($ownDescription) > self::DESCRIPTION_MAX) {
            $hints[] = ['level' => 'warn', 'text' => __('The search description is :length letters; Google cuts it at about :max.', ['length' => mb_strlen($ownDescription), 'max' => self::DESCRIPTION_MAX])];
        } elseif (mb_strlen($ownDescription) < self::DESCRIPTION_MIN) {
            $hints[] = ['level' => 'note', 'text' => __('The search description is short. Google often swaps a short one for a line from the text.')];
        }
        if ($title !== '' && ($twin = self::sameTitle($entry, $title))) {
            $hints[] = ['level' => 'warn', 'text' => __('“:title” has the same title in search. Search engines may treat the two as one page, so give each its own.', ['title' => Str::limit((string) $twin->title(), 60)]), 'href' => route('admin.entries.edit', [$twin->collectionHandle(), $twin->id()])];
        }
        $bare = self::picturesWithoutDescriptions($entry);
        if ($bare > 0) {
            $hints[] = ['level' => 'warn', 'text' => trans_choice('A picture has no description (alt text). Screen readers can’t say what’s in it, and search can’t either. Add one in Media.|:count pictures have no description (alt text). Screen readers can’t say what’s in them, and search can’t either. Add them in Media.', $bare)];
        }
        if (! $picture) {
            $hints[] = ['level' => 'note', 'text' => __('No picture for shares, so they show as plain text. Add a cover picture.')];
        } elseif ($drawn) {
            $hints[] = ['level' => 'note', 'text' => __('No cover picture, so shares get a card Booklet draws with the title. A photo usually gets more clicks.')];
        }

        return [
            'google' => [
                'site' => $site->name(),
                'host' => $host,
                'crumbs' => $host.($path !== '' ? ' › '.implode(' › ', array_map(fn ($p) => Str::limit($p, 24, '…'), explode('/', $path))) : ''),
                'title' => Str::limit($tag, self::TITLE_MAX, ' …'),
                'description' => Str::limit($description, self::DESCRIPTION_MAX, ' …'),
                'date' => $entry->collection()?->dated() && $entry->date() ? $entry->date()->format('j M Y') : null,
                'icon' => $site->logoUrl(),
            ],
            'share' => [
                'image' => $picture ? $site->absolute($picture) : null,
                'drawn' => $drawn,
                'host' => Str::upper($host),
                'title' => $title !== '' ? $title : $site->name(),
                'description' => Str::limit($description, 110, '…'),
            ],
            'counts' => [
                'title' => mb_strlen($tag),
                'own_title' => mb_strlen($own),
                'description' => mb_strlen($ownDescription),
            ],
            'hints' => $hints,
        ];
    }

    /** Another entry whose search title is the same (ignoring case), if any. Its own translations don't count. */
    public static function sameTitle(Entry $entry, string $title): ?Entry
    {
        $title = mb_strtolower(trim($title));
        $family = array_filter([$entry->id(), (string) $entry->get('translation_of')]);

        return Booklet::entries()->all()->first(function (Entry $other) use ($title, $family) {
            if (in_array($other->id(), $family, true) || in_array((string) $other->get('translation_of'), $family, true)) {
                return false;
            }
            $theirs = trim((string) $other->get('meta_title', '')) ?: trim((string) $other->title());

            return $theirs !== '' && mb_strtolower($theirs) === $title;
        });
    }

    /** Pictures in the entry (cover, share picture, cut-outs, pictures in the text) with no alt text. */
    public static function picturesWithoutDescriptions(Entry $entry): int
    {
        $paths = [];
        foreach (['cover', 'share_image'] as $field) {
            foreach ((array) $entry->get($field, []) as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }
        // Cut-outs: any "picture" or "pictures" value inside them.
        $walk = function ($value, ?string $key = null) use (&$walk, &$paths): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, is_string($k) ? $k : $key);
                }
            } elseif (is_string($value) && in_array($key, ['picture', 'pictures'], true) && $value !== '') {
                $paths[] = $value;
            }
        };
        $walk((array) $entry->get('blocks', []));

        $bare = 0;
        foreach (array_unique($paths) as $path) {
            $alt = rescue(fn () => Booklet::assets()->find(['container' => 'media', 'path' => $path])?->get('alt'), null, false);
            if (trim((string) $alt) === '') {
                $bare++;
            }
        }

        // ![](picture.jpg) in the text: an empty description between the brackets.
        $bare += preg_match_all('/!\[\s*\]\(/', (string) $entry->get('content', ''));

        return $bare;
    }
}
