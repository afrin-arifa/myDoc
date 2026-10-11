@extends('layouts.app')

@section('title', 'Admin dashboard | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Administration</p>
        <h1 class="h2 mb-3">Welcome, {{ auth()->user()->name }}.</h1>
        <div class="row g-3 mb-4">
            @foreach ([
                'patients' => 'Patients', 'doctors' => 'Doctors', 'appointments' => 'Appointments',
                'upcoming' => 'Upcoming', 'booked' => 'Booked', 'confirmed' => 'Confirmed',
                'completed' => 'Completed', 'cancelled_or_rejected' => 'Cancelled / rejected'
            ] as $key => $label)
                <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <p class="text-secondary mb-1">{{ $label }}</p><p class="h3 mb-0">{{ $counts[$key] }}</p>
                </div></div></div>
            @endforeach
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="{{ route('admin.users.index') }}">Manage users</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.doctors.index') }}">Review doctors</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.specialties.index') }}">Manage specialties</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.appointments.index') }}">Appointment oversight</a>
        </div>
    </div>
@endsection
