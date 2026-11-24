@extends('layouts.app')

@section('title', 'Admin dashboard | myDoc')

@section('content')
    <div class="container py-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <p class="hero__eyebrow mb-2">Administration</p>
                <h1 class="h2 mb-1">Welcome, {{ auth()->user()->name }}.</h1>
                <p class="text-secondary mb-0">A quick look at the platform today.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="{{ route('admin.assistants.create') }}"><i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>Add assistant</a>
                <a class="btn btn-outline-primary" href="{{ route('admin.profile') }}">My profile</a>
            </div>
        </div>
        <div class="row g-3 mb-5">
            @foreach ([
                'patients' => ['Patients', 'fa-solid fa-user-group', 'primary'],
                'doctors' => ['Doctors', 'fa-solid fa-user-doctor', 'primary'],
                'appointments' => ['Appointments', 'fa-regular fa-calendar', 'primary'],
                'upcoming' => ['Upcoming', 'fa-regular fa-clock', 'info'],
                'booked' => [App\Models\Appointment::statusLabel('booked'), 'fa-regular fa-hourglass-half', 'warning'],
                'confirmed' => ['Confirmed', 'fa-regular fa-circle-check', 'success'],
                'completed' => ['Completed', 'fa-solid fa-check-double', 'success'],
                'cancelled_or_rejected' => ['Cancelled, rejected or expired', 'fa-regular fa-circle-xmark', 'danger'],
            ] as $key => [$label, $icon, $tone])
                <div class="col-sm-6 col-xxl-3">
                    <x-ui.stat :label="$label" :value="$counts[$key]" :icon="$icon" :tone="$tone" />
                </div>
            @endforeach
        </div>

        <h2 class="h5 mb-3">Manage</h2>
        <div class="row g-3">
            @foreach ([
                ['Manage users', 'Accounts, roles and access', 'fa-solid fa-users-gear', route('admin.users.index')],
                ['Review doctors', 'Invitations, profiles and status', 'fa-solid fa-user-doctor', route('admin.doctors.index')],
                ['Doctor assistants', 'Invitations and assignments', 'fa-solid fa-user-nurse', route('admin.assistants.index')],
                ['Appointment oversight', 'Search every appointment', 'fa-regular fa-calendar-check', route('admin.appointments.index')],
                ['Waiting lists', 'Who is waiting for each doctor', 'fa-regular fa-hourglass-half', route('admin.waitlist.index')],
                ['Notification delivery issues', 'Emails and SMS that need attention', 'fa-regular fa-bell', route('admin.sms-issues.index')],
                ['Manage specialties', 'The list patients search by', 'fa-solid fa-stethoscope', route('admin.specialties.index')],
            ] as [$label, $hint, $icon, $url])
                <div class="col-md-6 col-xxl-4">
                    <a class="action-tile" href="{{ $url }}">
                        <span class="ui-stat__icon ui-stat__icon--primary" aria-hidden="true"><i class="{{ $icon }}"></i></span>
                        <span class="min-w-0"><span class="action-tile__label">{{ $label }}</span><span class="action-tile__hint">{{ $hint }}</span></span>
                        <i class="fa-solid fa-chevron-right action-tile__arrow" aria-hidden="true"></i>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
@endsection
