@extends('layouts.app')

@section('title', 'Appointment details | myDoc')

@section('content')
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4" href="{{ route('doctor.appointments.index') }}">&larr; Back to appointments</a>
        <p class="hero__eyebrow mb-2">Doctor space</p>
        <h1 class="h2 mb-4">Appointment details</h1>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <p><strong>Patient:</strong> {{ $appointment->patient->name }}</p>
                <p><strong>Contact:</strong> {{ $appointment->patient->email }}</p>
                <p><strong>Date and time:</strong> {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p>
                <p><strong>Status:</strong> {{ ucfirst($appointment->status) }}</p>
                @if ($appointment->status === 'booked')
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('doctor.appointments.confirm', $appointment) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit">Confirm</button>
                        </form>
                        <form method="POST" action="{{ route('doctor.appointments.reject', $appointment) }}">
                            @csrf
                            <button class="btn btn-outline-danger" type="submit">Reject</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
