{{-- "Read in: English · Bahasa Melayu · 日本語": every language this post or page is in --}}
@props(['entry'])
@php
    $versions = app(\App\Booklet\Site::class)->languages($entry);
    $here = $versions->firstWhere('current', true)['code'] ?? 'en';
    $label = ['ms' => 'Baca dalam', 'id' => 'Baca dalam', 'zh' => '语言', 'ja' => '言語', 'ko' => '언어', 'fr' => 'Lire en', 'es' => 'Leer en', 'de' => 'Lesen auf', 'it' => 'Leggi in', 'pt' => 'Ler em', 'nl' => 'Lees in', 'ar' => 'اقرأ بـ', 'ta' => 'மொழி', 'th' => 'อ่านเป็น', 'vi' => 'Đọc bằng', 'tl' => 'Basahin sa', 'hi' => 'भाषा'][$here] ?? 'Read in';
@endphp
@if ($versions->count() > 1)
    <nav class="langs" aria-label="{{ __('Read this in another language') }}">
        <span class="langs__label">{{ $label }}</span>
        <ul>
            @foreach ($versions as $version)
                <li>
                    @if ($version['current'])
                        <span class="langs__here" aria-current="page" lang="{{ $version['code'] }}">{{ $version['name'] }}</span>
                    @else
                        <a href="{{ $version['url'] }}" hreflang="{{ $version['code'] }}" lang="{{ $version['code'] }}" rel="alternate">{{ $version['name'] }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
@endif
