@php
    $size = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
    $label = [
        'entries' => ['entry', 'entries'], 'assets' => ['file detail', 'file details'], 'terms' => ['category or tag', 'categories & tags'],
        'collections' => ['chapter', 'chapters'], 'trees' => ['page tree or menu', 'page trees & menus'], 'globalVariables' => ['setting group', 'setting groups'],
        'revisions' => ['edition', 'editions'], 'submissions' => ['form reply', 'form replies'],
    ];
@endphp
<div style="display: contents">
    {{-- Left page: making backups --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> Settings</a>
            <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
            <h1 class="display display--page" style="margin-top: 8px">Backups</h1>
            <p class="lede" style="margin-top: 10px; font-size: 15px">The whole book in one file: every post and page, settings, menus, pictures, people and comments. Keep one somewhere safe, and you can always start again from it.</p>
        </div>

        @unless ($available)
            <p class="field__error">This server can’t make .zip files yet (PHP’s zip extension is missing). Ask whoever runs the site to turn it on.</p>
        @endunless

        <div>
            <button type="button" class="btn btn--ink btn--lg" wire:click="makeOne" wire:loading.attr="disabled" wire:target="makeOne" @disabled(! $available)>
                <span wire:loading.remove wire:target="makeOne">Make a backup now</span>
                <span wire:loading wire:target="makeOne">Packing the book…</span>
            </button>
            @if ($justMade)
                <p class="backup-made">
                    Ready: <a href="{{ route('admin.backups.download', $justMade) }}">download {{ $justMade }}</a>
                </p>
            @endif
        </div>

        <div class="setting-rows">
            <div class="setting-row">
                <span>
                    <span id="nightly" style="display: block; font-size: 15px; font-weight: 500">Back up every night</span>
                    <span style="display: block; font-size: 13px; color: var(--faint)">Around 3am. The newest {{ \App\Support\Backups::KEEP_NIGHTLY }} are kept on this server; older ones are cleared away.</span>
                </span>
                <button type="button" class="switch" role="switch" aria-labelledby="nightly" aria-checked="{{ $nightly ? 'true' : 'false' }}" wire:click="toggleNightly"></button>
            </div>
        </div>

        <div class="backup-notes">
            <p><strong>In every backup:</strong> posts, pages and their editions, the page tree and menus, categories and tags, settings (Stacy’s too), files and their descriptions, people and their pictures, comments, sticky notes, dog-ears, redirects and form replies.</p>
            <p><strong>Left out on purpose:</strong> AI service keys and the email password (they stay on this server), Stacy’s conversations, visit counts, the activity log and the bin.</p>
            <p>Backups on this server don’t help if the server itself is lost, so download one now and then.</p>
        </div>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the ones we have, and restoring --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>

        @if ($restoring !== '')
            <div class="restore-card ribbon-clear" wire:key="restore-{{ $restoring }}">
                <h2 class="heading">Restore from this backup?</h2>
                @if ($problem)
                    <p class="field__error" style="margin-top: 10px">{{ $problem }}</p>
                    <button type="button" class="btn" style="margin-top: 14px" wire:click="cancelRestore">Back to the list</button>
                @elseif ($manifest)
                    <dl class="restore-card__facts">
                        <div><dt>From</dt><dd>{{ $manifest['site'] ?? 'A Booklet site' }}</dd></div>
                        <div><dt>Made</dt><dd>{{ \Illuminate\Support\Carbon::parse($manifest['created_at'])->format('j M Y, g:ia') }}{{ ! empty($manifest['by']) ? ' by '.$manifest['by'] : '' }}</dd></div>
                        <div>
                            <dt>Holds</dt>
                            <dd>
                                @php
                                    $c = $manifest['counts'] ?? [];
                                    $parts = [];
                                    foreach (['entries', 'assets', 'terms', 'revisions', 'submissions'] as $k) {
                                        $n = (int) ($c['content'][$k] ?? 0);
                                        if ($n) $parts[] = number_format($n).' '.($n === 1 ? $label[$k][0] : $label[$k][1]);
                                    }
                                    foreach (['users' => ['person', 'people'], 'comments' => ['comment', 'comments'], 'redirects' => ['redirect', 'redirects']] as $k => [$one, $many]) {
                                        $n = (int) ($c['tables'][$k] ?? 0);
                                        if ($n) $parts[] = number_format($n).' '.($n === 1 ? $one : $many);
                                    }
                                    if ($c['files'] ?? 0) $parts[] = number_format($c['files']).' '.str('file')->plural($c['files']);
                                @endphp
                                {{ $parts ? implode(', ', $parts) : 'Nothing much' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="restore-card__warn">
                        <p><strong>Everything in the book now is replaced</strong> by what’s in this backup: posts, pages, settings, menus, comments and redirects. Pictures in the backup are put back; any others stay.</p>
                        <p>Before anything changes, a backup of the book as it is now is made, so you can go back. People are matched by email: everyone here keeps their password, and anyone missing comes back.</p>
                    </div>

                    <form wire:submit="restore" class="restore-card__go">
                        <div class="field">
                            <label for="confirm-word">Type <strong>restore</strong> to go ahead</label>
                            <input id="confirm-word" type="text" wire:model="confirmWord" autocomplete="off" spellcheck="false" style="max-width: 220px">
                            @error('confirmWord') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap">
                            <button type="submit" class="btn btn--danger" wire:loading.attr="disabled" wire:target="restore">
                                <span wire:loading.remove wire:target="restore">Restore the book</span>
                                <span wire:loading wire:target="restore">Restoring…</span>
                            </button>
                            <button type="button" class="btn btn--quiet" wire:click="cancelRestore">Not now</button>
                        </div>
                    </form>
                @endif
            </div>
        @else
            <div class="ribbon-clear">
                <h2 class="heading">Your backups</h2>
                <p style="margin-top: 6px; font-size: 15px; color: var(--muted)">Kept on this server. Newest first.</p>
            </div>

            @if ($backups->isEmpty())
                <div class="bin-empty">
                    <p class="serif">No backups yet.</p>
                    <p>Make one now, or switch on nightly backups.</p>
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
                                <a href="{{ route('admin.backups.download', $b['name']) }}" class="btn btn--sm">Download</a>
                                <button type="button" class="link-button" wire:click="look(@js($b['name']))">Restore…</button>
                                <button type="button" class="link-button bin-list__forget" wire:click="remove(@js($b['name']))"
                                        wire:confirm="Delete the backup from {{ $b['at']->format('j M Y, g:ia') }}?">Delete</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('admin.backups.upload') }}" enctype="multipart/form-data" class="backup-upload">
                @csrf
                <label for="backup-file" class="field__label">Restore from a backup on your computer</label>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                    <input id="backup-file" type="file" name="backup" accept=".zip,application/zip" required>
                    <button type="submit" class="btn btn--sm">Upload and look inside</button>
                </div>
                @error('backup') <p class="field__error">{{ $message }}</p> @enderror
                <p class="field__hint">Nothing changes until you’ve seen what’s in it and said yes.</p>
            </form>
        @endif

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
