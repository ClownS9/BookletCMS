<?php

namespace App\Livewire\Admin;

use App\Support\SearchIndex;
use Livewire\Component;

/**
 * Search everything, in a window over the book (⌘K, Ctrl+K or "/" anywhere).
 * Opening and closing happen in the browser; only the typing asks the server.
 */
class SearchPalette extends Component
{
    public string $q = '';

    public string $scope = 'all';

    public function setScope(string $scope): void
    {
        $this->scope = array_key_exists($scope, SearchIndex::scopes()) ? $scope : 'all';
    }

    public function updatedQ(): void
    {
        // Typing "in:media" picks the filter too.
        if (preg_match('/\bin:([a-z0-9_-]+)/i', $this->q, $m) && array_key_exists(strtolower($m[1]), SearchIndex::scopes())) {
            $this->scope = strtolower($m[1]);
        }
    }

    public function render()
    {
        $index = new SearchIndex($this->q, $this->scope);
        $typed = $index->needle() !== '' || $index->status !== null;
        $found = $typed ? $index->run() : ['groups' => [], 'counts' => []];

        return view('livewire.admin.search-palette', [
            'typed' => $typed,
            'needle' => $index->query,
            'status' => $index->status,
            'scopes' => SearchIndex::scopes(),
            'groups' => $found['groups'],
            'counts' => $found['counts'],
            'total' => collect($found['groups'])->sum(fn ($g) => count($g['items'])),
            'actions' => $index->actions(),
            'recent' => $typed ? [] : $index->recentlyEdited(),
            'dogEars' => $typed ? collect() : \App\Models\DogEar::where('user_id', request()->user()?->id)->latest()->take(5)->get(),
        ]);
    }
}
