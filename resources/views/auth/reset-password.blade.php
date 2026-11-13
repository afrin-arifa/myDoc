@extends('layouts.app')

@section('title', 'Reset password | myDoc')

@section('content')
    <x-auth.card eyebrow="Account recovery" title="Choose a new password"
        description="Reset links can be used once and expire after a short time.">
        <form method="POST" action="{{ route('password.update') }}" data-disable-on-submit>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email"
                    value="{{ old('email', $email) }}" required autocomplete="email">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <x-auth.new-password-fields />
            <button class="btn btn-primary w-100" type="submit" data-loading-text="Saving…">Reset password</button>
        </form>
    </x-auth.card>
@endsection
