{{--
    Global navigation. Links are defined per role in one place below and rendered by a single loop.
    myDoc has no guest access: guests only see Log in and Register; signed-in users see only their role's pages.
    Each link: label, url, and the route patterns that mark it active.
--}}
@php
    $user = auth()->user();
    $role = $user?->role;
    $canBrowseDirectory = Illuminate\Support\Facades\Gate::allows('browseDirectory', App\Models\DoctorProfile::class);
    $directoryLink = ['label' => 'Doctors', 'icon' => 'fa-solid fa-user-doctor', 'url' => route('doctors.index'), 'active' => ['doctors.*']];

    $links = match ($role) {
        'patient' => array_values(array_filter([
            $canBrowseDirectory ? $directoryLink : null,
            ['label' => 'My appointments', 'icon' => 'fa-regular fa-calendar-check', 'url' => route('patient.appointments.index'), 'active' => ['patient.appointments.*', 'appointment-slots.*']],
            ['label' => 'Waiting list', 'icon' => 'fa-regular fa-hourglass-half', 'url' => route('patient.waitlist.index'), 'active' => ['patient.waitlist.*']],
            ['label' => 'Medical records', 'icon' => 'fa-regular fa-folder-open', 'url' => route('patient.documents.index'), 'active' => ['patient.documents.*']],
            ['label' => 'Profile', 'icon' => 'fa-regular fa-user', 'url' => route('patient.profile'), 'active' => ['patient.profile']],
        ])),
        'doctor' => [
            ['label' => 'Appointments', 'icon' => 'fa-regular fa-calendar-check', 'url' => route('doctor.appointments.index'), 'active' => ['doctor.appointments.*'], 'counter' => 'pending'],
            ['label' => 'Schedule', 'icon' => 'fa-regular fa-clock', 'url' => route('doctor.schedule'), 'active' => ['doctor.schedule']],
            ['label' => 'Patients', 'icon' => 'fa-solid fa-user-group', 'url' => route('doctor.patients.index'), 'active' => ['doctor.patients.*']],
            ['label' => 'Medical records', 'icon' => 'fa-regular fa-folder-open', 'url' => route('doctor.medical-records.index'), 'active' => ['doctor.medical-records.*']],
            ['label' => 'Profile', 'icon' => 'fa-regular fa-id-card', 'url' => route('doctor.profile'), 'active' => ['doctor.profile']],
            ['label' => 'Settings', 'icon' => 'fa-solid fa-gear', 'url' => route('doctor.settings'), 'active' => ['doctor.settings']],
        ],
        App\Models\User::ROLE_ASSISTANT => [
            ['label' => 'My doctors', 'icon' => 'fa-solid fa-user-doctor', 'url' => route('assistant.doctors.index'), 'active' => ['assistant.doctors.*']],
            ['label' => 'Account', 'icon' => 'fa-solid fa-gear', 'url' => route('assistant.account'), 'active' => ['assistant.account']],
        ],
        'admin' => [
            ['label' => 'Appointments', 'icon' => 'fa-regular fa-calendar-check', 'url' => route('admin.appointments.index'), 'active' => ['admin.appointments.*']],
            ['label' => 'Doctors', 'icon' => 'fa-solid fa-user-doctor', 'url' => route('admin.doctors.index'), 'active' => ['admin.doctors.*']],
            ['label' => 'Patients', 'icon' => 'fa-solid fa-user-group', 'url' => route('admin.users.index', ['role' => 'patient']), 'active' => ['admin.users.*'], 'only' => request('role') === 'patient'],
            ['label' => 'All users', 'icon' => 'fa-solid fa-users-gear', 'url' => route('admin.users.index'), 'active' => ['admin.users.*'], 'only' => request('role') !== 'patient'],
            ['label' => 'Assistants', 'icon' => 'fa-solid fa-user-nurse', 'url' => route('admin.assistants.index'), 'active' => ['admin.assistants.*']],
            ['label' => 'Waiting lists', 'icon' => 'fa-regular fa-hourglass-half', 'url' => route('admin.waitlist.index'), 'active' => ['admin.waitlist.*']],
            ['label' => 'Medical records', 'icon' => 'fa-regular fa-folder-open', 'url' => route('admin.medical-documents.index'), 'active' => ['admin.medical-documents.*']],
            ['label' => 'Specialties', 'icon' => 'fa-solid fa-stethoscope', 'url' => route('admin.specialties.index'), 'active' => ['admin.specialties.*']],
            ['label' => 'Delivery issues', 'icon' => 'fa-regular fa-bell', 'url' => route('admin.sms-issues.index'), 'active' => ['admin.sms-issues.*']],
        ],
        default => [],
    };

    $dashboardRoute = App\Http\Controllers\Auth\AuthenticatedSessionController::dashboardRoute($role);
    $onDashboard = $dashboardRoute !== null && request()->routeIs($dashboardRoute);

    if ($dashboardRoute !== null) {
        array_unshift($links, ['label' => 'Dashboard', 'icon' => 'fa-solid fa-house', 'url' => route($dashboardRoute), 'active' => [$dashboardRoute]]);
    }

    // Signed-in menus have more links, so they collapse into the mobile menu until there is room.
    $breakpoint = $user ? 'xl' : 'lg';

    // Staff get a sidebar on large screens (same markup, styled by .site-navbar--side); patients and guests a top bar.
    $isSidebar = in_array($role, ['doctor', 'admin', App\Models\User::ROLE_ASSISTANT], true);

    // An assistant inside a doctor's workspace also gets that doctor's pages and a switcher.
    $workspaceDoctor = $role === App\Models\User::ROLE_ASSISTANT ? $workspace->currentDoctorProfile()?->loadMissing('user:id,name') : null;
    $workspaceLinks = [];
    $assignedDoctors = collect();
    if ($workspaceDoctor) {
        $workspaceLinks = [
            ['label' => 'Appointments', 'icon' => 'fa-regular fa-calendar-check', 'url' => $workspace->url('doctor.appointments.index'), 'active' => ['assistant.workspace.appointments.*'], 'counter' => 'pending'],
            ['label' => 'Schedule and slots', 'icon' => 'fa-regular fa-clock', 'url' => $workspace->url('doctor.schedule'), 'active' => ['assistant.workspace.schedule']],
            ['label' => 'Patients', 'icon' => 'fa-solid fa-user-group', 'url' => $workspace->url('doctor.patients.index'), 'active' => ['assistant.workspace.patients.*']],
            ['label' => 'Medical records', 'icon' => 'fa-regular fa-folder-open', 'url' => $workspace->url('doctor.medical-records.index'), 'active' => ['assistant.workspace.medical-records.*']],
        ];
        $currentSection = str_replace('assistant.workspace.', '', (string) request()->route()?->getName());
        $switchSection = in_array($currentSection, ['appointments.index', 'schedule', 'patients.index', 'medical-records.index'], true) ? $currentSection : 'appointments.index';
        $assignedDoctors = App\Models\DoctorProfile::query()
            ->whereKey(app(App\Services\DoctorAccess::class)->actingProfileIds($user))
            ->with('user:id,name')
            ->get()
            ->sortBy('user.name');
    }

    $navProfile = $workspaceDoctor ?? ($role === 'doctor' ? $user->doctorProfile : null);
    $pendingCount = $navProfile
        ? App\Models\Appointment::query()->where('status', 'booked')->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $navProfile->id))->count()
        : 0;
    $subtitle = $role === 'doctor' ? ($navProfile?->professional_title ?? 'Doctor') : $user?->roleLabel();
@endphp

<nav @class(["navbar navbar-expand-{$breakpoint} site-navbar", 'site-navbar--side' => $isSidebar]) aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand fs-3" href="{{ $user ? route('home') : route('login') }}"><span class="site-navbar__mark" aria-hidden="true"><i class="fa-solid fa-heart-pulse"></i></span>my<span class="text-primary">Doc</span></a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navigation"
            aria-controls="main-navigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="main-navigation">
            @if ($workspaceDoctor)
                <div class="site-navbar__workspace">
                    <p class="site-navbar__heading">Working for</p>
                    <div class="d-flex align-items-center gap-2">
                        <x-ui.avatar :name="$workspaceDoctor->user->name" :src="$workspaceDoctor->photoUrl() ?? asset('images/doctor.png')" size="sm" />
                        <span class="fw-semibold min-w-0" data-selected-doctor>{{ $workspaceDoctor->user->name }}</span>
                    </div>
                    @if ($assignedDoctors->count() > 1)
                        <div class="dropdown mt-2">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false">Switch doctor</button>
                            <ul class="dropdown-menu w-100" data-doctor-switcher>
                                @foreach ($assignedDoctors as $doctor)
                                    <li>
                                        <a @class(['dropdown-item', 'fw-semibold' => $doctor->is($workspaceDoctor)]) href="{{ route('assistant.workspace.'.$switchSection, $doctor) }}"
                                            @if ($doctor->is($workspaceDoctor)) aria-current="true" @endif>{{ $doctor->user->name }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            <ul class="navbar-nav ms-auto">
                @foreach ([...$workspaceLinks, ...$links] as $link)
                    @php($isActive = $link['active'] !== [] && ($link['only'] ?? true) && request()->routeIs(...$link['active']))
                    <li @class(['nav-item', 'site-navbar__divider' => $workspaceLinks !== [] && $loop->index === count($workspaceLinks)])>
                        <a @class(['nav-link', 'active' => $isActive]) href="{{ $link['url'] }}" @if ($isActive) aria-current="page" @endif><i class="{{ $link['icon'] }} site-navbar__icon" aria-hidden="true"></i>{{ $link['label'] }}@if (($link['counter'] ?? null) === 'pending' && $pendingCount > 0)<span class="site-navbar__count">{{ $pendingCount }}<span class="visually-hidden"> pending approval</span></span>@endif</a>
                    </li>
                @endforeach

                @guest
                    <li class="nav-item">
                        <a @class(['nav-link', 'active' => request()->routeIs('login')]) href="{{ route('login') }}" @if (request()->routeIs('login')) aria-current="page" @endif>Log in</a>
                    </li>
                    <li class="nav-item site-navbar__action">
                        <a @class(['btn btn-primary px-4', 'active' => request()->routeIs('register')]) href="{{ route('register') }}" @if (request()->routeIs('register')) aria-current="page" @endif>Register</a>
                    </li>
                @endguest
            </ul>

            @auth
                <div class="site-navbar__account">
                    <x-ui.avatar :name="$user->name" :src="$role === 'doctor' ? ($navProfile?->photoUrl() ?? asset('images/doctor.png')) : null" size="sm" />
                    <div class="site-navbar__identity">
                        <span class="site-navbar__name">{{ $user->name }}</span>
                        <span class="site-navbar__role">{{ $subtitle }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="site-navbar__logout" type="submit"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Log out</span></button>
                    </form>
                </div>
            @endauth
        </div>
    </div>
</nav>
