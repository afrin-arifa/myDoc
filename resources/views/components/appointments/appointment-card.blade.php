@props(['appointment', 'cancelable' => false])

@php
    $slot = $appointment->slot;
    $doctorProfile = $slot->doctorProfile;
    $isPast = ! $slot->starts_at->isFuture();
    $cancellationClosed = ! $cancelable && in_array($appointment->status, App\Models\Appointment::ACTIVE_STATUSES, true) && ! $isPast;
    $detailsUrl = route('patient.appointments.show', $appointment);
@endphp

<article @class(['appointment-card', 'is-today' => $slot->starts_at->isToday(), 'is-past' => $isPast])>
    <a class="appointment-card__date" href="{{ $detailsUrl }}" aria-hidden="true" tabindex="-1">
        <span class="appointment-card__month">{{ $slot->starts_at->format('M') }}</span>
        <span class="appointment-card__day">{{ $slot->starts_at->format('j') }}</span>
        <span class="appointment-card__weekday">{{ $slot->starts_at->isToday() ? 'Today' : $slot->starts_at->format('D') }}</span>
    </a>

    <div class="appointment-card__body">
        <div class="appointment-card__top">
            <div class="appointment-card__doctor">
                <x-ui.avatar :name="$doctorProfile->user->name" :src="$doctorProfile->photoUrl()" size="sm" />
                <div class="min-w-0">
                    <h2 class="appointment-card__name">
                        <a href="{{ $detailsUrl }}">{{ $doctorProfile->user->name }}</a>
                    </h2>
                    @if ($doctorProfile->relationLoaded('specialties') && $doctorProfile->specialties->isNotEmpty())
                        <p class="appointment-card__specialty">{{ $doctorProfile->specialties->pluck('name')->join(', ') }}</p>
                    @elseif ($doctorProfile->professional_title)
                        <p class="appointment-card__specialty">{{ $doctorProfile->professional_title }}</p>
                    @endif
                </div>
            </div>
            <x-ui.status-badge :status="$appointment->status" />
        </div>

        <ul class="appointment-card__meta">
            <li><i class="fa-regular fa-clock" aria-hidden="true"></i>{{ $slot->starts_at->format('D, M j, Y g:i A') }}</li>
            <li><x-appointments.location :location="$slot->location" compact /></li>
            <li><i class="fa-solid {{ match ($appointment->consultation_type) { 'voice' => 'fa-phone', 'video' => 'fa-video', default => 'fa-house-medical' } }}" aria-hidden="true"></i>{{ match ($appointment->consultation_type) { 'voice' => 'Voice call', 'video' => 'Video call', default => 'Physical visit' } }}</li>
            @if ($appointment->follow_up_of_id)
                <li><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Follow-up to <a href="{{ route('patient.appointments.show', App\Models\Appointment::urlKey($appointment->follow_up_of_id)) }}">{{ App\Models\Appointment::referenceFor($appointment->follow_up_of_id) }}</a></li>
            @endif
        </ul>

        <x-appointments.status-explanation :appointment="$appointment" class="appointment-card__note" />

        <div class="appointment-card__footer">
            @if ($cancellationClosed)
                <span class="appointment-card__hint"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>Online cancellation closes {{ App\Models\Appointment::PATIENT_CANCELLATION_NOTICE_HOURS }} hours before the start.</span>
            @elseif ($cancelable)
                <span class="appointment-card__hint"><i class="fa-regular fa-clock" aria-hidden="true"></i>Cancel online until {{ App\Models\Appointment::patientCancellationDeadline($slot->starts_at)->format('M j, g:i A') }}</span>
            @else
                <span></span>
            @endif
            <div class="appointment-card__actions">
                @if ($cancelable)
                    <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" data-disable-on-submit
                        data-confirm="Cancel your appointment with {{ $doctorProfile->user->name }} on {{ $slot->starts_at->format('D, M j, Y \a\t g:i A') }}? This cannot be undone.">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger" type="submit" data-loading-text="Cancelling…">Cancel appointment<span class="visually-hidden"> with {{ $doctorProfile->user->name }} on {{ $slot->starts_at->format('D, M j, Y \a\t g:i A') }}</span></button>
                    </form>
                @endif
                <a class="btn btn-sm btn-outline-primary" href="{{ $detailsUrl }}">View details<span class="visually-hidden"> of your appointment with {{ $doctorProfile->user->name }}</span><i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>
</article>
