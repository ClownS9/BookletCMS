<?php

namespace App\Livewire\Concerns;

/**
 * Finding themes or inserts on GitHub from the "add" panel (see Catalog).
 * Looking inside one hands its repository to the component's own fetch(),
 * so it's unpacked to one side and shown, exactly as if it were pasted in.
 */
trait BrowsesCatalog
{
    /** Words to search for. */
    public string $find = '';

    public string $findSort = 'stars';

    public int $findPage = 1;

    public function updatedFind(): void
    {
        $this->findPage = 1;
    }

    public function updatedFindSort(): void
    {
        $this->findPage = 1;
    }

    /** Asked for this time: GitHub now, not what was found a few minutes ago. */
    protected bool $findFresh = false;

    /** What GitHub has, for the panel (see Catalog). */
    public function findResults(string $kind): array
    {
        return \App\Support\Catalog::search($kind, $this->find, $this->findSort, $this->findPage, $this->findFresh);
    }

    /** Look again: ask GitHub now (something just shared, say). At most every 20 seconds, so GitHub doesn't say slow down. */
    public function findAgain(): void
    {
        $limit = 'catalog-again:'.request()->user()->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limit, 1)) {
            $this->stamp(__('Just looked. Try again in :seconds seconds.', ['seconds' => \Illuminate\Support\Facades\RateLimiter::availableIn($limit)]));

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($limit, 20);
        $this->findFresh = true;
    }

    public function findPageTo(int $page): void
    {
        $this->findPage = max(1, min(20, $page));
    }

    /** Look inside one found on GitHub: the same as pasting its name. */
    public function lookAt(string $repo): void
    {
        abort_unless(request()->user()?->isAdmin(), 403);
        if (! preg_match('#^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$#', $repo)) {
            return;
        }
        $this->source = $repo;
        $this->fetch();
        if ($this->getErrorBag()->has('source')) {
            $this->stamp(\Illuminate\Support\Str::limit($this->getErrorBag()->first('source'), 220));
        }
    }
}
