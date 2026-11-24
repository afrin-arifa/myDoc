@extends('layouts.app')

@section('title', 'Forgot password | myDoc')

@section('content')
    <x-auth.card eyebrow="Account recovery" title="Forgot your password?"
        description="Enter the email address you use for myDoc and we will send you a link to choose a new password.">
        <form method="POST" action="{{ route('password.email') }}" data-disable-on-submit>
            @csrf
            <div class="mb-4">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email"
                    value="{{ old('email') }}" required autofocus autocomplete="email">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <button class="btn btn-primary w-100" type="submit" data-loading-text="Sending…">Email reset link</button>
        </form>
        <p class="text-center mt-4 mb-0"><a class="back-link" href="{{ route('login') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to log in</a></p>
    </x-auth.card>
@endsection
