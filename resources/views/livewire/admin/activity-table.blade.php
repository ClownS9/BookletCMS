@php
    $me = request()->user();
    $who = fn ($log) => $log->actor === 'stacy' ? __('Stacy') : ($log->actor === 'booklet' ? __('Booklet') : ($log->user?->name ?? __('Someone')));
@endphp
<div style="display: contents">
    {{-- Activity in the Dashboard layout: every change in a table, filtered by
         kind and person, searchable, each stamp opening in the drawer. --}}
    <section class="page page--single page--dash" aria-labelledby="activity-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="activity-title">{{ __('Activity') }}</h1>
                    <p>{{ __('Every change, stamped like a library card.') }}</p>
                </div>
                <a href="{{ route('admin.visits') }}" class="btn" wire:navigate><x-admin.icon name="pulse" size="16" /> {{ __('Visits') }}</a>
                <a href="{{ route('admin.bin') }}" class="btn" wire:navigate><x-admin.icon name="bin" size="16" /> {{ __('The bin') }}</a>
            </div>
            <x-admin.insert-links chapter="Activity" />

            <section class="dash-card" aria-label="{{ __('Activity') }}">
                <div class="dash-tabs" role="group" aria-label="{{ __('Show') }}">
                    <button type="button" wire:click="$set('kind', '')" aria-pressed="{{ $kind === '' ? 'true' : 'false' }}">{{ __('Everything') }} <b>{{ number_format($counts->sum()) }}</b></button>
                    @foreach ($kinds as $value => [$label, $tone])
                        @if (($counts[$value] ?? 0) > 0 || $kind === $value)
                            <button type="button" wire:click="$set('kind', '{{ $value }}')" aria-pressed="{{ $kind === $value ? 'true' : 'false' }}">{{ __($label) }} <b>{{ number_format($counts[$value] ?? 0) }}</b></button>
                        @endif
                    @endforeach
                </div>
                <div class="dash-tools">
                    <div class="search">
                        <x-admin.icon name="search" size="16" />
                        <label for="activity-search" class="sr-only">{{ __('Find a change') }}</label>
                        <input id="activity-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Find by what it was about') }}">
                    </div>
                    <label for="by" class="sr-only">{{ __('Person') }}</label>
                    <select id="by" class="input" style="width: auto" wire:model.live="person">
                        <option value="">{{ __('Everyone') }}</option>
                        @foreach ($people as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}{{ $p->id === $me?->id ? ' '.__('(you)') : '' }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($logs->isEmpty())
                    <p class="dash-empty">{{ __('No stamps of that kind yet.') }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Who') }}</th>
                                    <th scope="col">{{ __('What') }}</th>
                                    <th scope="col" class="is-wide">{{ __('Kind') }}</th>
                                    <th scope="col">{{ __('When') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($logs as $log)
                                    <tr wire:key="log-{{ $log->id }}" @class(['is-open' => $openedLog?->id === $log->id])>
                                        <td style="width: 1%; white-space: nowrap">
                                            @if ($log->user && ! $log->actor)
                                                <span class="dash-person"><x-admin.avatar :user="$log->user" size="24" />{{ $log->user->name }}</span>
                                            @else
                                                <span class="dash-person">{{ $who($log) }}</span>
                                            @endif
                                        </td>
                                        <td class="dash-table__main">
                                            <button type="button" class="dash-table__open" wire:click="$set('opened', {{ $log->id }})">
                                                <span class="dash-table__quote">{{ \Illuminate\Support\Str::ucfirst(__($log->action)) }} @if ($log->subject_label)<em>{{ $log->subject_label }}</em>@endif</span>
                                            </button>
                                        </td>
                                        <td class="is-wide"><span class="dash-role" style="--tone: {{ $kinds[$log->kind][1] ?? 'var(--sand)' }}">{{ __($kinds[$log->kind][0] ?? $log->kind) }}</span></td>
                                        <td class="is-quiet" title="{{ $log->created_at->translatedFormat('l j F Y, g:i a') }}">{{ $log->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-admin.pager :of="$logs" :sizes="$pageSizes" class="dash-foot" id="activity-pager" always />
                @endif
            </section>
        </div>
    </section>

    @if ($openedLog)
        <x-admin.drawer :title="__('A stamp on the card')">
            <div class="drawer-note">
                <p class="drawer-note__body" style="margin: 0"><strong>{{ $who($openedLog) }}</strong> {{ __($openedLog->action) }} @if ($openedLog->subject_label)<em>{{ $openedLog->subject_label }}</em>@endif</p>
            </div>
            <dl class="drawer-facts">
                <div><dt>{{ __('When') }}</dt><dd>{{ $openedLog->created_at->translatedFormat('l j F Y, g:i a') }} · {{ $openedLog->created_at->diffForHumans() }}</dd></div>
                <div><dt>{{ __('Kind') }}</dt><dd>{{ __($kinds[$openedLog->kind][0] ?? $openedLog->kind) }}</dd></div>
                @if ($openedLog->user)<div><dt>{{ __('Person') }}</dt><dd>{{ $openedLog->user->name }} · {{ $openedLog->user->email }}</dd></div>@endif
                @foreach ((array) $openedLog->properties as $key => $value)
                    @continue(is_array($value) || is_object($value))
                    <div><dt>{{ __(\Illuminate\Support\Str::headline((string) $key)) }}</dt><dd>{{ is_bool($value) ? ($value ? __('Yes') : __('No')) : $value }}</dd></div>
                @endforeach
            </dl>
            <div class="drawer__actions">
                @if ($openedLink)<a href="{{ $openedLink }}" class="btn btn--ink" wire:navigate>{{ __('Open it') }}</a>@endif
                @if ($openedLog->user_id)<button type="button" class="btn" wire:click="byPerson({{ $openedLog->user_id }})">{{ __('Everything by them') }}</button>@endif
            </div>
        </x-admin.drawer>
    @endif
</div>
