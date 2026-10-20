<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\View\View;

/**
 * A page title that depends on what's shown ("Editions · Pandan chiffon").
 * Livewire adds title() to views while the app runs (a macro), which
 * editors can't see; going through here keeps them from flagging it.
 */
trait PageTitle
{
    protected function titled(View $view, string $title): View
    {
        return call_user_func([$view, 'title'], $title);
    }
}
