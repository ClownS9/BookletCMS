<?php

namespace App\Livewire\Admin;

use App\Booklet\Data\Entry;
use App\Booklet\Data\Term;
use App\Booklet\Facades\Booklet;
use App\Livewire\Concerns\Stamps;
use App\Models\ActivityLog;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The index at the back of the book: categories on the left, tags as
 * stickers on the right (bigger means used more). Pick one to rename it,
 * merge it into another, or remove it from every post.
 */
#[Layout('components.layouts.admin')]
#[Title('Categories & tags')]
class Taxonomies extends Component
{
    use Stamps;

    #[Url(as: 'in', except: 'tags')]
    public string $taxonomy = 'tags';

    #[Url(as: 'term', except: null)]
    public ?string $selected = null;

    public string $newCategory = '';

    public string $newTag = '';

    public string $rename = '';

    public string $mergeInto = '';

    public function mount(): void
    {
        if (! Booklet::taxonomies()->find($this->taxonomy)) {
            $this->taxonomy = 'tags';
        }
    }

    protected function canManage(): bool
    {
        return in_array(request()->user()->level(), ['admin', 'editor'], true);
    }

    /**
     * How many entries use each term, keyed "taxonomy:slug".
     */
    protected function usage(): array
    {
        $counts = [];

        foreach (Booklet::entries()->all() as $entry) {
            foreach (['categories', 'tags'] as $taxonomy) {
                foreach ((array) $entry->get($taxonomy, []) as $slug) {
                    $key = $taxonomy.':'.$slug;
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    public function choose(string $taxonomy, string $slug): void
    {
        $this->taxonomy = $taxonomy;
        $this->selected = $slug;
        $this->rename = '';
        $this->mergeInto = '';
        $this->resetValidation();
    }

    public function addCategory(): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate(['newCategory' => ['required', 'string', 'max:80']], [], ['newCategory' => 'category']);
        $slug = Str::slug($this->newCategory);

        if ($slug === '' || Booklet::terms()->findBySlug('categories', $slug)) {
            $this->addError('newCategory', __('There’s already a category called that.'));

            return;
        }

        Booklet::terms()->make(['taxonomy' => 'categories', 'slug' => $slug], ['title' => trim($this->newCategory)])->save();
        ActivityLog::record('added the category', 'settings', 'term', 'categories/'.$slug, trim($this->newCategory));

        $this->newCategory = '';
        $this->stamp('Category added.');
    }

    public function addTag(): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate(['newTag' => ['required', 'string', 'max:80']], [], ['newTag' => 'tag']);
        $title = trim(ltrim(trim($this->newTag), '#'));
        $slug = Str::slug($title);

        if ($slug === '') {
            $this->addError('newTag', __('Use some letters or numbers.'));

            return;
        }
        if (Booklet::terms()->findBySlug('tags', $slug)) {
            $this->addError('newTag', __('There’s already a tag called that.'));

            return;
        }

        Booklet::terms()->make(['taxonomy' => 'tags', 'slug' => $slug], ['title' => $title])->save();
        ActivityLog::record('added the tag', 'settings', 'term', 'tags/'.$slug, $title);

        $this->newTag = '';
        $this->choose('tags', $slug);
        $this->stamp('Tag added. Choose it on any post.');
    }

    protected function chosenTerm(): Term
    {
        $term = $this->selected ? Booklet::terms()->findBySlug($this->taxonomy, $this->selected) : null;
        abort_unless($term !== null, 404);

        return $term;
    }

    /**
     * Swap one slug for another (or drop it) on every entry that uses it.
     */
    protected function retag(string $taxonomy, string $from, ?string $to): int
    {
        $changed = 0;

        foreach (Booklet::entries()->all() as $entry) {
            /** @var Entry $entry */
            $slugs = (array) $entry->get($taxonomy, []);
            if (! in_array($from, $slugs, true)) {
                continue;
            }

            $slugs = array_values(array_unique(array_filter(array_map(fn ($s) => $s === $from ? $to : $s, $slugs))));
            $slugs === [] ? $entry->remove($taxonomy) : $entry->set($taxonomy, $slugs);
            $entry->save();
            $changed++;
        }

        return $changed;
    }

    public function renameTerm(): void
    {
        abort_unless($this->canManage(), 403);
        $term = $this->chosenTerm();

        $this->validate(['rename' => ['required', 'string', 'max:80']], [], ['rename' => 'new name']);
        $title = trim($this->rename);
        $slug = Str::slug($title);

        if ($slug === '') {
            $this->addError('rename', __('Use some letters or numbers.'));

            return;
        }

        if ($slug !== $term->slug()) {
            if (Booklet::terms()->findBySlug($this->taxonomy, $slug)) {
                $this->addError('rename', __('That name is taken. Use “Merge into” to combine them.'));

                return;
            }

            Booklet::terms()->make(['taxonomy' => $this->taxonomy, 'slug' => $slug], array_merge($term->data(), ['title' => $title]))->save();
            $this->retag($this->taxonomy, $term->slug(), $slug);
            Booklet::terms()->delete($term);
        } else {
            $term->set('title', $title)->save();
        }

        ActivityLog::record('renamed', 'settings', 'term', $this->taxonomy.'/'.$slug, $term->title().' → '.$title);
        $this->selected = $slug;
        $this->rename = '';
        $this->stamp('Renamed everywhere it’s used.');
    }

    public function mergeTerm(): void
    {
        $this->resetErrorBag(); // clear the last try's message

        abort_unless($this->canManage(), 403);
        $term = $this->chosenTerm();
        $target = $this->mergeInto !== '' ? Booklet::terms()->findBySlug($this->taxonomy, $this->mergeInto) : null;

        if (! $target || $target->slug() === $term->slug()) {
            $this->addError('mergeInto', __('Choose where to merge it.'));

            return;
        }

        $count = $this->retag($this->taxonomy, $term->slug(), $target->slug());
        Booklet::terms()->delete($term);

        ActivityLog::record('merged', 'settings', 'term', $this->taxonomy.'/'.$target->slug(), $term->title().' → '.$target->title());
        $this->selected = $target->slug();
        $this->mergeInto = '';
        $this->stamp(trans_choice('Merged into :title on :count entry.|Merged into :title on :count entries.', $count, ['title' => $target->title()]));
    }

    public function removeTerm(): void
    {
        abort_unless($this->canManage(), 403);
        $term = $this->chosenTerm();

        $count = $this->retag($this->taxonomy, $term->slug(), null);
        Booklet::terms()->delete($term);

        ActivityLog::record('removed', 'settings', 'term', $this->taxonomy.'/'.$term->slug(), $term->title());
        $this->selected = null;
        $this->stamp(trans_choice('Removed from :count entry.|Removed from :count entries.', $count));
    }

    public function render()
    {
        $usage = $this->usage();
        $count = fn (Term $t) => $usage[$t->taxonomy().':'.$t->slug()] ?? 0;

        $categories = Booklet::terms()->whereTaxonomy('categories');
        $tags = Booklet::terms()->whereTaxonomy('tags')->sortByDesc($count)->values();

        $chosen = $this->selected ? Booklet::terms()->findBySlug($this->taxonomy, $this->selected) : null;
        $chosen ??= $this->taxonomy === 'tags' ? ($tags->first() ?? $categories->first()) : ($categories->first() ?? $tags->first());

        if ($chosen) {
            $this->taxonomy = $chosen->taxonomy();
            $this->selected = $chosen->slug();
        }

        return view('livewire.admin.taxonomies', [
            'categories' => $categories,
            'tags' => $tags,
            'count' => $count,
            'chosen' => $chosen,
            'mergeChoices' => $chosen
                ? Booklet::terms()->whereTaxonomy($chosen->taxonomy())->reject(fn (Term $t) => $t->slug() === $chosen->slug())->values()
                : collect(),
            'canManage' => $this->canManage(),
            'max' => max(1, $tags->map($count)->max() ?? 1),
        ]);
    }
}
