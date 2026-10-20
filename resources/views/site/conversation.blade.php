<x-site.layout title="Emails about replies" robots="noindex">
    <div class="wrap wrap--narrow section subscription">
        @switch ($state)
            @case('stopping')
                <h1 class="post-head__title">Stop the emails about this conversation?</h1>
                <p class="lede">You won’t hear about new replies to the notes under “{{ $entry?->title() ?: 'this post' }}”. The conversation stays on the page.</p>
                <form method="POST" action="{{ request()->fullUrl() }}" style="margin-top: 24px">
                    @csrf
                    <button type="submit" class="button button--ink">Yes, stop them</button>
                </form>
                @break
            @case('stopped')
                <h1 class="post-head__title">No more emails about it</h1>
                <p class="lede">You won’t hear about new replies under “{{ $entry?->title() ?: 'this post' }}”.@if ($entry) You can still <a href="{{ $site->url($entry) }}#notes-heading">read the conversation</a> any time.@endif</p>
                @break
            @default
                <h1 class="post-head__title">That link doesn’t work</h1>
                <p class="lede">It may be incomplete (some email apps break long links), or the conversation is gone. Try opening it from the email again.</p>
        @endswitch
    </div>
</x-site.layout>
