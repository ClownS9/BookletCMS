<?php

namespace App\Livewire\Admin;

use App\Booklet\Facades\Booklet;
use App\Support\VisitStats;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Visits: how many people came, what they read, how far they got and where
 * they came from. Counted without cookies or personal data.
 */
#[Layout('components.layouts.admin')]
#[Title('Visits')]
class Visits extends Component
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    #[Url(as: 'days', except: 7)]
    public int $days = 7;

    public function mount(): void
    {
        if (! array_key_exists($this->days, self::PERIODS)) {
            $this->days = 7;
        }
    }

    public function setDays(int $days): void
    {
        $this->days = array_key_exists($days, self::PERIODS) ? $days : 7;
    }

    public function render()
    {
        $stats = rescue(fn () => VisitStats::summary($this->days), null, false) ?? [
            'days' => [], 'views' => 0, 'visitors' => 0, 'reads' => 0, 'previous' => 0, 'pages' => [], 'sources' => [],
        ];

        // Name each page by its entry's title where there is one.
        foreach ($stats['pages'] as &$page) {
            $entry = $page['entry_id'] ? Booklet::entries()->find($page['entry_id']) : null;
            $page['title'] = $entry?->title() ?: ($page['path'] === '/' ? 'Home page' : $page['path']);
            $page['is_post'] = (bool) $entry?->collection()?->dated();
            $page['edit'] = $entry ? route('admin.entries.edit', [$entry->collectionHandle(), $entry->id()]) : null;
            $page['read_rate'] = $page['is_post'] && $page['views'] > 0 ? min(100, (int) round($page['reads'] / $page['views'] * 100)) : null;
        }
        unset($page);

        $change = $stats['previous'] > 0 ? (int) round(($stats['views'] - $stats['previous']) / $stats['previous'] * 100) : null;

        return view('livewire.admin.visits', [
            'stats' => $stats,
            'change' => $change,
            'peak' => max(1, ...array_column($stats['days'] ?: [['views' => 1]], 'views')),
            'sourceTotal' => max(1, array_sum(array_column($stats['sources'], 'views'))),
            'periods' => self::PERIODS,
        ]);
    }
}
