<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Support\AdminNav;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Your menu: move, hide, rename and recolour the chapters and tools, and pick
 * the two on the phone's bar. Each person's own; only what they may open is
 * listed, and every change is kept straight away.
 */
#[Layout('components.layouts.admin')]
#[Title('Your menu')]
class YourMenu extends Component
{
    use Stamps;

    /** New names being typed, by key. */
    public array $names = [];

    public function mount(): void
    {
        foreach (AdminNav::menu() as $item) {
            $this->names[$item['key']] = $item['label'];
        }
    }

    /** @return array<string, array<string, mixed>> */
    protected function items(): array
    {
        return collect(AdminNav::menu(request()->user()))->keyBy('key')->all();
    }

    /** Keep a change to one item (or the order, or the phone bar). */
    protected function keep(?callable $change = null, ?array $order = null, ?array $dock = null): void
    {
        $user = request()->user();
        $saved = AdminNav::savedMenu($user);
        $items = $this->items();

        if ($change) {
            $saved['items'] = $change($saved['items'], $items);
        }
        if ($order !== null) {
            $saved['order'] = array_values(array_filter($order, fn ($k) => isset($items[$k])));
        }
        if ($dock !== null) {
            $saved['dock'] = array_values(array_filter($dock, fn ($k) => isset($items[$k])));
        }

        $preferences = (array) $user->preferences;
        $preferences['menu'] = AdminNav::cleanMenu($saved);
        $user->forceFill(['preferences' => $preferences])->save();
    }

    public function place(string $key, string $where): void
    {
        abort_unless(in_array($where, AdminNav::MENU_PLACES, true), 422);
        abort_unless(isset($this->items()[$key]), 404);
        $this->keep(fn ($saved) => array_replace($saved, [$key => array_merge($saved[$key] ?? [], ['where' => $where])]));
    }

    public function recolour(string $key): void
    {
        $items = $this->items();
        abort_unless(isset($items[$key]), 404);
        $at = array_search($items[$key]['tone'], AdminNav::TONES, true);
        $next = AdminNav::TONES[$at === false ? 0 : ($at + 1) % count(AdminNav::TONES)];
        $this->keep(fn ($saved) => array_replace($saved, [$key => array_merge($saved[$key] ?? [], ['tone' => $next])]));
    }

    public function updatedNames(mixed $value, string $key): void
    {
        $items = $this->items();
        if (! isset($items[$key])) {
            return;
        }
        $name = mb_substr(trim(strip_tags((string) $value)), 0, 40);
        $this->keep(function ($saved) use ($key, $name, $items) {
            $mine = $saved[$key] ?? [];
            // An empty name, or the usual one, puts the usual one back.
            if ($name === '' || $name === $items[$key]['original']) {
                unset($mine['label']);
            } else {
                $mine['label'] = $name;
            }
            $saved[$key] = $mine;

            return array_filter($saved);
        });
        if ($name === '') {
            $this->names[$key] = $items[$key]['original'];
        }
    }

    public function move(string $key, int $by): void
    {
        $order = array_keys($this->items());
        $at = array_search($key, $order, true);
        abort_if($at === false, 404);
        $to = max(0, min(count($order) - 1, $at + ($by < 0 ? -1 : 1)));
        [$order[$at], $order[$to]] = [$order[$to], $order[$at]];
        $this->keep(order: $order);
    }

    public function dockPick(int $slot, string $key): void
    {
        abort_unless(in_array($slot, [0, 1], true) && isset($this->items()[$key]), 422);
        $dock = array_column(AdminNav::dock(), 'key');
        $other = $dock[1 - $slot] ?? null;
        if ($other === $key) {
            $other = $dock[$slot] ?? null; // picking the other slot's chapter swaps them
        }
        $dock[$slot] = $key;
        $dock[1 - $slot] = $other;
        ksort($dock);
        $this->keep(dock: array_values(array_filter($dock)));
    }

    public function putBack(): void
    {
        $user = request()->user();
        $preferences = (array) $user->preferences;
        unset($preferences['menu']);
        $user->forceFill(['preferences' => $preferences])->save();
        $this->stamp('The usual menu is back.', afterRedirect: true);
        $this->redirectRoute('admin.account.menu', navigate: true);
    }

    public function render()
    {
        $items = array_values($this->items());

        return view('livewire.admin.your-menu', [
            'items' => $items,
            'places' => ['sticker' => __('Sticker'), 'thumb' => __('Side tab'), 'index' => __('Index'), 'hidden' => __('Hidden')],
            'placeHints' => ['sticker' => __('Along the top of the book'), 'thumb' => __('Cut into the edge of the pages'), 'index' => __('Only in search and the Index'), 'hidden' => __('Out of sight; search still finds it')],
            'dock' => AdminNav::dock(),
            'dockChoices' => array_values(array_filter($items, fn ($i) => $i['where'] !== 'hidden')),
        ]);
    }
}
