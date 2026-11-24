@props(['doctor', 'canBook' => false])

{{-- next_available_at comes from the directory's aggregate query (withMin), so no slots are loaded per card. --}}
@php($nextAvailableAt = $doctor->next_available_at)

<div class="doctor-card">
    <div class="doctor-card__media">
        {{-- Decorative: the doctor's name is the card heading right below. --}}
        <img src="{{ $doctor->photoUrl() ?? asset('images/doctor.png') }}" alt="" width="320" height="224" decoding="async">
    </div>
    <div class="doctor-card__body">
        <h2 class="h5 mb-1">{{ $doctor->user->name }}</h2>
        <p class="small text-secondary mb-3">{{ $doctor->professional_title }}</p>
        <p class="mb-3"><span class="doctor-card__specialty">{{ $doctor->specialties->pluck('name')->join(', ') ?: 'General care' }}</span></p>
        <div class="doctor-card__meta small text-secondary">
            <span>{{ $doctor->years_of_experience !== null ? $doctor->years_of_experience.' years experience' : 'Experience not listed' }}</span>
            <strong>Fee {{ App\Services\ConsultationFee::format($doctor->consultation_fee) }}</strong>
        </div>
        @if ($nextAvailableAt)
            <p class="doctor-card__next is-open">Next available <time datetime="{{ $nextAvailableAt->toIso8601String() }}">{{ $nextAvailableAt->format('D, M j, g:i A') }}</time></p>
        @else
            <p class="doctor-card__next">No upcoming times listed</p>
        @endif
        <div class="doctor-card__actions">
            @if ($canBook && $nextAvailableAt)
                <a class="btn btn-primary btn-sm" href="{{ route('doctors.show', ['doctor' => $doctor, 'date' => $nextAvailableAt->toDateString()]) }}#availability"
                    aria-label="See available times for {{ $doctor->user->name }}">See available times</a>
            @endif
            <a class="btn btn-outline-primary btn-sm" href="{{ route('doctors.show', $doctor) }}" aria-label="View profile of {{ $doctor->user->name }}">View profile</a>
        </div>
    </div>
</div>
