@props(['profile', 'active' => 'appointments'])

<aside class="doctor-sidebar" aria-label="Doctor workspace navigation">
    <div class="doctor-sidebar__profile">
        <img src="{{ asset('images/doctor.png') }}" alt="{{ $profile->user->name }} profile image">
        <h2 class="h5 mb-1">{{ $profile->user->name }}</h2>
        <p class="small text-secondary mb-2">{{ $profile->professional_title }}</p>
        <p class="small mb-0">{{ $profile->specialties->pluck('name')->join(', ') ?: 'General care' }}</p>
    </div>
    <nav class="doctor-sidebar__nav" aria-label="Doctor workspace">
        <a class="{{ $active === 'dashboard' ? 'is-active' : '' }}" href="{{ route('doctor.dashboard') }}">Overview</a>
        <a class="{{ $active === 'appointments' ? 'is-active' : '' }}" href="{{ route('doctor.appointments.index') }}">Appointments</a>
        <a href="{{ route('doctor.dashboard') }}#availability">Availability</a>
        <a href="{{ route('doctor.dashboard') }}#professional-profile">Professional profile</a>
    </nav>
</aside>
