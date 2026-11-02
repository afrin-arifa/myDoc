
@extends('layouts.app')

@section('title', 'Confirm appointment | myDoc')

@section('content')
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4"
            href="{{ route('doctors.show', $slot->doctorProfile) }}">
            &larr; Back to doctor profile
        </a>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <p class="hero__eyebrow mb-2">Booking review</p>
                <h1 class="h2 mb-3">Confirm your appointment</h1>
                <p class="text-secondary mb-4">
                    Review your appointment details and select how you would
                    like to consult with your doctor.
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

                            <dt class="col-sm-4">Consultation fee</dt>
                            <dd class="col-sm-8">
                                {{ number_format((float) $slot->doctorProfile->consultation_fee, 2) }}
                            </dd>
                        </dl>

                        <hr class="my-4">

                        <form method="POST"
                            action="{{ route('appointment-slots.book', $slot) }}">
                            @csrf

                            <x-appointments.consultation-options
                                :selected="old('consultation_type')" />

                            <div class="d-flex flex-wrap gap-2 mt-4">
                                <button class="btn btn-primary px-4" type="submit">
                                    Confirm appointment
                                </button>

                                <a class="btn btn-outline-secondary"
                                    href="{{ route('doctors.show', $slot->doctorProfile) }}">
                                    Choose another time
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
