@props(['status'])

@php
    $tone = match ($status) {
        'confirmed', 'completed' => 'success',
        'booked' => 'warning',
        'available' => 'neutral',
        default => 'danger',
    };
@endphp

<span {{ $attributes->merge(['class' => 'status-badge status-badge--'.$tone]) }}>{{ ucfirst($status) }}</span>
