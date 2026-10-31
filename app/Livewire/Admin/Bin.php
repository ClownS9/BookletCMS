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

    use \App\Livewire\Concerns\Paged;

    protected function pagedList(): string
    {
        return 'bin';
    }

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
        $this->ticked = [];
        $this->page = 1;
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

        $this->stamp(__('“:title” is back.', ['title' => \Illuminate\Support\Str::limit($back['label'], 40)]));
    }

    public function forget(int $id): void
    {
        $item = $this->item($id);
        abort_unless(in_array(request()->user()->level(), ['admin', 'editor'], true) || (string) $item->deleted_by === (string) request()->user()?->id, 403);

        TheBin::forget($item);
        $this->stamp('Gone for good.');
    }

    // The Dashboard's table ----------------------------------------------------

    /** @var list<int> */
    public array $ticked = [];

    public function tickAll(array $ids): void
    {
        $ids = array_values(array_map('intval', array_filter($ids, 'is_numeric')));
        $this->ticked = array_diff($ids, $this->ticked) === []
            ? array_values(array_diff($this->ticked, $ids))
            : array_values(array_unique(array_merge($this->ticked, $ids)));
    }

    /** Put back, or delete for good, every ticked one this person may. */
    public function doTicked(string $what): void
    {
        abort_unless(in_array($what, ['restore', 'forget'], true), 422);
        $user = request()->user();
        $back = $gone = $stuck = 0;
        foreach (BinItem::query()->whereIn('id', array_slice(array_map('intval', $this->ticked), 0, 500))->get() as $item) {
            if (! TheBin::canHandle($user, $item)) {
                continue;
            }
            if ($what === 'restore') {
                try {
                    TheBin::restore($item);
                    $back++;
                } catch (RuntimeException) {
                    $stuck++;
                }
            } elseif (in_array($user->level(), ['admin', 'editor'], true) || (string) $item->deleted_by === (string) $user->id) {
                TheBin::forget($item);
                $gone++;
            }
        }
        $this->ticked = [];
        $this->stamp($what === 'restore'
            ? trans_choice(':count put back.|:count put back.', $back).($stuck ? ' '.trans_choice(':count couldn’t come back.|:count couldn’t come back.', $stuck) : '')
            : trans_choice(':count gone for good.|:count gone for good.', $gone));
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

        return view(\App\Support\YourBook::desk() ? 'livewire.admin.bin-table' : 'livewire.admin.bin', [
            'items' => $this->pageOf((clone $all)->with('deleter')->when($this->kind, fn ($q) => $q->where('kind', $this->kind))),
            'counts' => $counts,
            'kinds' => self::KINDS,
            'canEmpty' => $user->isAdmin(),
            'canForgetAny' => in_array($user->level(), ['admin', 'editor'], true),
        ]);
    }
}
