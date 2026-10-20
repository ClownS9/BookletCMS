<x-site.layout title="New posts by email" robots="noindex">
    <div class="wrap wrap--narrow section subscription">
        @switch ($state)
            @case('confirmed')
                <span class="sticker" style="--tilt: -3deg">You’re in</span>
                <h1 class="post-head__title" style="margin-top: 18px">Thank you!</h1>
                <p class="lede">New posts from {{ $site->name() }} will come to {{ $subscriber->email }}. Every email has a link to stop, any time.</p>
                <p style="margin-top: 24px"><a href="{{ $site->blogUrl() }}" class="button button--ink">Read the latest</a></p>
                @break
            @case('leaving')
                <h1 class="post-head__title">Stop the emails?</h1>
                <p class="lede">{{ $subscriber->email }} won’t get new posts from {{ $site->name() }} any more.</p>
                <form method="POST" action="{{ route('site.unsubscribe.leave', $subscriber->token) }}" style="margin-top: 24px">
                    @csrf
                    <button type="submit" class="button button--ink">Yes, unsubscribe me</button>
                </form>
                @break
            @case('left')
                <h1 class="post-head__title">You’re unsubscribed</h1>
                <p class="lede">No more emails to {{ $subscriber->email }}. Sorry to see you go. If you change your mind, the sign-up is at the bottom of every post.</p>
                @break
            @default
                <h1 class="post-head__title">That link has expired</h1>
                <p class="lede">It may have been used already, or replaced by a newer one. Sign up again at the bottom of any post if you’d like new posts by email.</p>
        @endswitch
    </div>
</x-site.layout>
