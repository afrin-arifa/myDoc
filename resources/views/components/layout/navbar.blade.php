{{--
    Global navigation. Links are defined per role in one place below and rendered by a single loop.
    Guests see the public, informational links; signed-in users see only the pages their role can open.
    Each link: label, url, and the route patterns that mark it active.
--}}
@php
    $user = auth()->user();
    $role = $user?->role;
    $canBrowseDirectory = Illuminate\Support\Facades\Gate::allows('browseDirectory', App\Models\DoctorProfile::class);
    $directoryLink = ['label' => 'Doctors', 'url' => route('doctors.index'), 'active' => ['doctors.*']];
    $homeLink = ['label' => 'Home', 'url' => route('home'), 'active' => ['home']];

    $links = match ($role) {
        'patient' => array_values(array_filter([
            $homeLink,
            $canBrowseDirectory ? $directoryLink : null,
            ['label' => 'My appointments', 'url' => route('patient.appointments.index'), 'active' => ['patient.appointments.*', 'appointment-slots.*']],
            ['label' => 'Medical records', 'url' => route('patient.documents.index'), 'active' => ['patient.documents.*']],
            ['label' => 'Profile', 'url' => route('patient.profile'), 'active' => ['patient.profile']],
        ])),
        'doctor' => [
            $homeLink,
            ['label' => 'Appointments', 'url' => route('doctor.appointments.index'), 'active' => ['doctor.appointments.*']],
            ['label' => 'Schedule', 'url' => route('doctor.schedule'), 'active' => ['doctor.schedule']],
            ['label' => 'Patients', 'url' => route('doctor.patients.index'), 'active' => ['doctor.patients.*']],
            ['label' => 'Medical records', 'url' => route('doctor.medical-records.index'), 'active' => ['doctor.medical-records.*']],
            ['label' => 'Profile', 'url' => route('doctor.profile'), 'active' => ['doctor.profile', 'doctor.settings']],
        ],
        App\Models\User::ROLE_ASSISTANT => [
            $homeLink,
            ['label' => 'My doctors', 'url' => route('assistant.doctors.index'), 'active' => ['assistant.doctors.*', 'assistant.workspace.*']],
        ],
        'admin' => [
            ['label' => 'Doctors', 'url' => route('admin.doctors.index'), 'active' => ['admin.doctors.*']],
            ['label' => 'Patients', 'url' => route('admin.users.index', ['role' => 'patient']), 'active' => ['admin.users.*']],
            ['label' => 'Appointments', 'url' => route('admin.appointments.index'), 'active' => ['admin.appointments.*']],
            ['label' => 'Medical records', 'url' => route('admin.medical-documents.index'), 'active' => ['admin.medical-documents.*']],
        ],
        default => array_values(array_filter([
            $homeLink,
            $canBrowseDirectory ? $directoryLink : null,
            ['label' => 'How it works', 'url' => url('/#how-it-works'), 'active' => []],
            ['label' => 'Why myDoc', 'url' => url('/#features'), 'active' => []],
        ])),
    };

    $dashboardRoute = App\Http\Controllers\Auth\AuthenticatedSessionController::dashboardRoute($role);
    $onDashboard = $dashboardRoute !== null && request()->routeIs($dashboardRoute);

    // Signed-in menus have more links, so they collapse into the mobile menu until there is room for one line.
    $breakpoint = $user ? 'xl' : 'lg';
@endphp

<nav class="navbar navbar-expand-{{ $breakpoint }} site-navbar bg-white py-3" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand fs-3" href="{{ route('home') }}">my<span class="text-primary">Doc</span></a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navigation"
            aria-controls="main-navigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="main-navigation">
            <ul class="navbar-nav ms-auto align-items-{{ $breakpoint }}-center gap-{{ $breakpoint }}-2">
                @foreach ($links as $link)
                    @php($isActive = $link['active'] !== [] && request()->routeIs(...$link['active']))
                    <li class="nav-item">
                        <a @class(['nav-link', 'active' => $isActive]) href="{{ $link['url'] }}" @if ($isActive) aria-current="page" @endif>{{ $link['label'] }}</a>
                    </li>
                @endforeach

                @auth
                    @if ($dashboardRoute)
                        <li class="nav-item site-navbar__action">
                            <a @class(['btn btn-primary px-4', 'active' => $onDashboard]) href="{{ route($dashboardRoute) }}" @if ($onDashboard) aria-current="page" @endif>Dashboard</a>
                        </li>
                    @endif
                    <li class="nav-item">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="btn btn-link nav-link" type="submit">Log out</button>
                        </form>
                    </li>
                @else
                    <li class="nav-item">
                        <a @class(['nav-link', 'active' => request()->routeIs('login')]) href="{{ route('login') }}" @if (request()->routeIs('login')) aria-current="page" @endif>Log in</a>
                    </li>
                    <li class="nav-item site-navbar__action">
                        <a @class(['btn btn-primary px-4', 'active' => request()->routeIs('register')]) href="{{ route('register') }}" @if (request()->routeIs('register')) aria-current="page" @endif>Register</a>
                    </li>
                @endauth
            </ul>
        </div>
    </div>
</nav>
