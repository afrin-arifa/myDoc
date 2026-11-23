@props(['location' => null, 'compact' => false])
{{--
    Where an appointment or slot is held. The location comes from the slot, which keeps the location of the weekly
    window that generated it. Slots created before locations existed have none.
--}}
@if ($location === null)
    <span {{ $attributes->merge(['class' => 'text-secondary']) }}>Location not specified</span>
@elseif ($compact)
    <span {{ $attributes }}><i class="fa-solid fa-location-dot text-secondary me-1" aria-hidden="true"></i>{{ $location->name }}@unless ($location->is_active ?? true) <span class="badge text-bg-light">Archived</span>@endunless</span>
@else
    <span {{ $attributes->merge(['class' => 'd-block']) }}>
        <strong>{{ $location->name }}</strong>
        <span class="d-block small text-secondary">{{ collect([$location->address, $location->area])->filter()->join(', ') ?: 'Address not provided yet' }}</span>
        @if ($location->phone)
            <span class="d-block small text-secondary">Phone: {{ $location->phone }}</span>
        @endif
        @if ($location->directions)
            <span class="d-block small text-secondary">{{ $location->directions }}</span>
        @endif
    </span>
@endif
