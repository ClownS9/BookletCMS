<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PageTitle;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A chapter that isn't written yet. Each one is replaced by its own screen
 * in the coming steps; until then the flags still lead somewhere friendly.
 */
#[Layout('components.layouts.admin')]
class Chapter extends Component
{
    use PageTitle;

    public string $chapter;

    public function mount(string $chapter): void
    {
        $this->chapter = $chapter;
    }

    public function render()
    {
        $name = str($this->chapter)->replace('-', ' ')->title()->toString();

        return $this->titled(view('livewire.admin.chapter', ['name' => $name]), $name);
    }
}
