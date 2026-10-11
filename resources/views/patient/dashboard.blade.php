@extends('layouts.app')

@section('title', 'Patient dashboard | myDoc')

@section('content')
    <div class="container py-5">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <p class="hero__eyebrow mb-2">Patient space</p>
                <h1 class="h2 mb-2">Welcome, {{ auth()->user()->name }}.</h1>
                <p class="text-secondary mb-0">Keep your care journey organized.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="{{ route('doctors.index') }}">Find a doctor</a>
                <a class="btn btn-outline-primary" href="{{ route('patient.profile') }}">Edit profile</a>
            </div>
        </div>

        <div class="row g-3 mb-5">
            @foreach ([
                'total' => 'Total appointments',
                'upcoming' => 'Upcoming',
                'booked' => 'Awaiting confirmation',
                'confirmed' => 'Confirmed',
            ] as $key => $label)
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <p class="text-secondary mb-1">{{ $label }}</p>
                            <p class="h3 mb-0">{{ $appointmentCounts[$key] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h4 mb-0">Upcoming appointments</h2>
            <a href="{{ route('patient.appointments.index') }}">View appointment history</a>
        </div>
        <div class="row g-3">
            @forelse ($upcomingAppointments as $appointment)
                <div class="col-lg-4">
                    <article class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <p class="hero__eyebrow mb-2">{{ $appointment->slot->starts_at->format('D, M j') }}</p>
                            <h3 class="h5">{{ $appointment->slot->doctorProfile->user->name }}</h3>
                            <p class="text-secondary mb-3">{{ $appointment->slot->starts_at->format('g:i A') }} · {{ $appointment->slot->doctorProfile->professional_title }}</p>
                            <x-appointments.consultation-type :type="$appointment->consultation_type" />
                            <div class="mt-2">
                                <x-ui.status-badge :status="$appointment->status" />
                            </div>
                            <a class="btn btn-sm btn-outline-primary d-block mt-3" href="{{ route('patient.appointments.show', $appointment) }}">View details</a>
                        </div>
                    </article>
                </div>
            @empty
                <div class="col-12">
                    <div class="empty-state">
                        <h3 class="h5">No upcoming appointments.</h3>
                        <p class="text-secondary mb-3">Find a doctor and choose an available time when you are ready.</p>
                        <a class="btn btn-outline-primary" href="{{ route('doctors.index') }}">Browse doctors</a>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
@endsection
