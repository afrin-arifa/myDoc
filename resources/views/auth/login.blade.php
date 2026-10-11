@extends('layouts.app')

@section('title', 'Log in | myDoc')

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-lg-5">
                        <p class="hero__eyebrow mb-2">Welcome back</p>
                        <h1 class="h2 mb-2">Log in to myDoc</h1>
                        <p class="text-secondary mb-4">Continue your care journey.</p>

                        <form method="POST" action="{{ route('login') }}">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label" for="email">Email address</label>
                                <input class="form-control @error('email') is-invalid @enderror" id="email"
                                    name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="password">Password</label>
                                <input class="form-control" id="password" name="password" type="password" required
                                    autocomplete="current-password">
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                                <label class="form-check-label" for="remember">Remember me</label>
                            </div>

                            <button class="btn btn-primary w-100" type="submit">Log in</button>
                        </form>

                        <p class="text-center text-secondary mt-4 mb-0">
                            New to myDoc?
                            <a href="{{ route('register') }}">Create a patient account</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
