{{-- The Dashboard layout's sidebar: your stickers as Content, your tabs as
     Tools, then Settings, each with its pages in a sub-menu; at the foot who
     else is here. Your own menu is on your picture in the top bar. --}}
@php
    $user = request()->user();
    $side = \App\Support\AdminNav::sidebar($user);
    $waitingComments = collect(\App\Support\AdminNav::waiting())->firstWhere('key', 'comments')['count'] ?? 0;
    $here = rescue(fn () => \App\Models\User::query()->whereKeyNot($user->id)->where('last_seen_at', '>', now()->subMinutes(5))->orderByDesc('last_seen_at')->take(3)->get(), collect(), false);
@endphp
<aside class="sidebar" id="desk-sidebar" aria-label="{{ __('Booklet') }}">
    @foreach (['content' => __('Content'), 'tools' => __('Tools'), 'settings' => __('Back of the book')] as $part => $title)
        @if ($side[$part] !== [])
            <p class="sidebar__group" id="sidebar-{{ $part === 'settings' ? 'back' : $part }}">{{ $title }}</p>
            <nav aria-labelledby="sidebar-{{ $part === 'settings' ? 'back' : $part }}" class="sidebar__list">
                @foreach ($side[$part] as $item)
                    <x-admin.sidebar-item :item="$item" :waiting-comments="$waitingComments" />
                @endforeach
            </nav>
        @endif
    @endforeach

    {{-- Who's here, and you --}}
    <div class="sidebar__foot">
        @if ($here->isNotEmpty())
            <a href="{{ route('admin.people') }}" class="sidebar__here" wire:navigate>
                <span class="sidebar__faces" aria-hidden="true">
                    @foreach ($here as $person)<x-admin.avatar :user="$person" size="26" presence />@endforeach
                </span>
                <span>{{ trans_choice(':name is here too|:name and :count more are here too', $here->count(), ['name' => str($here->first()->name)->before(' '), 'count' => $here->count() - 1]) }}</span>
            </a>
        @endif

    </div>
</aside>
