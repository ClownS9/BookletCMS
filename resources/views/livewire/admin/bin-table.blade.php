@php $pageIds = $items->getCollection()->pluck('id')->all(); $me = request()->user(); @endphp
<div style="display: contents">
    {{-- The bin in the Dashboard layout: what's waiting, what kind, who deleted it
         and how long it has left, with Put back and Delete for good, one or many. --}}
    <section class="page page--single page--dash" aria-labelledby="bin-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="bin-title">{{ __('The bin') }}</h1>
                    <p>{{ __('Torn-out pages wait here for :value days, so nothing is lost by accident. Put anything back exactly as it was.', ['value' => \App\Support\Bin::DAYS]) }}</p>
                </div>
                @if ($canEmpty && $counts->sum() > 0)
                    <button type="button" class="btn btn--danger" wire:click="emptyBin" wire:confirm="{{ __('Empty the bin? Everything in it will be gone for good.') }}">{{ __('Empty the bin') }}</button>
                @endif
            </div>

            <section class="dash-card" aria-label="{{ __('Everything in the bin') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Show') }}">
                    <button type="button" wire:click="show('')" aria-pressed="{{ $kind === '' ? 'true' : 'false' }}">{{ __('Everything') }} <b>{{ $counts->sum() }}</b></button>
                    @foreach ($kinds as $value => [$label, $tone])
                        <button type="button" wire:click="show('{{ $value }}')" aria-pressed="{{ $kind === $value ? 'true' : 'false' }}">{{ __($label) }} <b>{{ $counts[$value] ?? 0 }}</b></button>
                    @endforeach
                </div>
                @if ($ticked !== [])
                    <div class="dash-tools dash-bulk" role="region" aria-label="{{ __('Do to the chosen ones') }}" wire:key="ticks">
                        <strong>{{ trans_choice(':count chosen|:count chosen', count($ticked)) }}</strong>
                        <button type="button" class="btn btn--sm btn--ink" wire:click="doTicked('restore')">{{ __('Put back') }}</button>
                        <button type="button" class="btn btn--danger btn--sm" wire:click="doTicked('forget')" wire:confirm="{{ trans_choice('Delete :count for good? It can’t come back after this.|Delete :count for good? They can’t come back after this.', count($ticked)) }}">{{ __('Delete for good') }}</button>
                        <button type="button" class="btn btn--quiet btn--sm" wire:click="$set('ticked', [])">{{ __('Clear') }}</button>
                    </div>
                @endif

                @if ($items->isEmpty())
                    <p class="dash-empty">{{ __('The bin is empty.') }} {{ __('Deleted posts, pages, files and comments will wait here.') }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="dash-table__tick"><input type="checkbox" wire:click="tickAll(@js($pageIds))" @checked($pageIds !== [] && array_diff($pageIds, $ticked) === []) aria-label="{{ __('Choose all on this page') }}"></th>
                                    <th scope="col">{{ __('What') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Kind') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Deleted by') }}</th>
                                    <th scope="col" class="is-wide">{{ __('When') }}</th>
                                    <th scope="col">{{ __('Days left') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    @php $left = $item->daysLeft(); $mineToForget = $canForgetAny || (string) $item->deleted_by === (string) $me?->id; @endphp
                                    <tr wire:key="bin-{{ $item->id }}" @class(['is-ticked' => in_array($item->id, $ticked, true)])>
                                        <td class="dash-table__tick"><input type="checkbox" wire:model.live="ticked" value="{{ $item->id }}" aria-label="{{ __('Choose “:name”', ['name' => \Illuminate\Support\Str::limit($item->title, 40)]) }}"></td>
                                        <td class="dash-table__main"><span class="dash-table__name">{{ $item->title }}</span><span class="dash-table__sub dash-table__sub--plain">{{ $item->meta }}</span></td>
                                        <td class="is-wide"><span class="dash-role" style="--tone: {{ $kinds[$item->kind][1] ?? 'var(--sand)' }}">{{ __($kinds[$item->kind][0] ?? $item->kind) }}</span></td>
                                        <td class="is-wide">@if ($item->deleter)<span class="dash-person"><x-admin.avatar :user="$item->deleter" size="24" />{{ $item->deleter->id === $me?->id ? __('You') : $item->deleter->name }}</span>@else<span class="is-quiet">—</span>@endif</td>
                                        <td class="is-wide is-quiet">{{ $item->created_at->diffForHumans() }}</td>
                                        <td><span @class(['dash-days', 'is-soon' => $left <= 3])>{{ trans_choice(':count day|:count days', $left) }}</span></td>
                                        <td class="dash-table__actions">
                                            <button type="button" class="btn btn--sm btn--ink" wire:click="restore({{ $item->id }})" wire:loading.attr="disabled">{{ __('Put back') }}</button>
                                            @if ($mineToForget)
                                                <button type="button" class="btn btn--sm btn--quiet" wire:click="forget({{ $item->id }})" wire:confirm="{{ __('Delete “:name” for good? It can’t come back after this.', ['name' => \Illuminate\Support\Str::limit($item->title, 40)]) }}">{{ __('Delete for good') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-admin.pager :of="$items" :sizes="$pageSizes" class="dash-foot" id="bin-pager" always />
                @endif
            </section>
            <p class="field__hint" style="margin: 0">
                {{ __('A page comes back to its place in the tree and in the site menus. If its address was taken in the meantime, it gets the next free one.') }}
                @unless ($canForgetAny) {{ __('You can see what you deleted, and anything you wrote.') }} @endunless
            </p>
        </div>
    </section>
</div>
