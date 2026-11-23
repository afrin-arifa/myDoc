@props(['status'])

@php
    $tone = match ($status) {
        'confirmed', 'completed' => 'success',
        'booked' => 'warning',
        'available', 'expired' => 'neutral',
        default => 'danger',
    };
@endphp

<span {{ $attributes->merge(['class' => 'status-badge status-badge--'.$tone]) }}>{{ App\Models\Appointment::statusLabel($status) }}</span>
