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

        <x-patients.announcements :announcements="$announcements" />

        <div class="row g-3 mb-4">
            @foreach ([
                'total' => ['Total appointments', 'fa-regular fa-calendar', 'primary'],
                'upcoming' => ['Upcoming', 'fa-regular fa-clock', 'info'],
                'booked' => [App\Models\Appointment::statusLabel('booked'), 'fa-regular fa-hourglass-half', 'warning'],
                'confirmed' => ['Confirmed', 'fa-regular fa-circle-check', 'success'],
            ] as $key => [$label, $icon, $tone])
                <div class="col-6 col-lg-3">
                    <x-ui.stat :label="$label" :value="$appointmentCounts[$key]" :icon="$icon" :tone="$tone" />
                </div>
            @endforeach
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h4 mb-0">Upcoming appointments</h2>
            <a class="section-link" href="{{ route('patient.appointments.index') }}">View appointment history<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="row g-3">
            @forelse ($upcomingAppointments as $appointment)
                <div class="col-lg-4">
                    <article class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <p class="hero__eyebrow mb-3"><i class="fa-regular fa-calendar me-2" aria-hidden="true"></i>{{ $appointment->slot->starts_at->format('D, M j') }} · {{ $appointment->slot->starts_at->format('g:i A') }}</p>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <x-ui.avatar :name="$appointment->slot->doctorProfile->user->name" :src="$appointment->slot->doctorProfile->photoUrl() ?? asset('images/doctor.png')" />
                                <div class="min-w-0">
                                    <h3 class="h6 mb-0">{{ $appointment->slot->doctorProfile->user->name }}</h3>
                                    <p class="small text-secondary mb-0">{{ $appointment->slot->doctorProfile->professional_title }}</p>
                                </div>
                            </div>
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

        <section class="card border-0 shadow-sm p-4 mt-5" aria-labelledby="dashboard-upload-heading">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0" id="dashboard-upload-heading">Add a medical document</h2>
                <a class="section-link" href="{{ route('patient.documents.index') }}">View all medical records<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <x-medical-documents.upload-form compact />
        </section>
    </div>
@endsection
