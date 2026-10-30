{{-- A group's invitation link, followed by someone not signed in. --}}
<x-site.layout :title="__('An invitation')" robots="noindex">
    <div class="wrap wrap--narrow section reader-desk">
        <div class="reader-card">
            <span class="sticker" style="--tilt: -3deg">{{ __('Invited') }}</span>
            <h1 class="reader-card__title">{{ __('Join :group', ['group' => $group->name]) }}</h1>
            @if ($group->about) <p class="reader-card__lede">{{ $group->about }}</p> @endif
            <p class="reader-card__lede">{{ __('Make an account, or sign in if you have one, and you’re in the group.') }}</p>
            <div class="reader-card__actions">
                <a href="{{ route('readers.join') }}" class="button button--ink">{{ __('Make an account') }}</a>
                <a href="{{ route('readers.sign-in') }}" class="button">{{ __('Sign in') }}</a>
            </div>
        </div>
    </div>
</x-site.layout>
