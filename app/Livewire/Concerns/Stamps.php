<?php

namespace App\Livewire\Concerns;

/**
 * The little "Saved." stamp. Shown straight away when we stay on the page,
 * or flashed for the next page when we're about to redirect.
 */
trait Stamps
{
    /** @param  int|null  $undo  a bin item: the stamp offers "Undo" to put it back */
    protected function stamp(string $text, bool $afterRedirect = false, ?int $undo = null): void
    {
        if ($afterRedirect) {
            session()->flash('stamp', $text);
            if ($undo) {
                session()->flash('stamp_undo', $undo);
            }

            return;
        }

        $this->dispatch('stamp', text: $text, undo: $undo);
    }
}
