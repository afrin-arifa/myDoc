<nav class="navbar navbar-expand-lg bg-white py-3" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand fs-3" href="{{ route('home') }}">my<span class="text-primary">Doc</span></a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navigation"
            aria-controls="main-navigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="main-navigation">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}" href="{{ route('home') }}"
                        @if (request()->routeIs('home')) aria-current="page" @endif>Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('doctors.*') ? 'active' : '' }}" href="{{ route('doctors.index') }}"
                        @if (request()->routeIs('doctors.*')) aria-current="page" @endif>Doctors</a>
                </li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/#how-it-works') }}">How it works</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ url('/#features') }}">Why myDoc</a></li>
                @auth
                    @if (auth()->user()->role === 'admin')
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}">Users</a></li>
                    @elseif (auth()->user()->role === 'patient')
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('patient.appointments.*') ? 'active' : '' }}" href="{{ route('patient.appointments.index') }}">My appointments</a></li>
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('patient.profile') ? 'active' : '' }}" href="{{ route('patient.profile') }}">Profile</a></li>
                    @elseif (auth()->user()->role === 'doctor')
                        <li class="nav-item"><a class="nav-link {{ request()->routeIs('doctor.appointments.*') ? 'active' : '' }}" href="{{ route('doctor.appointments.index') }}">Appointments</a></li>
                    @endif
                    <li class="nav-item">
                        <a class="btn btn-primary px-4" href="{{ route(auth()->user()->role . '.dashboard') }}">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="btn btn-link nav-link" type="submit">Log out</button>
                        </form>
                    </li>
                @else
                    <li class="nav-item"><a class="nav-link" href="{{ route('login') }}">Log in</a></li>
                    <li class="nav-item"><a class="btn btn-primary px-4" href="{{ route('register') }}">Get started</a></li>
                @endauth
            </ul>
        </div>
    </div>
</nav>
