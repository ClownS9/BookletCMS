<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Models\User;
use App\Support\Milestones;
use App\Support\PrintBook as Book;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Print as a book": choose what goes in and how the pages are cut, then
 * open the typeset book to print or save as a PDF.
 */
#[Layout('components.layouts.admin')]
#[Title('Print as a book')]
class PrintBook extends Component
{
    public string $collection = '';

    public string $from = '';

    public string $to = '';

    public string $tag = '';

    public string $author = '';

    public string $order = 'oldest';

    public string $size = 'a5';

    public bool $covers = true;

    public bool $recto = true;

    public bool $toc = true;

    public string $title = '';

    public string $subtitle = '';

    public string $byline = '';

    /** '' for the originals, or a language code for that edition. */
    public string $lang = '';

    public function mount(): void
    {
        // The last book you made, or a fresh start.
        $saved = (array) (((array) request()->user()->preferences)['print_book'] ?? []);
        // Opened from a chapter's list ("Print as a book →"): start with that chapter.
        if (is_string($from = request()->query('collection')) && $from !== '') {
            $saved = ['collection' => $from] + $saved;
        }
        foreach (Book::normalise($saved + Book::defaults()) as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return Book::normalise([
            'collection' => $this->collection, 'from' => $this->from, 'to' => $this->to, 'tag' => $this->tag,
            'author' => $this->author, 'order' => $this->order, 'size' => $this->size,
            'covers' => $this->covers, 'recto' => $this->recto, 'toc' => $this->toc,
            'title' => $this->title, 'subtitle' => $this->subtitle, 'byline' => $this->byline, 'lang' => $this->lang,
        ]);
    }

    /** Remember these choices for next time (called as the book opens). */
    public function remember(): void
    {
        $user = request()->user();
        $preferences = (array) $user->preferences;
        $preferences['print_book'] = $this->options();
        $user->forceFill(['preferences' => $preferences])->save();
    }

    public function startOver(): void
    {
        foreach (Book::defaults() as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function render()
    {
        $o = $this->options();
        $entries = Book::entries($o);
        $collection = Booklet::collections()->find($o['collection']);

        return view('livewire.admin.print-book', [
            'o' => $o,
            'collections' => Booklet::collections()->all(),
            'editions' => Book::languagesIn($o['collection']),
            'dated' => (bool) $collection?->dated(),
            'tags' => rescue(fn () => Booklet::terms()->whereTaxonomy('tags')->sortBy(fn ($t) => mb_strtolower($t->title()))->values(), collect(), false),
            'people' => User::orderBy('name')->get(['id', 'name', 'byline']),
            'entries' => $entries,
            'total' => Book::total($o),
            'words' => $entries->sum(fn ($e) => Milestones::words($e->get('content'))),
            'pages' => $entries->isEmpty() ? 0 : Book::estimatePages($entries, $o),
            'authors' => $o['byline'] ?: Book::authorsOf($entries),
            'href' => route('admin.print.book', Book::query($o)),
        ]);
    }
}
