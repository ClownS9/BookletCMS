{{--
    A members-only post or page, for someone who can't read it (yet): its title, first lines
    and cover, and a way in. Nothing more of it is in the page. A theme can replace this file
    (site/locked.blade.php). Expects $entry, $site, $label, $reader (signed in, or null), $dated.
--}}
<x-site.layout :type="$dated ? 'article' : 'website'" :title="$entry->get('meta_title') ?: $entry->title()" :description="$site->description($entry)" :image="$site->shareImage($entry)">
    @php
        $static = (bool) request()->attributes->get('booklet_static');
        $lede = trim((string) \App\Support\Readers::get('locked_lede'));
        $canJoin = in_array(\App\Support\Readers::joining(), ['open', 'approval'], true);
        $back = $site->url($entry);
    @endphp
    <article class="wrap wrap--narrow section locked">
        <header class="post-head">
            @if ($dated) <a href="{{ $site->blogUrl() }}" class="back-link">{{ __('← The blog') }}</a> @endif
            <p><span class="sticker locked__sticker" style="--tilt: -2deg">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                {{ $label }}
            </span></p>
            <h1 class="post-head__title">{{ $entry->title() }}</h1>
            @if ($entry->get('excerpt')) <p class="post-head__standfirst">{{ $entry->get('excerpt') }}</p> @endif
            @if ($dated && $entry->date()) <p class="byline">{{ $entry->date()->translatedFormat('j F Y') }}</p> @endif
        </header>

        @if ($cover = $site->image($entry))
            <figure class="post-cover"><img src="{{ $cover }}" alt="{{ $site->imageAlt($entry) }}" fetchpriority="high" @if ($srcset = $site->imageSrcset($entry)) srcset="{{ $srcset }}" sizes="(max-width: 760px) 100vw, 720px" @endif style="object-position: {{ $site->imageFocus($entry) }}"></figure>
        @endif

        @unless ($entry->get('excerpt'))
            <p class="locked__teaser">{{ $site->excerpt($entry, 260) }}</p>
        @endunless

        <section class="locked__door" aria-labelledby="locked-title">
            @if ($static)
                <h2 id="locked-title" class="locked__title">{{ __('This one is for members') }}</h2>
                <p>{{ __('Read it on :site, signed in.', ['site' => $site->name()]) }}</p>
            @elseif (! $reader)
                <h2 id="locked-title" class="locked__title">{{ $label === __('Members') ? __('This one is for members') : __('This one is for :groups', ['groups' => $label]) }}</h2>
                <p>{{ $lede !== '' ? $lede : ($canJoin ? __('Sign in to read the rest, or make an account: it’s free.') : __('Sign in to read the rest.')) }}</p>
                <div class="locked__actions">
                    <a href="{{ route('readers.sign-in', ['back' => $back]) }}" class="button button--ink">{{ __('Sign in') }}</a>
                    @if ($canJoin) <a href="{{ route('readers.join') }}" class="button">{{ __('Make an account') }}</a> @endif
                </div>
            @elseif (! $reader->confirmed())
                <h2 id="locked-title" class="locked__title">{{ __('Confirm your email first') }}</h2>
                <p>{{ __('Press the button in the email we sent to :email, then come back here.', ['email' => $reader->email]) }}</p>
                <div class="locked__actions"><a href="{{ route('readers.home') }}" class="button">{{ __('Your account') }}</a></div>
            @elseif ($reader->state === 'waiting')
                <h2 id="locked-title" class="locked__title">{{ __('Nearly there') }}</h2>
                <p>{{ __('You can read this once someone at :site lets you in. We’ll email you when they have.', ['site' => $site->name()]) }}</p>
            @else
                <h2 id="locked-title" class="locked__title">{{ __('This one is for :groups', ['groups' => $label]) }}</h2>
                <p>{{ __('Your account isn’t in that group yet. Your account page shows the groups you can join or ask to join.') }}</p>
                <div class="locked__actions"><a href="{{ route('readers.home') }}" class="button">{{ __('Your account') }}</a></div>
            @endif
        </section>
    </article>
</x-site.layout>
