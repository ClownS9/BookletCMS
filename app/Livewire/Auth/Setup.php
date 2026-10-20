<?php

namespace App\Livewire\Auth;

use App\Booklet\Data\Entry;
use App\Booklet\Facades\Booklet;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\BookletStarterSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * First setup: four short steps the very first time Booklet opens.
 * Only available while the book has nobody in it.
 */
#[Layout('components.layouts.cover')]
#[Title('Start your book')]
class Setup extends Component
{
    public const ACCENTS = [
        'Coral' => '#F4876A',
        'Sun' => '#F3C24F',
        'Mint' => '#86CDB9',
        'Sky' => '#93B4F2',
        'Lilac' => '#C5AAF0',
    ];

    public const STEPS = ['Name your book', 'Your account', 'Pick a sticker', 'Ready'];

    public int $step = 0;

    public string $siteName = '';

    public string $tagline = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $accent = 'Coral';

    public function mount()
    {
        if (User::query()->exists()) {
            return redirect()->route(request()->user() !== null ? 'admin.contents' : 'login');
        }

        $this->siteName = (string) config('app.name', '');
    }

    protected function rulesFor(int $step): array
    {
        return match ($step) {
            0 => ['siteName' => ['required', 'string', 'max:120'], 'tagline' => ['nullable', 'string', 'max:200']],
            1 => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190'],
                'password' => ['required', 'string', new \App\Rules\StrongPassword([$this->name, $this->email, $this->siteName])],
            ],
            2 => ['accent' => ['required', 'in:'.implode(',', array_keys(self::ACCENTS))]],
            default => [],
        };
    }

    public function next(): void
    {
        $this->email = mb_strtolower(trim($this->email));

        $this->validate($this->rulesFor($this->step), [], [
            'siteName' => 'site name',
        ]);

        if ($this->step === 2) {
            $this->finish();
        }

        $this->step = min(3, $this->step + 1);
    }

    public function back(): void
    {
        if ($this->step < 3) {
            $this->step = max(0, $this->step - 1);
        }
    }

    public function pickAccent(string $name): void
    {
        if (array_key_exists($name, self::ACCENTS)) {
            $this->accent = $name;
        }
    }

    protected function finish(): void
    {
        // Someone else may have finished setup in another tab.
        abort_if(User::query()->exists(), 403);

        foreach ([0, 1, 2] as $step) {
            $this->validate($this->rulesFor($step));
        }

        DB::transaction(function () {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'role' => 'admin',
            ]);

            // Posts, Pages, menus, a Home page and a first draft, unless a book is already there.
            if (Booklet::collections()->all()->isEmpty()) {
                (new BookletStarterSeeder)->run();
            }

            $globals = Booklet::globals('site') ?? Booklet::globalVariables()->make(['handle' => 'site', 'locale' => config('booklet.default_site')]);
            $globals->merge([
                'site_name' => $this->siteName,
                'tagline' => $this->tagline ?: null,
                'accent' => self::ACCENTS[$this->accent],
            ])->save();

            // The starter post is theirs.
            Booklet::entries()->all()
                ->filter(fn (Entry $e) => ! $e->get('author'))
                ->each(fn (Entry $e) => $e->set('author', $user->id)->save());

            Auth::login($user, true);
            ActivityLog::record('started the book', 'settings', 'settings', 'site', $this->siteName);
        });

        session()->regenerate();
        session()->put('stacy.greet', 'first'); // and introduces herself
    }

    public function render()
    {
        return view('livewire.auth.setup', [
            'steps' => self::STEPS,
            'accents' => self::ACCENTS,
        ]);
    }
}
