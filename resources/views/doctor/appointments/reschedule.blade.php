@extends('layouts.app')

@section('title', 'Reschedule Appointment | myDoc')

@section('content')
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4" href="{{ $workspace->url('doctor.appointments.show', $appointment) }}">&larr; Back to appointment</a>
        <x-assistant.workspace-banner class="mb-4" />
        <h1 class="h3 mb-2">Reschedule appointment</h1>
        <p class="text-secondary">{{ $appointment->patient->name }} · current time: {{ $appointment->slot->starts_at->format('M j, Y g:i A') }} at {{ $appointment->slot->location?->name ?? 'location not specified' }}</p>
        @if (! $hasOpenSlots)
            <x-ui.empty-state title="No available slots"
                message="There are no free future slots to move this appointment to. Generate new slots from the schedule first."
                action-label="Open schedule" :action-url="$workspace->url('doctor.schedule')" />
        @else
            <form class="mb-3" method="GET" action="{{ $workspace->url('doctor.appointments.reschedule', $appointment) }}">
                <label class="form-label" for="date">Day</label>
                <div class="input-group"><input class="form-control" id="date" name="date" type="date" min="{{ today()->toDateString() }}" value="{{ $selectedDate->toDateString() }}"><button class="btn btn-outline-primary" type="submit">Show slots</button></div>
            </form>
        @endif
        @if ($hasOpenSlots && $slots->isEmpty())
            <p class="text-secondary">No free slots on {{ $selectedDate->format('D, M j, Y') }}. Choose another day.</p>
        @elseif ($hasOpenSlots)
            <form method="POST" action="{{ $workspace->url('doctor.appointments.reschedule.store', $appointment) }}" class="card card-body">
                @csrf
                <label class="form-label" for="slot_id">New appointment slot</label>
                <select class="form-select mb-3" id="slot_id" name="slot_id" required>
                    @foreach ($slots as $slot)
                        <option value="{{ $slot->id }}" @selected(old('slot_id') == $slot->id)>{{ $slot->starts_at->format('D, M j, Y g:i A') }} – {{ $slot->ends_at->format('g:i A') }} · {{ $slot->location?->name ?? 'Location not specified' }}</option>
                    @endforeach
                </select>
                <label class="form-label" for="reason">Reason (optional)</label>
                <textarea class="form-control mb-3" id="reason" name="reason" rows="3">{{ old('reason') }}</textarea>
                <button class="btn btn-primary" type="submit">Confirm reschedule</button>
            </form>
        @endif
    </div>
@endsection
