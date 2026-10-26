<x-site.layout :title="$entry->get('meta_title') ?: (request()->is('/') ? null : $entry->title())" :description="$site->description($entry)" :image-alt="$site->imageAlt($entry) ?: null" :image="$site->shareImage($entry) ?? \App\Support\ShareImage::urlFor($entry)">
    @php \App\Support\StructuredData::page($entry); @endphp
    @php
        $wide = in_array($template, ['full_width', 'landing'], true);
        $trail = $site->breadcrumbs($entry);
        $contact = $site->contactPage();
        $people = $entry->get('show_people', $entry->slug() === 'about') ? \App\Models\User::orderBy('created_at')->get() : collect();
    @endphp

    @if ($template === 'landing')
        <section class="landing-hero">
            <div class="wrap">
                <h1>{{ $entry->title() }}</h1>
                @if ($entry->get('excerpt') ?: $site->tagline()) <p>{{ $entry->get('excerpt') ?: $site->tagline() }}</p> @endif
            </div>
        </section>
    @endif

    <article @class(['wrap', 'section', 'wrap--narrow' => ! $wide])>
        @unless ($template === 'landing')
            <header class="post-head">
                @if ($trail !== [])
                    <nav class="crumbs" aria-label="You are here">
                        <a href="{{ url('/') }}">Home</a>
                        @foreach ($trail as $crumb)
                            <span aria-hidden="true">/</span>
                            <a href="{{ $crumb['url'] }}">{{ $crumb['title'] }}</a>
                        @endforeach
                        <span aria-hidden="true">/</span>
                        <span aria-current="page">{{ $entry->title() }}</span>
                    </nav>
                @elseif (! request()->is('/'))
                    <nav class="crumbs" aria-label="You are here">
                        <a href="{{ url('/') }}">Home</a> <span aria-hidden="true">/</span> <span aria-current="page">{{ $entry->title() }}</span>
                    </nav>
                @endif
                <h1 class="post-head__title">{{ $entry->title() }}</h1>
                @if ($entry->get('excerpt'))
                    <p class="post-head__standfirst">{{ $entry->get('excerpt') }}</p>
                @endif
                <x-site.languages :entry="$entry" />
            </header>
        @endunless

        @if ($cover = $site->image($entry))
            <figure class="post-cover"><img src="{{ $cover }}" alt="{{ $site->imageAlt($entry) }}" fetchpriority="high" @if ($srcset = $site->imageSrcset($entry)) srcset="{{ $srcset }}" sizes="(max-width: 1140px) 100vw, 1100px" @endif style="object-position: {{ $site->imageFocus($entry) }}"></figure>
        @endif

        <div @class(['prose', 'prose--wide' => $wide])>
            {{ $site->markdown($entry->get('content')) }}
        </div>
        <div class="blocks-room">{{ $site->blocks($entry) }}</div>
        {{ $site->extras($entry) }}

        @if ($contact && $contact->id() === $entry->id() && \App\Support\Inbox::open())
            <x-site.contact-form :entry="$entry" />
        @endif

        @if ($people->isNotEmpty())
            <section class="people" aria-labelledby="people-title">
                <h2 id="people-title" class="serif">The people behind it</h2>
                <ul>
                    @foreach ($people as $person)
                        <li>
                            <x-site.portrait :user="$person" size="56" />
                            <span>
                                <span class="serif" style="display: block; font-size: 19px">{{ $person->byline ?: $person->name }}</span>
                                <span class="muted" style="display: block; font-size: 14px">{{ ['admin' => 'Editor-in-chief', 'editor' => 'Editor', 'writer' => 'Writer'][$person->role] ?? $person->roleName() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($contact && $contact->id() !== $entry->id() && $people->isNotEmpty())
            <aside class="say-hello">
                <p class="serif">Want to say hello?</p>
                <a href="{{ $site->url($contact) }}" class="button button--ink">Get in touch</a>
            </aside>
        @endif
    </article>

    @if ($builder ?? null)
        {{-- People who can build it: "Build this page", and the editor. Readers never get either. --}}
        @include('builder.editor', $builder)
    @endif
</x-site.layout>
