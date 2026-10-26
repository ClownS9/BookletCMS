<?php

namespace App\Stacy;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\PageTree;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Translated copies of an entry. A translation is its own draft in the same
 * chapter ("spring-recipes-ms"), with every picture and setting carried over
 * and its words in the other language. It remembers which entry it came
 * from ("translation_of") and its language ("lang"), so each one can link to
 * the others. Nothing goes live until someone publishes it.
 */
class Translations
{
    /** The entry everything was translated from. */
    public static function root(Entry $entry): Entry
    {
        $from = (string) $entry->get('translation_of', '');

        return $from !== '' ? (Booklet::entries()->find($from) ?? $entry) : $entry;
    }

    /**
     * The original and every translation of it, original first.
     *
     * @return Collection<int, Entry>
     */
    public static function family(Entry $entry): Collection
    {
        $root = self::root($entry);

        return Booklet::entries()->all()
            ->filter(fn (Entry $e) => $e->id() === $root->id() || (string) $e->get('translation_of') === (string) $root->id())
            ->sortBy(fn (Entry $e) => [$e->id() === $root->id() ? 0 : 1, (string) $e->get('lang')])
            ->values();
    }

    /** Already translated into this language? */
    public static function existing(Entry $entry, string $lang): ?Entry
    {
        return self::family($entry)->first(fn (Entry $e) => $e->id() !== self::root($entry)->id() && $e->get('lang') === $lang);
    }

    public static function canTranslate(User $user, Entry $entry): bool
    {
        // Anyone who may start drafts may make one, from anything they can read.
        return StacyConfig::allowsAction('translate') && Writer::available($user);
    }

    /**
     * Make the translated draft. Long posts take a little while (several requests).
     */
    public static function make(User $user, Entry $entry, string $lang): Entry
    {
        Writer::languageName($lang); // throws for a language we don't offer
        @set_time_limit(600);
        $root = self::root($entry);

        if ($root->get('lang') === $lang) {
            throw new StacyException('That’s the language it was written in.');
        }
        if ($already = self::existing($entry, $lang)) {
            throw new StacyException('There’s already a '.Writer::LANGUAGES[$lang][0].' version: “'.($already->title() ?: 'Untitled').'”.');
        }

        $words = Writer::translateEntry($user, $root, $lang);
        // Built on the page: its sections come too, in the other language.
        $pages = app(\App\PageBuilder\PageBuilder::class);
        $built = $pages->buildable($root) ? \App\Models\PageBuild::forEntry($root) : null;
        $sections = $built ? $pages->translateSections($user, $built, $lang, (string) $root->title()) : null;

        $data = array_merge($root->data(), $words, [
            'translation_of' => $root->id(),
            'lang' => $lang,
            'author' => $user->id,
        ]);
        unset($data['updated_by']);

        $copy = Booklet::entries()->make(
            ['collection' => $root->collectionHandle(), 'published' => false],
            array_filter($data, fn ($v) => $v !== null),
        );
        $copy->setAttribute('slug', Str::limit((string) ($root->slug() ?: Str::slug($root->title())), 180, '').'-'.$lang)
            ->setAttribute('blueprint', $root->attribute('blueprint'))
            ->setAttribute('date', $root->date());
        $copy->save();

        // A page sits beside its original in the tree.
        $collection = $root->collection();
        if ($collection?->structured()) {
            $tree = PageTree::for($collection->handle());
            $tree->place($copy->id(), $tree->parentOf($root->id()))->save();
            $copy = Booklet::entries()->find($copy->id()) ?? $copy;
        }

        if ($built && $sections !== null) {
            $pages->buildTranslation($built, $copy, $sections);
        }

        Booklet::revisions()->snapshot($copy->revisionKey(), $copy, 'started a draft', (string) $user->id, 'Translated by Stacy from “'.($root->title() ?: 'Untitled').'”');
        ActivityLog::create([
            'user_id' => $user->id,
            'actor' => 'stacy',
            'action' => 'translated into '.Writer::LANGUAGES[$lang][0],
            'kind' => 'edits',
            'subject_type' => 'entry',
            'subject_key' => $copy->id(),
            'subject_label' => $copy->title(),
            'properties' => ['for' => $user->name, 'from' => $root->id()],
        ]);

        return $copy;
    }
}
