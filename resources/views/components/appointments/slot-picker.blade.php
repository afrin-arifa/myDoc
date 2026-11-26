@props(['slots', 'name' => 'slot_id', 'selected' => null, 'legend' => 'Choose a time'])
{{--
    Open times for one day as buttons, grouped by location (when there is more than one) and by part of the day. Each
    button is a radio input, so the form submits the chosen slot id exactly as a select would.
--}}
@php
    $selected = (int) old($name, $selected);
    $byLocation = $slots->groupBy(fn ($slot) => $slot->doctor_location_id ?? 0);
    $periodIcons = ['Morning' => 'fa-regular fa-sun', 'Afternoon' => 'fa-solid fa-cloud-sun', 'Evening' => 'fa-regular fa-moon'];
@endphp
<fieldset {{ $attributes->merge(['class' => 'slot-picker']) }}>
    <legend class="form-label">{{ $legend }}</legend>
    @foreach ($byLocation as $locationSlots)
        <p class="slot-picker__location"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>{{ $locationSlots->first()->location?->name ?? 'Location not specified' }}</p>
        @foreach ($locationSlots->groupBy(fn ($slot) => match (true) { $slot->starts_at->hour < 12 => 'Morning', $slot->starts_at->hour < 17 => 'Afternoon', default => 'Evening' }) as $period => $periodSlots)
            <p class="doctor-detail__period"><i class="{{ $periodIcons[$period] }}" aria-hidden="true"></i>{{ $period }} <span class="slot-picker__count">{{ $periodSlots->count() }} {{ Str::plural('time', $periodSlots->count()) }}</span></p>
            <div class="slot-grid mb-3">
                @foreach ($periodSlots as $slot)
                    <label class="slot-option">
                        <input class="slot-option__input" type="radio" name="{{ $name }}" value="{{ $slot->id }}" required @checked($selected === $slot->id)
                            aria-label="{{ $slot->starts_at->format('g:i A') }} to {{ $slot->ends_at->format('g:i A') }}">
                        <span class="slot-time">{{ $slot->starts_at->format('g:i A') }}<small>until {{ $slot->ends_at->format('g:i') }}</small></span>
                    </label>
                @endforeach
            </div>
        @endforeach
    @endforeach
    @error($name)<div class="text-danger small">{{ $message }}</div>@enderror
</fieldset>
