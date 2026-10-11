<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="myDoc helps patients find the right care for their needs.">

        <title>@yield('title', 'myDoc')</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="d-flex min-vh-100 flex-column bg-white">
        <header>
            <nav class="navbar navbar-expand-lg bg-white py-3" aria-label="Main navigation">
                <div class="container">
                    <a class="navbar-brand fs-3" href="{{ url('/') }}">my<span class="text-primary">Doc</span></a>

                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navigation"
                        aria-controls="main-navigation" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>

                    <div class="collapse navbar-collapse" id="main-navigation">
                        <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">
                            <li class="nav-item"><a class="nav-link" href="{{ route('doctors.index') }}">Find a doctor</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/#how-it-works') }}">How it works</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/#features') }}">Why myDoc</a></li>
                            @auth
                                @if (auth()->user()->role === 'admin')
                                    <li class="nav-item"><a class="nav-link" href="{{ route('admin.users.index') }}">Users</a></li>
                                @elseif (auth()->user()->role === 'patient')
                                    <li class="nav-item"><a class="nav-link" href="{{ route('patient.profile') }}">Profile</a></li>
                                @elseif (auth()->user()->role === 'doctor')
                                    <li class="nav-item"><a class="nav-link" href="{{ route('doctor.appointments.index') }}">Appointments</a></li>
                                @endif
                                <li class="nav-item">
                                    <a class="btn btn-primary px-4" href="{{ route(auth()->user()->role . '.dashboard') }}">
                                        Dashboard
                                    </a>
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
        </header>

        <main class="flex-grow-1">
            @if (session('status'))
                <div class="container pt-4">
                    <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="border-top py-4">
            <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
                <span class="fw-semibold">myDoc</span>
                <span class="text-secondary">A simpler way to start your care journey.</span>
            </div>
        </footer>
    </body>
</html>
