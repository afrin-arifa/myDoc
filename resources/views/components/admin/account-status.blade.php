@props(['status', 'labels'])

@php
    $tone = match ($status) {
        'active' => 'success',
        'invitation_pending' => 'warning',
        'invitation_expired' => 'neutral',
        default => 'danger',
    };
@endphp

<span {{ $attributes->merge(['class' => 'status-badge status-badge--'.$tone]) }}>{{ $labels[$status] ?? ucfirst($status) }}</span>
