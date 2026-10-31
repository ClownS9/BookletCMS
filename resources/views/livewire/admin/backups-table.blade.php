@php
    $size = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
@endphp
<div style="display: contents">
    {{-- Backups in the Dashboard layout: making one, nightly, the ones kept here
         in a table, and restoring in the drawer. --}}
    <section class="page page--single page--dash" aria-labelledby="backups-title">
        <div class="dash">
            <div class="dash-head">
                <div class="dash-head__title">
                    <x-admin.crumbs />
                    <h1 id="backups-title">{{ __('Backups') }}</h1>
                    <p>{{ __('The whole book in one file: every post and page, settings, menus, pictures, people and comments.') }}</p>
                </div>
                <button type="button" class="btn btn--ink" wire:click="makeOne" wire:loading.attr="disabled" wire:target="makeOne" @disabled(! $available)>
                    <span wire:loading.remove wire:target="makeOne" style="display: inline-flex; align-items: center; gap: 6px"><x-admin.icon name="plus" size="16" /> {{ __('Make a backup now') }}</span>
                    <span wire:loading wire:target="makeOne">{{ __('Packing the book…') }}</span>
                </button>
            </div>

            @unless ($available)
                <p class="notice notice--error" style="margin: 0">{{ __('This server can’t make .zip files yet (PHP’s zip extension is missing). Ask whoever runs the site to turn it on.') }}</p>
            @endunless
            @if ($justMade)
                <p class="notice" style="margin: 0">{{ __('Ready:') }} <a href="{{ route('admin.backups.download', $justMade) }}">{{ __('download :justmade', ['justmade' => $justMade]) }}</a></p>
            @endif

            <div class="dash-figures">
                <div class="dash-figure dash-figure--switch">
                    <span class="dash-figure__label" id="nightly">{{ __('Back up every night') }}</span>
                    <span class="dash-figure__n"><button type="button" class="switch" role="switch" aria-labelledby="nightly" aria-checked="{{ $nightly ? 'true' : 'false' }}" wire:click="toggleNightly"></button> <span>{{ __('Around 3am, newest :value kept', ['value' => \App\Support\Backups::KEEP_NIGHTLY]) }}</span></span>
                </div>
                <div class="dash-figure" style="--tone: var(--sand)"><span class="dash-figure__label"><i aria-hidden="true"></i>{{ __('Kept here') }}</span><span class="dash-figure__n"><b>{{ $backups->count() }}</b>@if ($backups->isNotEmpty()) <span>{{ $size((int) $backups->sum('size')) }}</span>@endif</span></div>
                <div class="dash-figure" style="--tone: var(--mint)"><span class="dash-figure__label"><i aria-hidden="true"></i>{{ __('Newest') }}</span><span class="dash-figure__n"><b style="font-size: 18px">{{ ($backups->first()['at'] ?? null)?->diffForHumans() ?? '—' }}</b></span></div>
            </div>

            <section class="dash-card" aria-labelledby="your-backups">
                <div class="dash-card__head"><h2 id="your-backups">{{ __('Your backups') }}</h2><small>{{ __('Kept on this server. Newest first.') }}</small></div>
                @if ($backups->isEmpty())
                    <p class="dash-empty">{{ __('No backups yet.') }} {{ __('Make one now, or switch on nightly backups.') }}</p>
                @else
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Made') }}</th>
                                    <th scope="col">{{ __('Kind') }}</th>
                                    <th scope="col" class="is-num">{{ __('Size') }}</th>
                                    <th scope="col" class="is-wide">{{ __('File') }}</th>
                                    <th scope="col"><span class="sr-only">{{ __('More') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($backups as $b)
                                    @php [$kindLabel, $tone] = $kinds[$b['kind']] ?? $kinds['manual']; @endphp
                                    <tr wire:key="backup-{{ md5($b['name']) }}" @class(['is-open' => $justMade === $b['name'] || $restoring === $b['name']])>
                                        <td><span class="dash-table__name">{{ $b['at']->translatedFormat('j M Y, g:ia') }}</span><span class="dash-table__sub dash-table__sub--plain">{{ $b['at']->diffForHumans() }}</span></td>
                                        <td><span class="dash-role" style="--tone: var(--{{ $tone }})">{{ __($kindLabel) }}</span></td>
                                        <td class="is-num">{{ $size($b['size']) }}</td>
                                        <td class="is-wide"><span class="dash-table__sub">{{ $b['name'] }}</span></td>
                                        <td class="dash-table__actions">
                                            <a href="{{ route('admin.backups.download', $b['name']) }}" class="btn btn--sm">{{ __('Download') }}</a>
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="look(@js($b['name']))">{{ __('Restore…') }}</button>
                                            <button type="button" class="btn btn--sm btn--quiet" wire:click="remove(@js($b['name']))" wire:confirm="{{ __('Delete the backup from :when?', ['when' => $b['at']->translatedFormat('j M Y, g:ia')]) }}">{{ __('Delete') }}</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <div class="dash-row" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr))">
                <section class="dash-card" aria-labelledby="upload-title">
                    <div class="dash-card__head"><h2 id="upload-title">{{ __('Restore from a backup on your computer') }}</h2></div>
                    <form method="POST" action="{{ route('admin.backups.upload') }}" enctype="multipart/form-data" class="dash-card__pad" style="display: flex; flex-direction: column; gap: 10px">
                        @csrf
                        <label for="backup-file" class="sr-only">{{ __('A backup .zip') }}</label>
                        <input id="backup-file" type="file" name="backup" accept=".zip,application/zip" required>
                        @error('backup') <p class="field__error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn btn--sm" style="align-self: flex-start">{{ __('Upload and look inside') }}</button>
                        <p class="field__hint" style="margin: 0">{{ __('Nothing changes until you’ve seen what’s in it and said yes.') }}</p>
                    </form>
                </section>
                <section class="dash-card" aria-labelledby="inside-title">
                    <div class="dash-card__head"><h2 id="inside-title">{{ __('What’s inside') }}</h2></div>
                    <div class="dash-card__pad backup-notes" style="margin: 0">
                        <p><strong>{{ __('In every backup:') }}</strong> {{ __('posts, pages and their editions, the page tree and menus, categories and tags, settings (Stacy’s too), files and their descriptions, people and their pictures, comments, sticky notes, dog-ears, redirects and form replies.') }}</p>
                        <p><strong>{{ __('Left out on purpose:') }}</strong> {{ __('AI service keys and the email password (they stay on this server), Stacy’s conversations, visit counts, the activity log and the bin.') }}</p>
                        <p>{{ __('Backups on this server don’t help if the server itself is lost, so download one now and then.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </section>

    @if ($restoring !== '')
        <x-admin.drawer :title="__('Restore from this backup?')" close="cancelRestore">
            @include('livewire.admin.partials.backups-restore')
        </x-admin.drawer>
    @endif
</div>
