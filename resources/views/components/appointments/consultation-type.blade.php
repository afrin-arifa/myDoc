@props(['type'])

@php
    $details = match ($type) {
        'voice' => ['label' => 'Voice call', 'description' => 'Speak with your doctor by phone.', 'class' => 'consultation-type--voice'],
        'video' => ['label' => 'Video call', 'description' => 'Meet your doctor in a secure video visit.', 'class' => 'consultation-type--video'],
        default => ['label' => 'Physical visit', 'description' => 'Meet your doctor in person.', 'class' => 'consultation-type--physical'],
    };
@endphp

<span {{ $attributes->merge(['class' => 'consultation-type '.$details['class']]) }}>
    <strong>{{ $details['label'] }}</strong>
    <small>{{ $details['description'] }}</small>
</span>
