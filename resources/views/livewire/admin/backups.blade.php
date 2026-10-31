@php
    $size = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
@endphp
<div style="display: contents">
    {{-- Left page: making backups --}}
    <section class="page page--left page--stack" style="flex-basis: 380px">
        <div>
            <div class="page-head">
                <x-admin.crumbs>
                    <a href="{{ route('admin.settings') }}" class="back" wire:navigate><x-admin.icon name="back" size="16" /> {{ __('Settings') }}</a>
                </x-admin.crumbs>
                <p class="eyebrow chapter-mark">{{ \App\Support\AdminNav::chapterLabel() }} · {{ \App\Support\AdminNav::spread()['title'] ?? '' }}</p>
                <h1 class="display display--page" style="margin-top: 8px">{{ __('Backups') }}</h1>
                <p class="lede" style="margin-top: 10px; font-size: 15px">{{ __('The whole book in one file: every post and page, settings, menus, pictures, people and comments. Keep one somewhere safe, and you can always start again from it.') }}</p>
            </div>
        </div>

        <x-admin.card>
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
        </x-admin.card>

        <x-admin.folio side="left" />
    </section>

    {{-- Right page: the ones we have, and restoring --}}
    <section class="page page--right page--stack" style="--ribbon: var(--sand)">
        <div class="ribbon" aria-hidden="true"></div>
        <x-admin.card>
            @if ($restoring !== '')
                @include('livewire.admin.partials.backups-restore')
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
                                        <span class="sticker sticker--{{ $tone }}" style="--tilt: 0deg; font-size: 11px; padding: 2px 8px">{{ __($kindLabel) }}</span>
                                        {{ $size($b['size']) }} · {{ $b['at']->diffForHumans() }}
                                    </span>
                                </span>
                                <span class="backup-list__do">
                                    <a href="{{ route('admin.backups.download', $b['name']) }}" class="btn btn--sm">{{ __('Download') }}</a>
                                    <button type="button" class="link-button" wire:click="look(@js($b['name']))">{{ __('Restore…') }}</button>
                                    <button type="button" class="link-button bin-list__forget" wire:click="remove(@js($b['name']))"
                                            wire:confirm="{{ __('Delete the backup from :when?', ['when' => $b['at']->translatedFormat('j M Y, g:ia')]) }}">{{ __('Delete') }}</button>
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
        </x-admin.card>

        <x-admin.folio side="right" style="margin-top: auto" />
    </section>
</div>
