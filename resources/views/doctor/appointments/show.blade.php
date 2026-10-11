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
                <p><strong>Consultation fee:</strong> {{ number_format((float) $appointment->slot->doctorProfile->consultation_fee, 2) }}</p>
                <p><strong>Consultation:</strong> <x-appointments.consultation-type :type="$appointment->consultation_type" /></p>
                <p><strong>Status:</strong> <x-ui.status-badge :status="$appointment->status" /></p>
                @if ($appointment->consultation_type === 'physical')
                    <p><strong>Visit details:</strong> In-person appointment at the configured clinic location.</p>
                @elseif ($appointment->status === 'confirmed')
                    <p><strong>Online visit:</strong> No voice/video provider is configured yet.</p>
                @endif
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
                @if ($canStart)
                    <details class="consultation-launch mt-3">
                        <summary class="btn btn-primary">Start now</summary>
                        <div class="consultation-launch__menu">
                            <p class="small text-secondary mb-2">This is a {{ $appointment->consultation_type }} consultation.</p>
                            <form method="POST" action="{{ route('doctor.appointments.start', $appointment) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary w-100" type="submit">{{ ucfirst($appointment->consultation_type) }} call</button>
                            </form>
                        </div>
                    </details>
                @endif
            </div>
        </div>
    </div>
@endsection
