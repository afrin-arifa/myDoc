
@extends('layouts.app')

@section('title', 'Request appointment | myDoc')

@section('content')
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4"
            href="{{ route('doctors.show', $slot->doctorProfile) }}">
            &larr; Back to doctor profile
        </a>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <p class="hero__eyebrow mb-2">Booking review</p>
                <h1 class="h2 mb-3">Request your appointment</h1>
                <p class="text-secondary mb-4">
                    Review the details below and send your request. The doctor approves each request; until then it
                    is shown as pending approval.
                </p>

                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-lg-5">
                        <h2 class="h4 mb-4">
                            {{ $slot->doctorProfile->user->name }}
                        </h2>

                        <dl class="row mb-4">
                            <dt class="col-sm-4">Professional title</dt>
                            <dd class="col-sm-8">
                                {{ $slot->doctorProfile->professional_title }}
                            </dd>

                            <dt class="col-sm-4">Specialty</dt>
                            <dd class="col-sm-8">
                                {{ $slot->doctorProfile->specialties->pluck('name')->join(', ') ?: 'General care' }}
                            </dd>

                            <dt class="col-sm-4">Date and time</dt>
                            <dd class="col-sm-8">
                                {{ $slot->starts_at->format('D, M j, Y g:i A') }}
                            </dd>

                            <dt class="col-sm-4">Location</dt>
                            <dd class="col-sm-8">
                                <x-appointments.location :location="$slot->location" />
                            </dd>

                            <dt class="col-sm-4">Consultation fee</dt>
                            <dd class="col-sm-8">
                                {{ App\Services\ConsultationFee::format($fee['fee']) }}@if ($fee['type'] === App\Services\ConsultationFee::TYPE_FOLLOW_UP) <span class="text-secondary">(follow-up)</span>@endif
                                <span class="d-block small text-secondary">This fee is saved with your request and does not change if the doctor's fees change later.</span>
                            </dd>
                        </dl>

                        <hr class="my-4">

                        @php($bookingBlockedReason = $hasReachedLimit ? 'booking-limit-notice' : ($overlapping ? 'booking-overlap-notice' : null))

                        @if ($hasReachedLimit)
                            <div class="alert alert-warning" role="status" id="booking-limit-notice">
                                You already have {{ $activeLimit }} active appointment requests (pending or confirmed), which is the maximum.
                                Cancel one, or wait until one has taken place, before requesting another.
                                <a class="alert-link" href="{{ route('patient.appointments.index') }}">View my appointments</a>
                            </div>
                        @elseif ($overlapping)
                            <div class="alert alert-warning" role="status" id="booking-overlap-notice">
                                This time overlaps your appointment on {{ $overlapping->slot->starts_at->format('D, M j, Y \a\t g:i A') }}
                                with {{ $overlapping->slot->doctorProfile?->user?->name ?? 'another doctor' }}.
                                <a class="alert-link" href="{{ route('doctors.show', array_filter(['doctor' => $slot->doctorProfile, 'date' => $slot->starts_at->toDateString(), 'follow_up' => $followUpOf?->id])) }}#availability">Choose another time</a>
                            </div>
                        @endif

                        @if ($followUpOf)
                            <div class="alert alert-info" role="status" data-follow-up-banner>
                                This is a follow-up to your appointment {{ $followUpOf->reference() }} on {{ $followUpOf->slot->starts_at->format('D, M j, Y') }}.
                            </div>
                        @endif

                        <form method="POST"
                            action="{{ route('appointment-slots.book', $slot) }}" data-disable-on-submit>
                            @csrf
                            @if ($followUpOf)
                                <input type="hidden" name="follow_up_of_id" value="{{ $followUpOf->id }}">
                            @endif

                            <x-appointments.consultation-options
                                :selected="old('consultation_type')" />

                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button class="btn btn-primary px-4" type="submit" data-loading-text="Sending request…"
                                    @if ($bookingBlockedReason) disabled aria-describedby="{{ $bookingBlockedReason }}" @endif>
                                    Send appointment request
                                </button>

                                <a class="btn btn-outline-secondary"
                                    href="{{ route('doctors.show', array_filter(['doctor' => $slot->doctorProfile, 'date' => $slot->starts_at->toDateString(), 'follow_up' => $followUpOf?->id])) }}#availability">
                                    Choose another time
                                </a>
                            </div>

                            <p class="small text-secondary mt-3 mb-0">
                                You can cancel online until {{ App\Models\Appointment::PATIENT_CANCELLATION_NOTICE_HOURS }} hours before the appointment starts.
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
