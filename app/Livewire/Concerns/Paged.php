<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * One way of turning pages, for every list in the book: which page is in the
 * address (so links, Back and refresh land on it), how many rows a page is
 * each person's own choice for each list (kept with their preferences), and
 * changing a filter or search goes back to the first page.
 *
 * A component says what its list is called (pagedList), may offer other page
 * sizes (pageSizes), and names what sends it back to page one (pageResetsOn).
 * The view shows <x-admin.pager :of="$paginator" />.
 */
trait Paged
{
    /** Which page of the list. */
    #[Url(except: 1)]
    public int $page = 1;

    /** Rows a page, this person's choice for this list. */
    public int $perPage = 0;

    /** The list's name for remembering its page size: "comments", "media"… */
    abstract protected function pagedList(): string;

    /** @return list<int> */
    protected function pageSizes(): array
    {
        return [25, 50, 100];
    }

    /** What sends the list back to its first page when it changes. */
    protected function pageResetsOn(): array
    {
        return ['search', 'status', 'show', 'kind'];
    }

    public function mountPaged(): void
    {
        $saved = (int) (((array) (request()->user()?->preferences['per_page'] ?? []))[$this->pagedList()] ?? 0);
        $this->perPage = in_array($saved, $this->pageSizes(), true) ? $saved : $this->defaultPageSize();
        $this->page = max(1, $this->page);
    }

    protected function defaultPageSize(): int
    {
        return $this->pageSizes()[0];
    }

    public function updatedPaged(string $name): void
    {
        if (in_array(Str::before($name, '.'), $this->pageResetsOn(), true)) {
            $this->page = 1;
        }
    }

    public function renderingPaged($view): void
    {
        $view->with('pageSizes', $this->pageSizes());
    }

    public function turnTo(int $page): void
    {
        $this->page = max(1, $page);
        $this->dispatch('pager-turned');
    }

    /** Laravel's name for it, kept so older calls still work. */
    public function resetPage(): void
    {
        $this->page = 1;
    }

    /** A new page size: remembered for this list, and the first row you could see stays in view. */
    public function setPerPage(int|string $size): void
    {
        $size = (int) $size;
        if (! in_array($size, $this->pageSizes(), true)) {
            return;
        }
        $first = ($this->page - 1) * $this->rowsPerPage();
        $this->perPage = $size;
        $this->page = intdiv($first, $size) + 1;

        if ($user = request()->user()) {
            $preferences = (array) $user->preferences;
            $preferences['per_page'] = array_merge((array) ($preferences['per_page'] ?? []), [$this->pagedList() => $size]);
            $user->forceFill(['preferences' => $preferences])->save();
        }
    }

    protected function rowsPerPage(): int
    {
        return in_array($this->perPage, $this->pageSizes(), true) ? $this->perPage : $this->defaultPageSize();
    }

    /**
     * This page of a list: a query or a collection. A page past the end turns
     * back to the last one (after deleting, or from an old link).
     */
    protected function pageOf(EloquentBuilder|QueryBuilder|Collection $items): LengthAwarePaginator
    {
        $per = $this->rowsPerPage();
        $total = $items instanceof Collection ? $items->count() : (clone $items)->count();
        $this->page = min(max(1, $this->page), max(1, (int) ceil($total / $per)));

        $rows = $items instanceof Collection
            ? $items->forPage($this->page, $per)->values()
            : $items->forPage($this->page, $per)->get();

        return new LengthAwarePaginator($rows, $total, $per, $this->page);
    }
}
