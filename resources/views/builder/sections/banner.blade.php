{{-- Big words over a picture. Without a picture, the words on the section's own background. --}}
<div @class(['pb-banner', 'pb-banner--'.$f->get('height', 'short'), 'pb-banner--with-picture' => $f->filled('picture') && \App\PageBuilder\Fields::pictureFor((string) $f->get('picture'))])>
    {{ $f->picture('picture', 'pb-banner__picture', '100vw', $first) }}
    <div class="pb-banner__words">
        {{ $f->text('eyebrow', 'p', 'pb-eyebrow') }}
        {{ $f->text('title', $h1 ? 'h1' : 'h2', 'pb-title pb-title--big') }}
        {{ $f->words('words', 'pb-lede') }}
        @if ($f->has('button.label'))
            <p class="pb-buttons">{{ $f->link('button', 'button button--ink') }}</p>
        @endif
    </div>
</div>
