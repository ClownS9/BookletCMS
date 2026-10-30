<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Support\YourBook;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Make it yours: how Booklet looks and opens for one person. Each choice is
 * kept straight away and the page turns to show it; nobody else is affected.
 */
#[Layout('components.layouts.admin')]
#[Title('Make it yours')]
class MakeItYours extends Component
{
    use Stamps;

    /** Pick one of the choices (layout, theme, font, density, size). */
    public function choose(string $what, string $value): void
    {
        abort_unless(in_array($what, ['layout', 'theme', 'font', 'density', 'size'], true), 422);
        YourBook::save(request()->user(), [$what => $value]);
        $this->turnTo();
    }

    /** Night reading: off, on, or with the device. */
    public function night(string $mode): void
    {
        abort_unless(in_array($mode, ['off', 'on', 'device'], true), 422);
        $user = request()->user();
        $user->forceFill(['preferences' => array_merge((array) $user->preferences, ['night' => $mode === 'on'])])->save();
        YourBook::save($user->fresh(), ['night_auto' => $mode === 'device']);
        $this->turnTo();
    }

    public function toggleTurn(): void
    {
        $user = request()->user();
        YourBook::save($user, ['turn' => ! YourBook::of($user)['turn']]);
        $this->turnTo();
    }

    /** "Put back Booklet's own look". */
    public function putBack(): void
    {
        YourBook::reset(request()->user());
        $this->stamp('Booklet’s own look is back.', afterRedirect: true);
        $this->turnTo();
    }

    /** The look is on the whole page, so show the page again in it. */
    protected function turnTo(): void
    {
        $this->redirectRoute('admin.account.yours', navigate: true);
    }

    public function render()
    {
        $user = request()->user();
        $yours = YourBook::of($user);
        $night = $yours['night_auto'] ? 'device' : (($user->preferences['night'] ?? false) ? 'on' : 'off');

        return view('livewire.admin.make-it-yours', [
            'yours' => $yours,
            'night' => $night,
            'layouts' => YourBook::LAYOUTS,
            'themes' => YourBook::THEMES,
            'fonts' => YourBook::FONTS,
            'densities' => YourBook::DENSITIES,
            'sizes' => YourBook::SIZES,
            'fontLinks' => collect(YourBook::FONTS)->pluck(4)->filter()->map(fn ($f) => 'https://fonts.googleapis.com/css2?'.$f.'&display=swap&text=Aa')->values()->all(),
        ]);
    }
}
