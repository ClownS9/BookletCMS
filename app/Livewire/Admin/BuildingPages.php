<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Help › Building pages: how the page builder works, in one place (it's
 * linked from the builder's bar and from "Built on the page" in the book).
 * Anyone signed in may read it.
 */
#[Layout('components.layouts.admin')]
#[Title('Building pages')]
class BuildingPages extends Component
{
    public function render()
    {
        $user = request()->user();
        $pages = rescue(fn () => \App\Booklet\Facades\Booklet::collections()->all()
            ->first(fn ($c) => $c->handle() === 'pages' && $user?->canOpen('pages')), null, false);

        return view('livewire.admin.building-pages', [
            'pagesUrl' => $pages ? route('admin.entries', 'pages') : null,
            'blogUrl' => app(\App\PageBuilder\PageBuilder::class)->canBuild($user, null) ? app(\App\Booklet\Site::class)->blogUrl().'?edit=1' : null,
            'starters' => (array) (app(\App\PageBuilder\PageBuilder::class)->builder->starters()),
        ]);
    }
}
