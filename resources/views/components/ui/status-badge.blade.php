@props(['status'])

@php
    $tone = match ($status) {
        'confirmed' => 'success',
        'booked' => 'warning',
        default => 'danger',
    };
@endphp

<span {{ $attributes->merge(['class' => 'status-badge status-badge--'.$tone]) }}>{{ ucfirst($status) }}</span>
