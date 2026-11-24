@props(['name', 'src' => null, 'size' => 'md'])

{{-- Photo when there is one, otherwise initials on a tint derived from the name. Decorative: the name is always shown beside it. --}}
@php
    $words = collect(preg_split('/\s+/', trim((string) $name)))
        ->reject(fn (string $word): bool => $word === '' || in_array(strtolower(rtrim($word, '.')), ['dr', 'prof'], true))
        ->values();
    $initials = $words->isEmpty() ? '?' : mb_strtoupper(mb_substr($words->first(), 0, 1).($words->count() > 1 ? mb_substr($words->last(), 0, 1) : ''));
    $tint = crc32((string) $name) % 6;
@endphp

@if ($src)
    <img {{ $attributes->merge(['class' => 'ui-avatar ui-avatar--'.$size]) }} src="{{ $src }}" alt="" loading="lazy" decoding="async">
@else
    <span {{ $attributes->merge(['class' => "ui-avatar ui-avatar--{$size} ui-avatar--tint-{$tint}"]) }} aria-hidden="true">{{ $initials }}</span>
@endif
