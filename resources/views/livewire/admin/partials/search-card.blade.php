{{-- Search & sharing: how this shows up in Google and when its link is shared, with its own search title and description. Live while typing. --}}
@php
    $g = $searchCard['google'];
    $sh = $searchCard['share'];
    $n = $searchCard['counts'];
    $warns = collect($searchCard['hints'])->where('level', 'warn')->count();
@endphp
<section class="search-card" aria-labelledby="search-card-title" x-data="{ view: 'google' }">
    <div class="search-card__head">
        <h2 id="search-card-title" class="field__label" style="margin: 0">Search &amp; sharing</h2>
        @if ($warns) <span class="team-notes__count search-card__count" title="Things to look at">{{ $warns }}</span> @endif
    </div>

    <div class="search-card__tabs" role="tablist" aria-label="Where it shows">
        <button type="button" role="tab" :aria-selected="(view === 'google').toString()" x-on:click="view = 'google'">Google</button>
        <button type="button" role="tab" :aria-selected="(view === 'facebook').toString()" x-on:click="view = 'facebook'">Facebook</button>
        <button type="button" role="tab" :aria-selected="(view === 'whatsapp').toString()" x-on:click="view = 'whatsapp'">WhatsApp</button>
    </div>

    {{-- Google --}}
    <div class="serp" x-show="view === 'google'" role="tabpanel" aria-label="As a Google result">
        <div class="serp__source">
            <span class="serp__icon" aria-hidden="true">@if ($g['icon']) <img src="{{ $g['icon'] }}" alt=""> @else {{ \Illuminate\Support\Str::substr($g['site'], 0, 1) }} @endif</span>
            <span class="serp__where"><span class="serp__site">{{ $g['site'] }}</span><span class="serp__crumbs">{{ $g['crumbs'] }}</span></span>
        </div>
        <p class="serp__title">{{ $g['title'] }}</p>
        <p class="serp__text">@if ($g['date']) <span class="serp__date">{{ $g['date'] }} — </span>@endif{{ $g['description'] ?: 'Google picks some words from the page.' }}</p>
    </div>

    {{-- Facebook (and LinkedIn, Slack, X: much the same card) --}}
    <div class="share-fb" x-show="view === 'facebook'" x-cloak role="tabpanel" aria-label="Shared on Facebook">
        @if ($sh['image'])
            <img class="share-fb__picture" src="{{ $sh['image'] }}" alt="" loading="lazy">
        @else
            <div class="share-fb__picture share-fb__picture--none" aria-hidden="true">No picture</div>
        @endif
        <div class="share-fb__words">
            <span class="share-fb__host">{{ $sh['host'] }}</span>
            <span class="share-fb__title">{{ $sh['title'] }}</span>
            <span class="share-fb__text">{{ $sh['description'] }}</span>
        </div>
    </div>

    {{-- WhatsApp --}}
    <div class="share-wa" x-show="view === 'whatsapp'" x-cloak role="tabpanel" aria-label="Shared on WhatsApp">
        <div class="share-wa__bubble">
            <div class="share-wa__card">
                @if ($sh['image']) <img class="share-wa__picture" src="{{ $sh['image'] }}" alt="" loading="lazy"> @endif
                <div class="share-wa__words">
                    <span class="share-wa__title">{{ $sh['title'] }}</span>
                    <span class="share-wa__text">{{ \Illuminate\Support\Str::limit($sh['description'], 80, '…') }}</span>
                    <span class="share-wa__host">{{ \Illuminate\Support\Str::lower($sh['host']) }}</span>
                </div>
            </div>
            <span class="share-wa__link">{{ \Illuminate\Support\Str::lower($sh['host']) }}/…</span>
            <span class="share-wa__time" aria-hidden="true">{{ now()->timezone(config('app.timezone'))->format('g:i a') }} ✓✓</span>
        </div>
    </div>

    @if (in_array('meta_title', $searchCard['fields'], true))
        <div class="field search-card__field">
            <label for="field-meta_title">Search title <span class="muted">(optional)</span></label>
            <input id="field-meta_title" type="text" wire:model.live.debounce.500ms="values.meta_title" maxlength="120" placeholder="{{ $values['title'] ?? '' }}">
            <span @class(['search-card__counter', 'is-over' => $n['title'] > \App\Support\SearchCard::TITLE_MAX])>{{ $n['title'] }} / {{ \App\Support\SearchCard::TITLE_MAX }} with the site’s name</span>
        </div>
    @endif
    @if (in_array('meta_description', $searchCard['fields'], true))
        <div class="field search-card__field">
            <label for="field-meta_description">Search description</label>
            <textarea id="field-meta_description" rows="3" wire:model.live.debounce.500ms="values.meta_description" maxlength="300" placeholder="One or two sentences on what readers will find. About 150 letters."></textarea>
            <span @class(['search-card__counter', 'is-over' => $n['description'] > \App\Support\SearchCard::DESCRIPTION_MAX, 'is-short' => $n['description'] > 0 && $n['description'] < \App\Support\SearchCard::DESCRIPTION_MIN])>{{ $n['description'] }} / {{ \App\Support\SearchCard::DESCRIPTION_MAX }}</span>
        </div>
    @endif

    @if ($searchCard['hints'] === [])
        <p class="search-card__ok"><span aria-hidden="true">✓</span> Ready for search and sharing.</p>
    @else
        <ul class="search-card__hints">
            @foreach ($searchCard['hints'] as $hint)
                <li class="search-card__hint search-card__hint--{{ $hint['level'] }}">
                    {{ $hint['text'] }}
                    @if (! empty($hint['href'])) <a href="{{ $hint['href'] }}" wire:navigate>Open it</a> @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
