{{-- One line of news: a sticker, the words, a link. --}}
<p class="pb-notice">
    {{ $f->text('sticker', 'span', 'pb-notice__sticker') }}
    {{ $f->text('words', 'span', 'pb-notice__words') }}
    {{ $f->link('link', 'pb-notice__link') }}
</p>
