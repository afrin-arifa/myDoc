@props(['doctor', 'showBooking' => true])

@php($nextSlot = $doctor->appointmentSlots->first())

<div class="doctor-card">
    <div class="doctor-card__media">
        <img src="{{ asset('images/doctor.png') }}" alt="{{ $doctor->user->name }} profile image">
        <span class="doctor-card__availability"><span aria-hidden="true"></span> Accepting patients</span>
    </div>
    <div class="doctor-card__body">
        <p class="hero__eyebrow mb-2">{{ $doctor->professional_title }}</p>
        <h2 class="h5 mb-1">{{ $doctor->user->name }}</h2>
        <p class="small text-secondary mb-3">{{ $doctor->specialties->pluck('name')->join(', ') ?: 'General care' }}</p>
        <div class="doctor-card__meta small text-secondary">
            <span>{{ $doctor->years_of_experience ?? 'Experience not listed' }}{{ $doctor->years_of_experience !== null ? ' years' : '' }} experience</span>
            <strong>Fee {{ number_format((float) $doctor->consultation_fee, 2) }}</strong>
        </div>
        @if ($nextSlot)
            <p class="small text-secondary mb-3">Next available {{ $nextSlot->starts_at->format('D, M j, g:i A') }}</p>
        @else
            <p class="small text-secondary mb-3">No upcoming times listed</p>
        @endif
        <div class="doctor-card__actions">
            @if ($showBooking)
                <a class="btn btn-primary btn-sm" href="{{ route('doctors.show', $doctor) }}">Book now</a>
            @endif
            <a class="btn btn-link btn-sm" href="{{ route('doctors.show', $doctor) }}">View profile</a>
        </div>
    </div>
</div>
