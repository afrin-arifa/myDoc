@props(['profile', 'active' => 'appointments', 'pendingCount' => 0])

<aside class="doctor-sidebar" aria-label="Doctor workspace navigation">
    <a class="doctor-sidebar__brand" href="{{ route('doctor.dashboard') }}">
        <span class="doctor-sidebar__brand-mark">+</span>
        <span>myDoc</span>
    </a>
    <div class="doctor-sidebar__profile">
        <img src="{{ $profile?->photoUrl() ?? asset('images/doctor.png') }}" alt="{{ $profile?->user?->name ?? auth()->user()->name }} profile image">
        <h2 class="h5 mb-1">{{ $profile?->user?->name ?? auth()->user()->name }}</h2>
        <p class="small text-secondary mb-2">{{ $profile?->professional_title ?? 'Doctor' }}</p>
    </div>
    <nav class="doctor-sidebar__nav" aria-label="Doctor workspace">
        <a class="{{ $active === 'overview' ? 'is-active' : '' }}" href="{{ route('doctor.dashboard') }}"><i class="fa-regular fa-chart-bar"></i><span>Dashboard</span>
        </a>
        <a class="{{ $active === 'appointments' ? 'is-active' : '' }}" href="{{ route('doctor.appointments.index') }}"><i class="fa-regular fa-calendar-check"></i><span>Appointment</span>
            @if ($pendingCount)
                <span class="doctor-sidebar__count">{{ $pendingCount }}</span>
            @endif
        </a>
        <a class="{{ $active === 'schedule' ? 'is-active' : '' }}" href="{{ route('doctor.schedule') }}"><i class="fa-regular fa-clock"></i><span>Schedule Timing</span></a>
        @foreach ([['fa-regular fa-credit-card', 'Invoices'], ['fa-regular fa-envelope', 'Messages']] as [$icon, $label])
            <span class="doctor-sidebar__disabled"><i class="{{ $icon }}"></i><span>{{ $label }}</span></span>
        @endforeach
        <a class="{{ $active === 'profile' ? 'is-active' : '' }}" href="{{ route('doctor.profile') }}"><i class="fa-regular fa-user"></i><span>Profile</span></a>
        <a class="{{ $active === 'settings' ? 'is-active' : '' }}" href="{{ route('doctor.settings') }}"><i class="fa-solid fa-user-gear"></i><span>Profile Settings</span></a>
        <a class="{{ $active === 'patients' ? 'is-active' : '' }}" href="{{ route('doctor.patients.index') }}"><i class="fa-regular fa-user"></i><span>Patients</span></a>
        @foreach (['Patients Review', 'Chat'] as $label)
            <span class="doctor-sidebar__disabled"><i class="fa-regular fa-user"></i><span>{{ $label }}</span></span>
        @endforeach
        <span class="doctor-sidebar__disabled"><i class="fa-solid fa-right-to-bracket"></i><span>Login</span></span>
        <span class="doctor-sidebar__disabled"><i class="fa-regular fa-id-card"></i><span>Forgot Password</span></span>
    </nav>
    <div class="doctor-sidebar__footer">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>
</aside>
