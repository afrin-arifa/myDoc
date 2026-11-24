@extends('layouts.app')

@section('title', 'Log in | myDoc')

@section('content')
    <x-auth.card eyebrow="Welcome back" title="Log in to myDoc" description="Continue your care journey.">
            <form method="POST" action="{{ route('login') }}" data-disable-on-submit>
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

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check mb-0">
                        <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    <a class="small fw-semibold" href="{{ route('password.request') }}">Forgot password?</a>
                </div>

                <button class="btn btn-primary w-100" type="submit" data-loading-text="Logging in…">Log in</button>
            </form>

            <p class="text-center text-secondary mt-4 mb-0">
                New to myDoc?
                <a href="{{ route('register') }}">Create a patient account</a>
            </p>
    </x-auth.card>
@endsection
