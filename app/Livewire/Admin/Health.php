<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings › Check-up: is everything the book needs from its server there
 * and working? Admins only (it talks about the server's settings).
 */
#[Layout('components.layouts.admin')]
#[Title('Check-up')]
class Health extends Component
{
    use Stamps;

    /** The address the admin is visiting, to compare with APP_URL. */
    public string $visiting = '';

    public function mount(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->visiting = request()->getSchemeAndHttpHost();
    }

    public function again(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->stamp('Checked again.');
    }

    public function render()
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $checks = collect(\App\Support\Health::run($this->visiting));

        return view('livewire.admin.health', [
            'checks' => $checks,
            'attention' => $checks->whereIn('state', ['fail', 'warn'])->sortBy(fn ($c) => $c['state'] === 'fail' ? 0 : 1)->values(),
            'groups' => \App\Support\Health::GROUPS,
        ]);
    }
}
