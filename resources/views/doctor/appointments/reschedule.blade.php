@extends('layouts.app')

@section('title', 'Reschedule Appointment | myDoc')

@section('content')
    <div class="container py-5">
        <h1 class="h3 mb-2">Reschedule appointment</h1>
        <p class="text-secondary">Current time: {{ $appointment->slot->starts_at->format('M j, Y g:i A') }}</p>
        <form method="POST" action="{{ route('doctor.appointments.reschedule.store', $appointment) }}" class="card card-body">
            @csrf
            <label class="form-label" for="slot_id">New appointment slot</label>
            <select class="form-select mb-3" id="slot_id" name="slot_id" required>
                @foreach ($slots as $slot)
                    <option value="{{ $slot->id }}">{{ $slot->starts_at->format('M j, Y g:i A') }} – {{ $slot->ends_at->format('g:i A') }}</option>
                @endforeach
            </select>
            <label class="form-label" for="reason">Reason (optional)</label>
            <textarea class="form-control mb-3" id="reason" name="reason" rows="3">{{ old('reason') }}</textarea>
            <button class="btn btn-primary" type="submit">Confirm reschedule</button>
        </form>
    </div>
@endsection
