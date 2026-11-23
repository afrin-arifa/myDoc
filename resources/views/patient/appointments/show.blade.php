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
                <div class="mb-3"><strong>Location:</strong> <x-appointments.location :location="$appointment->slot->location" /></div>
                <p><strong>Professional title:</strong> {{ $appointment->slot->doctorProfile->professional_title }}</p>
                <p><strong>Specialty:</strong> {{ $appointment->slot->doctorProfile->specialties->pluck('name')->join(', ') ?: 'General care' }}</p>
                <x-appointments.fee :appointment="$appointment" />
                <p><strong>Consultation:</strong> <x-appointments.consultation-type :type="$appointment->consultation_type" /></p>
                <p><strong>Status:</strong> <x-ui.status-badge :status="$appointment->status" /></p>
                @if ($appointment->consultation_type === 'physical')
                    <p><strong>Visit details:</strong> In-person appointment.</p>
                @else
                    <p><strong>Online visit:</strong> Voice and video calls are not available in myDoc yet, so there are no call instructions for this appointment.</p>
                @endif
                <x-appointments.follow-up-links :appointment="$appointment" class="small mb-3" />
                <x-appointments.status-explanation :appointment="$appointment" link-to-doctor />
                @if ($canBookFollowUp)
                    <a class="btn btn-primary mb-3" href="{{ route('doctors.show', ['doctor' => $appointment->slot->doctorProfile, 'follow_up' => $appointment->id]) }}#availability">Book a follow-up</a>
                @endif
                @if ($appointment->isCancellableByPatient())
                    <p class="small text-secondary mb-2">You can cancel online until {{ App\Models\Appointment::patientCancellationDeadline($appointment->slot->starts_at)->format('D, M j, Y g:i A') }}.</p>
                    <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" data-disable-on-submit
                        data-confirm="Cancel your appointment with {{ $appointment->slot->doctorProfile->user->name }} on {{ $appointment->slot->starts_at->format('D, M j, Y \a\t g:i A') }}? This cannot be undone.">
                        @csrf
                        <button class="btn btn-outline-danger" type="submit" data-loading-text="Cancelling…">Cancel appointment</button>
                    </form>
                @elseif (in_array($appointment->status, App\Models\Appointment::ACTIVE_STATUSES, true) && $appointment->slot->starts_at->isFuture())
                    <p class="small text-secondary mb-0">Online cancellation closes {{ App\Models\Appointment::PATIENT_CANCELLATION_NOTICE_HOURS }} hours before the appointment starts, so this appointment can no longer be cancelled here.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
