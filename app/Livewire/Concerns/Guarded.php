<?php

namespace App\Livewire\Concerns;

use App\Inserts\InsertManager;

/**
 * A public form an insert can guard (comments, contact, new posts by
 * email, sign-in): what its widget hands back (a CAPTCHA's answer) arrives
 * as guardToken, and guarded() asks the inserts before anything is kept.
 * In the form's view: <x-guard form="comment" />.
 */
trait Guarded
{
    /** What the guard's widget handed back (set from the page: $wire.set('guardToken', …)). */
    public string $guardToken = '';

    /** True: it may go ahead. False: an insert said no, and why is shown under the form. */
    protected function guarded(string $form, array $answers = []): bool
    {
        $manager = app(InsertManager::class);
        if ($manager->guards($form)->isEmpty()) {
            return true;
        }
        $why = $manager->guardCheck($form, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $answers), $this->guardToken);

        // An answer counts once: the widget asks again for the next try.
        $this->guardToken = '';
        $this->dispatch('booklet-guard-reset', form: $form);

        if ($why !== null) {
            $this->addError('guard', $why);

            return false;
        }

        return true;
    }
}
