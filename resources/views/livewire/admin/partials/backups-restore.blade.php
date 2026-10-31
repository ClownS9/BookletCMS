{{-- Restoring: what's in a backup, and saying yes (both layouts). --}}
@php
    $label = [
        'entries' => __(':count entry|:count entries'),
        'assets' => __(':count file detail|:count file details'),
        'terms' => __(':count category or tag|:count categories & tags'),
        'collections' => __(':count chapter|:count chapters'),
        'trees' => __(':count page tree or menu|:count page trees & menus'),
        'globalVariables' => __(':count setting group|:count setting groups'),
        'revisions' => __(':count edition|:count editions'),
        'submissions' => __(':count form reply|:count form replies'),
    ];
@endphp
<div class="restore-card ribbon-clear" wire:key="restore-{{ $restoring }}">
    <h2 class="heading">{{ __('Restore from this backup?') }}</h2>
    @if ($problem)
        <p class="field__error" style="margin-top: 10px">{{ $problem }}</p>
        <button type="button" class="btn" style="margin-top: 14px" wire:click="cancelRestore">{{ __('Back to the list') }}</button>
    @elseif ($manifest)
        <dl class="restore-card__facts">
            <div><dt>{{ __('From') }}</dt><dd>{{ $manifest['site'] ?? __('A Booklet site') }}</dd></div>
            <div><dt>{{ __('Made') }}</dt><dd>{{ \Illuminate\Support\Carbon::parse($manifest['created_at'])->translatedFormat('j M Y, g:ia') }}{{ ! empty($manifest['by']) ? ' '.__('by :name', ['name' => $manifest['by']]) : '' }}</dd></div>
            <div>
                <dt>{{ __('Holds') }}</dt>
                <dd>
                    @php
                        $c = $manifest['counts'] ?? [];
                        $parts = [];
                        foreach (['entries', 'assets', 'terms', 'revisions', 'submissions'] as $k) {
                            $n = (int) ($c['content'][$k] ?? 0);
                            if ($n) $parts[] = trans_choice($label[$k], $n, ['count' => number_format($n)]);
                        }
                        foreach (['users' => __(':count person|:count people'), 'comments' => __(':count comment|:count comments'), 'redirects' => __(':count redirect|:count redirects')] as $k => $words) {
                            $n = (int) ($c['tables'][$k] ?? 0);
                            if ($n) $parts[] = trans_choice($words, $n, ['count' => number_format($n)]);
                        }
                        if ($c['files'] ?? 0) $parts[] = trans_choice(':count file|:count files', $c['files'], ['count' => number_format($c['files'])]);
                    @endphp
                    {{ $parts ? implode(', ', $parts) : __('Nothing much') }}
                </dd>
            </div>
        </dl>

        <div class="restore-card__warn">
            <p><strong>{{ __('Everything in the book now is replaced') }}</strong> {{ __('by what’s in this backup: posts, pages, settings, menus, comments and redirects. Pictures in the backup are put back; any others stay.') }}</p>
            <p>{{ __('Before anything changes, a backup of the book as it is now is made, so you can go back. People are matched by email: everyone here keeps their password, and anyone missing comes back.') }}</p>
        </div>

        <form wire:submit="restore" class="restore-card__go">
            <div class="field">
                <label for="confirm-word">{{ __('Type') }} <strong>{{ __('restore') }}</strong> {{ __('to go ahead') }}</label>
                <input id="confirm-word" type="text" wire:model="confirmWord" autocomplete="off" spellcheck="false" style="max-width: 220px">
                @error('confirmWord') <p class="field__error">{{ $message }}</p> @enderror
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap">
                <button type="submit" class="btn btn--danger" wire:loading.attr="disabled" wire:target="restore">
                    <span wire:loading.remove wire:target="restore">{{ __('Restore the book') }}</span>
                    <span wire:loading wire:target="restore">{{ __('Restoring…') }}</span>
                </button>
                <button type="button" class="btn btn--quiet" wire:click="cancelRestore">{{ __('Not now') }}</button>
            </div>
        </form>
    @endif
</div>
