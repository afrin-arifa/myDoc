@props(['appointment', 'cancelable' => false])

<div class="card appointment-card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-column flex-md-row gap-3">
        <div class="date-tile" aria-hidden="true">
            <span class="date-tile__month">{{ $appointment->slot->starts_at->format('M') }}</span>
            <span class="date-tile__day">{{ $appointment->slot->starts_at->format('j') }}</span>
            <span class="date-tile__weekday">{{ $appointment->slot->starts_at->format('D') }}</span>
        </div>
        <div class="flex-grow-1 min-w-0">
            <h2 class="h5 mb-1">
                <a class="link-body-emphasis text-decoration-none" href="{{ route('patient.appointments.show', $appointment) }}">
                    {{ $appointment->slot->doctorProfile->user->name }}
                </a>
            </h2>
            <p class="text-secondary mb-1"><i class="fa-regular fa-clock me-1" aria-hidden="true"></i>{{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p>
            <p class="small mb-1"><x-appointments.location :location="$appointment->slot->location" compact /></p>
            @if ($appointment->follow_up_of_id)
                <p class="small mb-1"><i class="fa-solid fa-rotate-left text-secondary me-1" aria-hidden="true"></i>Follow-up to <a href="{{ route('patient.appointments.show', $appointment->follow_up_of_id) }}">{{ App\Models\Appointment::referenceFor($appointment->follow_up_of_id) }}</a></p>
            @endif
            <x-appointments.consultation-type :type="$appointment->consultation_type" />
            <div class="mt-2">
                <x-ui.status-badge :status="$appointment->status" />
            </div>
            <x-appointments.status-explanation :appointment="$appointment" class="small mt-2 mb-0" />
        </div>
        @if ($cancelable)
            <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" class="align-self-md-center appointment-card__action" data-disable-on-submit
                data-confirm="Cancel your appointment with {{ $appointment->slot->doctorProfile->user->name }} on {{ $appointment->slot->starts_at->format('D, M j, Y \a\t g:i A') }}? This cannot be undone.">
                @csrf
                <button class="btn btn-outline-danger" type="submit" data-loading-text="Cancelling…">Cancel appointment<span class="visually-hidden"> with {{ $appointment->slot->doctorProfile->user->name }} on {{ $appointment->slot->starts_at->format('D, M j, Y \a\t g:i A') }}</span></button>
            </form>
        @elseif (in_array($appointment->status, App\Models\Appointment::ACTIVE_STATUSES, true) && $appointment->slot->starts_at->isFuture())
            <p class="small text-secondary align-self-md-center mb-0">Online cancellation closes {{ App\Models\Appointment::PATIENT_CANCELLATION_NOTICE_HOURS }} hours before the start.</p>
        @endif
    </div>
</div>
