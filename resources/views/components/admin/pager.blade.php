@props(['of', 'sizes' => [25, 50, 100], 'previous' => null, 'next' => null, 'id' => 'pager', 'always' => false])
{{-- Turning a list's pages, the same on every list: how many and which, the
     page numbers, a page to jump to on long lists, and rows a page (each
     person's own). ← and → turn pages too, when you're not typing. --}}
@php
    $total = $of->total();
    $page = $of->currentPage();
    $per = $of->perPage();
    $last = $of->lastPage();
    $from = $total === 0 ? 0 : ($page - 1) * $per + 1;
    $to = min($total, $page * $per);
    // The first, the last, and two either side of this one; a gap stands for the rest.
    $numbers = [];
    foreach (range(1, $last) as $n) {
        if ($n === 1 || $n === $last || abs($n - $page) <= 2) {
            $numbers[] = $n;
        } elseif (end($numbers) !== '…') {
            $numbers[] = '…';
        }
    }
@endphp
@if ($total > min($sizes) || ($always && $total > 0))
    <nav {{ $attributes->class('pager') }} data-pager aria-label="{{ __('Pages') }}">
        <p class="pager__count">{{ __('Showing :from–:to of :total', ['from' => number_format($from), 'to' => number_format($to), 'total' => number_format($total)]) }}</p>

        @if ($last > 1)
            <div class="pager__turn">
                <button type="button" class="pager__step" data-pager-prev wire:click="turnTo({{ $page - 1 }})" @disabled($page <= 1) aria-label="{{ __('Previous page') }}">
                    <span aria-hidden="true">‹</span><span class="pager__word">{{ $previous ?? __('Previous') }}</span>
                </button>
                <ol class="pager__numbers" role="list">
                    @foreach ($numbers as $n)
                        @if ($n === '…')
                            <li class="pager__gap" aria-hidden="true">…</li>
                        @else
                            <li><button type="button" wire:click="turnTo({{ $n }})" @if ($n === $page) aria-current="page" @endif aria-label="{{ __('Page :n', ['n' => $n]) }}">{{ $n }}</button></li>
                        @endif
                    @endforeach
                </ol>
                <span class="pager__of">{{ __('Page :n of :count', ['n' => $page, 'count' => $last]) }}</span>
                <button type="button" class="pager__step" data-pager-next wire:click="turnTo({{ $page + 1 }})" @disabled($page >= $last) aria-label="{{ __('Next page') }}">
                    <span class="pager__word">{{ $next ?? __('Next') }}</span><span aria-hidden="true">›</span>
                </button>
            </div>

            @if ($last > 5)
                <form class="pager__jump" x-data="{ n: '' }"
                      x-on:submit.prevent="const to = parseInt(n, 10); if (to) { $wire.turnTo(Math.min({{ $last }}, Math.max(1, to))) } n = ''">
                    <label for="{{ $id }}-jump">{{ __('Go to page') }}</label>
                    <input id="{{ $id }}-jump" type="number" inputmode="numeric" min="1" max="{{ $last }}" x-model="n" placeholder="{{ $page }}">
                    <button type="submit" class="btn btn--sm">{{ __('Go') }}</button>
                </form>
            @endif
        @endif

        @if ($total > min($sizes))
        <label class="pager__per">
            <span>{{ __('Per page') }}</span>
            <select class="input" wire:change="setPerPage($event.target.value)">
                @foreach ($sizes as $size)
                    <option value="{{ $size }}" @selected($size === $per)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        @endif
    </nav>
@endif
