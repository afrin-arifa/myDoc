@extends('layouts.app')

@section('title', 'Appointment details | myDoc')

@section('content')
    @php
        $slot = $appointment->slot;
        $doctorProfile = $slot->doctorProfile;
        $canCancel = $appointment->isCancellableByPatient();
        $cancellationClosed = ! $canCancel && in_array($appointment->status, App\Models\Appointment::ACTIVE_STATUSES, true) && $slot->starts_at->isFuture();
        $when = match (true) {
            $slot->starts_at->isToday() => 'Today',
            $slot->starts_at->isTomorrow() => 'Tomorrow',
            $slot->starts_at->isYesterday() => 'Yesterday',
            default => $slot->starts_at->diffForHumans(['parts' => 1]),
        };
    @endphp
    <div class="container py-5 appointment-detail">
        <a class="back-link mb-4" href="{{ route('patient.appointments.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to appointments</a>
        <header class="appointment-detail__header">
            <div>
                <p class="hero__eyebrow mb-2">Patient space</p>
                <h1 class="h2 mb-0">Appointment details</h1>
            </div>
            <span class="appointment-detail__reference">{{ $appointment->reference() }}</span>
        </header>

        <section class="appointment-detail__summary" aria-label="Doctor and time">
            <div class="appointment-detail__patient">
                <x-ui.avatar :name="$doctorProfile->user->name" :src="$doctorProfile->photoUrl()" size="lg" />
                <div>
                    <h2 class="appointment-detail__name">{{ $doctorProfile->user->name }}</h2>
                    @if ($doctorProfile->professional_title)
                        <p class="appointment-detail__contact"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i>{{ $doctorProfile->professional_title }}</p>
                    @endif
                    <p class="appointment-detail__contact"><i class="fa-solid fa-briefcase-medical" aria-hidden="true"></i>{{ $doctorProfile->specialties->pluck('name')->join(', ') ?: 'General care' }}</p>
                </div>
            </div>
            <div @class(['appointment-detail__when', 'is-today' => $slot->starts_at->isToday()])>
                <x-ui.status-badge :status="$appointment->status" />
                <span class="appointment-detail__date"><i class="fa-regular fa-calendar" aria-hidden="true"></i>{{ $slot->starts_at->format('D, M j, Y') }}</span>
                <span class="appointment-detail__time"><i class="fa-regular fa-clock" aria-hidden="true"></i>{{ $slot->starts_at->format('g:i A') }} – {{ $slot->ends_at->format('g:i A') }}<span class="appointment-detail__relative">{{ $when }}</span></span>
            </div>
        </section>

        <dl class="appointment-detail__facts">
            <div class="appointment-detail__fact">
                <dt><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Location</dt>
                <dd><x-appointments.location :location="$slot->location" /></dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-solid fa-stethoscope" aria-hidden="true"></i>Consultation</dt>
                <dd><x-appointments.consultation-type :type="$appointment->consultation_type" /></dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-regular fa-money-bill-1" aria-hidden="true"></i>{{ App\Services\ConsultationFee::label($appointment->fee_type) ?? 'Consultation fee' }}</dt>
                <dd @class(['text-secondary' => $appointment->consultation_fee === null]) data-appointment-fee>{{ App\Services\ConsultationFee::format($appointment->consultation_fee) }}</dd>
            </div>
        </dl>

        <section class="appointment-detail__notes" aria-label="Visit details">
            <x-appointments.status-explanation :appointment="$appointment" link-to-doctor class="appointment-detail__status-note" />
            @if ($appointment->consultation_type === 'physical')
                <p><strong>Visit details:</strong> In-person appointment. Please arrive a few minutes early.</p>
            @else
                <p><strong>Online visit:</strong> Voice and video calls are not available in myDoc yet, so there are no call instructions for this appointment.</p>
            @endif
            <x-appointments.follow-up-links :appointment="$appointment" />
            @if ($cancellationClosed)
                <p class="appointment-detail__hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>Online cancellation closes {{ App\Models\Appointment::PATIENT_CANCELLATION_NOTICE_HOURS }} hours before the appointment starts, so this appointment can no longer be cancelled here.</p>
            @endif
            @if ($canCancel)
                <div class="appointment-detail__cancel">
                    <p class="appointment-detail__hint"><i class="fa-regular fa-clock" aria-hidden="true"></i>You can cancel online until {{ App\Models\Appointment::patientCancellationDeadline($slot->starts_at)->format('D, M j, Y g:i A') }}.</p>
                    <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" data-disable-on-submit
                        data-confirm="Cancel your appointment with {{ $doctorProfile->user->name }} on {{ $slot->starts_at->format('D, M j, Y \a\t g:i A') }}? This cannot be undone.">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger" type="submit" data-loading-text="Cancelling…"><i class="fa-regular fa-calendar-xmark me-1" aria-hidden="true"></i>Cancel appointment</button>
                    </form>
                </div>
            @endif
        </section>

        @if ($canBookFollowUp)
            <div class="appointment-detail__actions">
                <div class="appointment-detail__action-group">
                    <a class="btn btn-primary" href="{{ route('doctors.show', ['doctor' => $doctorProfile, 'follow_up' => $appointment->getRouteKey()]) }}#availability"><i class="fa-solid fa-rotate-right me-1" aria-hidden="true"></i>Book a follow-up</a>
                </div>
            </div>
        @endif
    </div>
@endsection
