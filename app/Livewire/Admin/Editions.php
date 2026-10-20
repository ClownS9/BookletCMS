<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use App\Booklet\Data\Entry;
use App\Booklet\Data\Revision;
use App\Booklet\Facades\Booklet;
use App\Booklet\Support\WordDiff;
use App\Models\ActivityLog;
use App\Support\EditionCompare;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use App\Livewire\Concerns\Stamps;
use Livewire\Component;

/**
 * Editions: every saved version of an entry, what changed in each (the
 * words, word by word, and every other field), compared with the edition
 * before it, with what's in the editor now, or with any other edition;
 * in one column or side by side. A whole edition can be brought back, or
 * just one of its fields.
 *
 * Worked out when asked (#[Computed]):
 *
 * @property-read Entry $entry
 * @property-read Collection<int, Revision> $editions
 * @property-read Revision|null $chosen
 */
#[Layout('components.layouts.admin')]
class Editions extends Component
{
    use PageTitle;

    use Stamps;

    public string $collection;

    public string $entryId;

    #[Url(as: 'edition', except: null)]
    public ?string $selected = null;

    /** Compared with: previous (the edition before), now (the editor), or another edition's id. */
    #[Url(as: 'with', except: 'previous')]
    public string $against = 'previous';

    /** One column (changes marked in the text) or side by side. */
    #[Url(as: 'view', except: 'inline')]
    public string $view = 'inline';

    public function mount(string $collection, string $entry): void
    {
        $model = Booklet::entries()->find($entry);
        abort_unless($model && $model->collectionHandle() === $collection, 404);

        $this->collection = $collection;
        $this->entryId = $entry;
    }

    #[Computed]
    public function entry(): Entry
    {
        return Booklet::entries()->findOrFail($this->entryId);
    }

    /**
     * Newest first.
     *
     * @return Collection<int, Revision>
     */
    #[Computed]
    public function editions(): Collection
    {
        return Booklet::revisions()->whereKey($this->entry->revisionKey());
    }

    #[Computed]
    public function chosen(): ?Revision
    {
        return $this->editions->first(fn (Revision $r) => $r->id() === $this->selected)
            ?? $this->editions->first();
    }

    public function pick(string $id): void
    {
        $this->selected = $id;
        // Comparing with itself makes no sense: back to "the edition before".
        if ($this->against === $id) {
            $this->against = 'previous';
        }
        unset($this->chosen);
    }

    public function compareWith(string $against): void
    {
        $this->against = in_array($against, ['previous', 'now'], true) || $this->editions->contains(fn (Revision $r) => $r->id() === $against)
            ? $against : 'previous';
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'side' ? 'side' : 'inline';
    }

    /** The chosen edition's number (1 is the first). */
    protected function numberOf(Revision $revision): int
    {
        return $this->editions->count() - (int) $this->editions->search(fn (Revision $r) => $r->id() === $revision->id());
    }

    /**
     * Put back one field (the standfirst, the tags, the address…) from the
     * chosen edition, leaving everything else as it is now.
     */
    public function putBack(string $handle): void
    {
        $revision = $this->chosen;
        $entry = $this->entry;
        $field = EditionCompare::fields($entry)[$handle] ?? null;
        if (! $revision || ! $field) {
            return;
        }

        $value = EditionCompare::value($revision->snapshot(), $handle, $field);
        if ($field['attribute']) {
            $entry->setAttribute($handle, $value);
        } elseif ($value === null) {
            $entry->remove($handle);
        } else {
            $entry->set($handle, $value);
        }
        $entry->save();

        $number = $this->numberOf($revision);
        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'restored', (string) request()->user()?->id, "Put back the {$field['label']} from edition {$number}");
        ActivityLog::record("put back the {$field['label']} from edition {$number} of", 'edits', 'entry', $entry->id(), $entry->title());

        // Keep looking at the same edition (a new edition has just been added on top).
        unset($this->entry, $this->editions, $this->chosen);
        $this->stamp("The {$field['label']} from edition {$number} is back. Everything else stays as it was.");
    }

    /**
     * Put the chosen edition's words back into the entry. The current words
     * are already an edition, so nothing is lost.
     */
    public function restore(): void
    {
        $revision = $this->chosen;
        if (! $revision) {
            return;
        }

        $entry = $this->entry;
        $snapshot = $revision->snapshot();
        $attributes = (array) ($snapshot['attributes'] ?? []);

        $entry->setData((array) ($snapshot['data'] ?? []));
        foreach (['slug', 'blueprint'] as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $entry->setAttribute($attribute, $attributes[$attribute]);
            }
        }
        $entry->save();

        $number = $this->numberOf($revision);
        Booklet::revisions()->snapshot($entry->revisionKey(), $entry, 'restored', (string) request()->user()?->id, "Restored edition {$number}");
        ActivityLog::record("restored edition {$number} of", 'edits', 'entry', $entry->id(), $entry->title());

        $this->selected = null;
        unset($this->entry, $this->editions, $this->chosen);
        $this->stamp("Edition {$number} is back. The words it replaced are kept as an edition too.");
    }

    public function render()
    {
        $editions = $this->editions;
        $chosen = $this->chosen;
        $count = $editions->count();
        $entry = $this->entry;

        $index = $chosen ? $editions->search(fn (Revision $r) => $r->id() === $chosen->id()) : false;
        $previous = $index !== false ? $editions->get($index + 1) : null;
        $label = fn (Revision $r) => $editions->first()?->id() === $r->id() ? 'The latest edition' : 'Edition '.($count - (int) $editions->search(fn (Revision $x) => $x->id() === $r->id()));
        $now = EditionCompare::now($entry);

        // What's compared: [older snapshot, newer snapshot, their names].
        $other = ! in_array($this->against, ['previous', 'now'], true) ? $editions->first(fn (Revision $r) => $r->id() === $this->against) : null;
        $against = $other || $this->against === 'now' ? $this->against : 'previous';
        [$older, $newer, $olderName, $newerName] = match (true) {
            ! $chosen => [null, null, '', ''],
            $against === 'now' => [$chosen->snapshot(), $now, $label($chosen), 'Now, in the editor'],
            $other !== null => $editions->search(fn (Revision $r) => $r->id() === $other->id()) > $index
                ? [$other->snapshot(), $chosen->snapshot(), $label($other), $label($chosen)]
                : [$chosen->snapshot(), $other->snapshot(), $label($chosen), $label($other)],
            default => [$previous?->snapshot(), $chosen->snapshot(), $previous ? $label($previous) : 'Nothing (it’s the first)', $label($chosen)],
        };

        $text = fn (?array $snap) => trim(((string) data_get($snap, 'data.title', ''))."\n\n".((string) data_get($snap, 'data.content', '')));
        $segments = $chosen ? WordDiff::compare($older ? $text($older) : '', $text($newer)) : [];

        // Fields that can be put back: where the chosen edition differs from now.
        $fields = EditionCompare::fields($entry);
        $changes = $chosen && $older ? EditionCompare::changes($entry, $older, $newer) : [];
        foreach ($changes as $i => $row) {
            $field = $fields[$row['handle']];
            $changes[$i]['can_put_back'] = $chosen && ! EditionCompare::same(EditionCompare::value($chosen->snapshot(), $row['handle'], $field), EditionCompare::value($now, $row['handle'], $field), $field);
        }
        $wordsDifferFromNow = $chosen && $text($chosen->snapshot()) !== $text($now);

        $users = User::whereIn('id', $editions->map(fn (Revision $r) => $r->user())->filter()->unique()->values())->get()->keyBy('id');

        return $this->titled(view('livewire.admin.editions', [
            'count' => $count,
            'chosenNumber' => $index !== false ? $count - $index : null,
            'isCurrent' => $index === 0,
            'previous' => $previous,
            'againstNow' => $against,
            'olderName' => $olderName,
            'newerName' => $newerName,
            'hasOlder' => (bool) $older,
            'paragraphs' => $this->paragraphs($segments),
            'before' => $this->paragraphs(array_values(array_filter($segments, fn ($s) => $s[0] !== 'added'))),
            'after' => $this->paragraphs(array_values(array_filter($segments, fn ($s) => $s[0] !== 'removed'))),
            'stats' => WordDiff::stats($segments),
            'changes' => $changes,
            'differsFromNow' => $wordsDifferFromNow || collect($fields)->contains(fn ($f, $h) => $chosen && ! EditionCompare::same(EditionCompare::value($chosen->snapshot(), $h, $f), EditionCompare::value($now, $h, $f), $f)),
            'users' => $users,
            'title' => (string) data_get($chosen?->snapshot(), 'data.title', $entry->title()),
        ]), 'Editions · '.$entry->title());
    }

    /**
     * Split diff segments into paragraphs for display, keeping each
     * segment's kind.
     *
     * @return array<int, array<int, array{0: string, 1: string}>>
     */
    protected function paragraphs(array $segments): array
    {
        $paragraphs = [[]];

        foreach ($segments as [$type, $text]) {
            $parts = preg_split("/\n{2,}/", $text);

            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    $paragraphs[] = [];
                }
                if ($part !== '') {
                    $paragraphs[array_key_last($paragraphs)][] = [$type, $part];
                }
            }
        }

        return array_values(array_filter($paragraphs));
    }
}
