@extends('layouts.app')

@section('title', 'Appointment details | myDoc')

@section('content')
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4" href="{{ route('patient.appointments.index') }}">
            &larr; Back to appointments
        </a>
        <p class="hero__eyebrow mb-2">Appointment details</p>
        <h1 class="h2 mb-4">{{ $appointment->slot->doctorProfile->user->name }}</h1>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <p><strong>Date and time:</strong> {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p>
                <p><strong>Professional title:</strong> {{ $appointment->slot->doctorProfile->professional_title }}</p>
                <p><strong>Specialty:</strong> {{ $appointment->slot->doctorProfile->specialties->pluck('name')->join(', ') ?: 'General care' }}</p>
                <p><strong>Consultation fee:</strong> {{ number_format((float) $appointment->slot->doctorProfile->consultation_fee, 2) }}</p>
                <p><strong>Consultation:</strong> <x-appointments.consultation-type :type="$appointment->consultation_type" /></p>
                <p><strong>Status:</strong> <x-ui.status-badge :status="$appointment->status" /></p>
                @if ($appointment->consultation_type === 'physical')
                    <p><strong>Visit details:</strong> In-person appointment at the configured clinic location.</p>
                @else
                    <p><strong>Online visit:</strong> {{ $appointment->status === 'confirmed' ? 'Your call instructions will appear when a provider is configured.' : 'Call instructions will be available after confirmation.' }}</p>
                @endif
                @if (in_array($appointment->status, ['booked', 'confirmed'], true))
                    <form method="POST" action="{{ route('appointments.cancel', $appointment) }}">
                        @csrf
                        <button class="btn btn-outline-danger" type="submit">Cancel appointment</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection
