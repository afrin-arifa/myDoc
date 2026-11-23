@props(['appointment', 'linkToDoctor' => false])

{{-- Patient-facing explanation of an appointment's status. Wording only: it never changes or decides the status. --}}
@php
    $doctorProfile = $appointment->slot->doctorProfile;
    $started = ! $appointment->slot->starts_at->isFuture();
    $explanation = match ($appointment->status) {
        'booked' => $started
            ? 'The appointment time has started before the doctor approved this request, so it will be marked as expired shortly.'
            : 'This request is pending until the doctor approves it. We will let you know once it is confirmed.',
        'confirmed' => $started
            ? 'The doctor confirmed this appointment.'
            : 'The doctor has confirmed this appointment. Please be ready at the time shown.',
        'completed' => 'This appointment has taken place.',
        'rejected' => 'The doctor could not accept this request. You can request a different time.',
        'cancelled' => 'This appointment was cancelled and is no longer scheduled.',
        'expired' => 'This request expired because it was not approved before the appointment start time.',
        default => null,
    };
    $canRequestAgain = $linkToDoctor
        && in_array($appointment->status, App\Models\Appointment::CLOSED_STATUSES, true)
        && $doctorProfile?->isPubliclyListed();
@endphp

@if ($explanation)
    <p {{ $attributes->merge(['class' => 'appointment-status-explanation text-secondary']) }}>
        {{ $explanation }}
        @if ($canRequestAgain)
            <a href="{{ route('doctors.show', $doctorProfile) }}#availability">Choose a new time with {{ $doctorProfile->user->name }}</a>
        @endif
    </p>
@endif
