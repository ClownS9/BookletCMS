<x-site.layout title="{{ __('Emails about replies') }}" robots="noindex">
    <div class="wrap wrap--narrow section subscription">
        @switch ($state)
            @case('stopping')
                <h1 class="post-head__title">{{ __('Stop the emails about this conversation?') }}</h1>
                <p class="lede">{{ __('You won’t hear about new replies to the notes under “:title”. The conversation stays on the page.', ['title' => $entry?->title() ?: __('this post')]) }}</p>
                <form method="POST" action="{{ request()->fullUrl() }}" style="margin-top: 24px">
                    @csrf
                    <button type="submit" class="button button--ink">{{ __('Yes, stop them') }}</button>
                </form>
                @break
            @case('stopped')
                <h1 class="post-head__title">{{ __('No more emails about it') }}</h1>
                <p class="lede">{{ __('You won’t hear about new replies under “:title”.', ['title' => $entry?->title() ?: __('this post')]) }}@if ($entry) {!! __('You can still :link any time.', ['link' => '<a href="'.e($site->url($entry)).'#notes-heading">'.e(__('read the conversation')).'</a>']) !!}@endif</p>
                @break
            @default
                <h1 class="post-head__title">{{ __('That link doesn’t work') }}</h1>
                <p class="lede">{{ __('It may be incomplete (some email apps break long links), or the conversation is gone. Try opening it from the email again.') }}</p>
        @endswitch
    </div>
</x-site.layout>
