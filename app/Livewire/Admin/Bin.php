<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\Stamps;
use App\Models\BinItem;
use App\Support\Bin as TheBin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The bin: what was deleted in the last 30 days, ready to put back.
 * Writers see (and can put back) what they deleted or wrote; editors and
 * admins see everything, and can empty it.
 */
#[Layout('components.layouts.admin')]
#[Title('Bin')]
class Bin extends Component
{
    use Stamps;

    public const KINDS = [
        'entry' => ['Posts & pages', 'var(--sun)'],
        'asset' => ['Files', 'var(--sky)'],
        'comment' => ['Comments', 'var(--rose)'],
    ];

    #[Url(except: '')]
    public string $kind = '';

    public function show(string $kind): void
    {
        $this->kind = array_key_exists($kind, self::KINDS) ? $kind : '';
    }

    public function restore(int $id): void
    {
        $item = $this->item($id);

        try {
            $back = TheBin::restore($item);
        } catch (RuntimeException $e) {
            $this->stamp($e->getMessage());

            return;
        }

        $this->stamp('“'.\Illuminate\Support\Str::limit($back['label'], 40).'” is back.');
    }

    public function forget(int $id): void
    {
        $item = $this->item($id);
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true) || (string) $item->deleted_by === (string) request()->user()?->id, 403);

        TheBin::forget($item);
        $this->stamp('Gone for good.');
    }

    public function emptyBin(): void
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $n = 0;
        TheBin::visibleTo(request()->user())->get()->each(function (BinItem $item) use (&$n) {
            TheBin::forget($item);
            $n++;
        });
        $this->stamp($n ? 'The bin is empty.' : 'Nothing to empty.');
    }

    protected function item(int $id): BinItem
    {
        $item = BinItem::findOrFail($id);
        abort_unless(TheBin::canHandle(request()->user(), $item), 403);

        return $item;
    }

    public function render()
    {
        $user = request()->user();
        $all = TheBin::visibleTo($user);
        $counts = (clone $all)->reorder()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return view('livewire.admin.bin', [
            'items' => (clone $all)->with('deleter')->when($this->kind, fn ($q) => $q->where('kind', $this->kind))->limit(200)->get(),
            'counts' => $counts,
            'kinds' => self::KINDS,
            'canEmpty' => $user->isAdmin(),
            'canForgetAny' => in_array($user->level(), ['admin', 'editor'], true),
        ]);
    }
}
