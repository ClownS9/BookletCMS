@if ($update)
    <div class="update-notice" role="status">
        <span class="sticker sticker--sun" style="--tilt: -4deg">{{ __('New') }}</span>
        <span style="min-width: 0">
            <a href="{{ route('admin.settings.updates') }}" wire:navigate class="update-notice__title">{{ __('Booklet :version is out', ['version' => $update['version']]) }}</a>
            @if ($update['line'] !== '') <small>{{ __($update['line']) }}</small> @endif
        </span>
        <span class="update-notice__do">
            <a href="{{ route('admin.settings.updates') }}" wire:navigate class="btn btn--sm">{{ __('See what changed') }}</a>
            <button type="button" class="link-button" wire:click="notNowUpdate">{{ __('Not now') }}</button>
        </span>
    </div>
@endif
