@php
    $size = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
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
<div style="display: contents">
    {{-- Left page: making backups --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">{{ __('Backups') }}</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('The whole book in one file: every post and page, settings, menus, pictures, people and comments. Keep one somewhere safe, and you can always start again from it.') }}</p>
        </div>

        @unless ($available)
            <p class="field__error">{{ __('This server can’t make .zip files yet (PHP’s zip extension is missing). Ask whoever runs the site to turn it on.') }}</p>
        @endunless

        <div>
            <button type="button" class="btn btn--ink btn--lg" wire:click="makeOne" wire:loading.attr="disabled" wire:target="makeOne" @disabled(! $available)>
                <span wire:loading.remove wire:target="makeOne">{{ __('Make a backup now') }}</span>
                <span wire:loading wire:target="makeOne">{{ __('Packing the book…') }}</span>
            </button>
            @if ($justMade)
                <p class="backup-made">
                    {{ __('Ready:') }} <a href="{{ route('admin.backups.download', $justMade) }}">{{ __('download :justmade', ['justmade' => $justMade]) }}</a>
                </p>
            @endif
        </div>

        <div class="setting-rows">
            <div class="setting-row">
                <span>
                    <span id="nightly" style="display: block; font-size: 15px; font-weight: 500">{{ __('Back up every night') }}</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">{{ __('Around 3am. The newest :value are kept on this server; older ones are cleared away.', ['value' => \App\Support\Backups::KEEP_NIGHTLY]) }}</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="nightly" aria-checked="{{ $nightly ? 'true' : 'false' }}" wire:click="toggleNightly"></button>
            </div>
        </div>

        <div class="backup-notes">
            <p><strong>{{ __('In every backup:') }}</strong> {{ __('posts, pages and their editions, the page tree and menus, categories and tags, settings (Stacy’s too), files and their descriptions, people and their pictures, comments, sticky notes, dog-ears, redirects and form replies.') }}</p>
            <p><strong>{{ __('Left out on purpose:') }}</strong> {{ __('AI service keys and the email password (they stay on this server), Stacy’s conversations, visit counts, the activity log and the bin.') }}</p>
            <p>{{ __('Backups on this server don’t help if the server itself is lost, so download one now and then.') }}</p>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the ones we have, and restoring --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($restoring !== '')
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
        @else
            <div class="ribbon-clear">
                <h2 class="heading">{{ __('Your backups') }}</h2>
                <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">{{ __('Kept on this server. Newest first.') }}</p>
            </div>

            @if ($backups->isEmpty())
                <div class="bin-empty">
                    <p class="serif">{{ __('No backups yet.') }}</p>
                    <p>{{ __('Make one now, or switch on nightly backups.') }}</p>
                </div>
            @else
                <ul class="backup-list">
                    @foreach ($backups as $b)
                        @php [$kindLabel, $tone] = $kinds[$b['kind']] ?? $kinds['manual']; @endphp
                        <li wire:key="backup-{{ md5($b['name']) }}" @class(['is-new' => $justMade === $b['name']])>
                            <span class="backup-list__text">
                                <span class="backup-list__date serif">{{ $b['at']->format('j M Y') }} <span>{{ $b['at']->format('g:ia') }}</span></span>
                                <span class="backup-list__meta">
                                    <span class="sticker sticker--{{ $tone }}" style="--tilt: 0deg; font-size: 11px; padding: 2px 8px">{{ $kindLabel }}</span>
                                    {{ $size($b['size']) }} · {{ $b['at']->diffForHumans() }}
                                </span>
                            </span>
                            <span class="backup-list__do">
                                <a href="{{ route('admin.backups.download', $b['name']) }}" class="btn btn--sm">{{ __('Download') }}</a>
                                <button type="button" class="link-button" wire:click="look(@js($b['name']))">{{ __('Restore…') }}</button>
                                <button type="button" class="link-button bin-list__forget" wire:click="remove(@js($b['name']))"
                                        wire:confirm="Delete the backup from {{ $b['at']->format('j M Y, g:ia') }}?">{{ __('Delete') }}</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('admin.backups.upload') }}" enctype="multipart/form-data" class="backup-upload">
                @csrf
                <label for="backup-file" class="field__label">{{ __('Restore from a backup on your computer') }}</label>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                    <input id="backup-file" type="file" name="backup" accept=".zip,application/zip" required>
                    <button type="submit" class="btn btn--sm">{{ __('Upload and look inside') }}</button>
                </div>
                @error('backup') <p class="field__error">{{ $message }}</p> @enderror
                <p class="field__hint">{{ __('Nothing changes until you’ve seen what’s in it and said yes.') }}</p>
            </form>
        @endif

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
