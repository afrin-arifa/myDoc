@props(['profile', 'active' => 'appointments', 'pendingCount' => 0])

@if ($workspace->inAssistantWorkspace())
    {{-- Doctor Assistant: works in the selected doctor's workspace; never the doctor's own account pages. --}}
    @php
        $workspaceSections = ['appointments.index', 'schedule', 'patients.index', 'medical-records.index'];
        $currentSection = str_replace('assistant.workspace.', '', (string) request()->route()?->getName());
        $switchSection = in_array($currentSection, $workspaceSections, true) ? $currentSection : 'appointments.index';
        $assignedDoctors = App\Models\DoctorProfile::query()
            ->whereKey(app(App\Services\DoctorAccess::class)->actingProfileIds(auth()->user()))
            ->with('user:id,name')
            ->get()
            ->sortBy('user.name');
    @endphp
    <aside class="doctor-sidebar" aria-label="Doctor Assistant workspace navigation">
        <a class="doctor-sidebar__brand" href="{{ route('assistant.dashboard') }}">
            <span class="doctor-sidebar__brand-mark">+</span>
            <span>myDoc</span>
        </a>
        <div class="doctor-sidebar__profile">
            <img src="{{ $profile->photoUrl() ?? asset('images/doctor.png') }}" alt="{{ $profile->user->name }} profile image">
            <p class="small text-secondary mb-1">Working for</p>
            <h2 class="h5 mb-1" data-selected-doctor>{{ $profile->user->name }}</h2>
            <p class="small text-secondary mb-2">Signed in as {{ auth()->user()->name }} · Doctor Assistant</p>
            @if ($assignedDoctors->count() > 1)
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Switch doctor">
                        Switch doctor
                    </button>
                    <ul class="dropdown-menu" data-doctor-switcher>
                        @foreach ($assignedDoctors as $doctor)
                            <li>
                                <a @class(['dropdown-item', 'active' => $doctor->is($profile)]) href="{{ route('assistant.workspace.'.$switchSection, $doctor) }}"
                                    @if ($doctor->is($profile)) aria-current="true" @endif>{{ $doctor->user->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
        <nav class="doctor-sidebar__nav" aria-label="Doctor Assistant workspace">
            <a href="{{ route('assistant.dashboard') }}"><i class="fa-regular fa-chart-bar"></i><span>Assistant dashboard</span></a>
            <a href="{{ route('assistant.doctors.index') }}"><i class="fa-solid fa-user-doctor"></i><span>My doctors</span></a>
            <a class="{{ $active === 'appointments' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.appointments.index') }}"><i class="fa-regular fa-calendar-check"></i><span>Appointments</span>
                @if ($pendingCount)
                    <span class="doctor-sidebar__count">{{ $pendingCount }}</span>
                @endif
            </a>
            <a class="{{ $active === 'schedule' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.schedule') }}"><i class="fa-regular fa-clock"></i><span>Schedule and slots</span></a>
            <a class="{{ $active === 'patients' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.patients.index') }}"><i class="fa-regular fa-user"></i><span>Patients</span></a>
            <a class="{{ $active === 'medical-records' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.medical-records.index') }}"><i class="fa-regular fa-folder-open"></i><span>Medical Records</span></a>
            <a href="{{ route('assistant.account') }}"><i class="fa-solid fa-user-gear"></i><span>My account</span></a>
        </nav>
        <div class="doctor-sidebar__footer">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log out</button>
            </form>
        </div>
    </aside>
@else
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
        <a class="{{ $active === 'medical-records' ? 'is-active' : '' }}" href="{{ route('doctor.medical-records.index') }}"><i class="fa-regular fa-folder-open"></i><span>Medical Records</span></a>
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
@endif
