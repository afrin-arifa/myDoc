@extends('layouts.app')

@section('title', 'Create your patient account | myDoc')

@section('content')
    <x-auth.card eyebrow="Get started" title="Create your patient account" description="Start exploring a simpler way to organize your care.">
            <form method="POST" action="{{ route('register') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name"
                        name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name">
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email"
                        name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control @error('password') is-invalid @enderror" id="password"
                        name="password" type="password" required autocomplete="new-password">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label class="form-label" for="password_confirmation">Confirm password</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation"
                        type="password" required autocomplete="new-password">
                </div>

                <button class="btn btn-primary w-100" type="submit">Create account</button>
            </form>

            <p class="text-center text-secondary mt-4 mb-0">
                Already have an account?
                <a href="{{ route('login') }}">Log in</a>
            </p>
    </x-auth.card>
@endsection
