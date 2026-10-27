<x-site.layout title="{{ __('New posts by email') }}" robots="noindex">
    <div class="wrap wrap--narrow section subscription">
        @switch ($state)
            @case('confirmed')
                <span class="sticker" style="--tilt: -3deg">{{ __('You’re in') }}</span>
                <h1 class="post-head__title" style="margin-top: 18px">{{ __('Thank you!') }}</h1>
                <p class="lede">{{ __('New posts from :site will come to :email. Every email has a link to stop, any time.', ['site' => $site->name(), 'email' => $subscriber->email]) }}</p>
                <p style="margin-top: 24px"><a href="{{ $site->blogUrl() }}" class="button button--ink">{{ __('Read the latest') }}</a></p>
                @break
            @case('leaving')
                <h1 class="post-head__title">{{ __('Stop the emails?') }}</h1>
                <p class="lede">{{ __(':email won’t get new posts from :site any more.', ['site' => $site->name(), 'email' => $subscriber->email]) }}</p>
                <form method="POST" action="{{ route('site.unsubscribe.leave', $subscriber->token) }}" style="margin-top: 24px">
                    @csrf
                    <button type="submit" class="button button--ink">{{ __('Yes, unsubscribe me') }}</button>
                </form>
                @break
            @case('left')
                <h1 class="post-head__title">{{ __('You’re unsubscribed') }}</h1>
                <p class="lede">{{ __('No more emails to :email. Sorry to see you go. If you change your mind, the sign-up is at the bottom of every post.', ['email' => $subscriber->email]) }}</p>
                @break
            @default
                <h1 class="post-head__title">{{ __('That link has expired') }}</h1>
                <p class="lede">{{ __('It may have been used already, or replaced by a newer one. Sign up again at the bottom of any post if you’d like new posts by email.') }}</p>
        @endswitch
    </div>
</x-site.layout>
