@extends('layouts.app')

@section('title', 'Activate your doctor account | myDoc')

@section('content')
    <x-auth.card eyebrow="Doctor invitation" title="Activate your account"
        description="Welcome, {{ $name }}. Choose a password to activate your myDoc doctor account.">
        <form method="POST" action="{{ route('doctor.invitation.accept') }}" data-disable-on-submit>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="mb-3">
                <label class="form-label" for="account_email">Account email</label>
                <input class="form-control" id="account_email" type="email" value="{{ $email }}" readonly
                    aria-describedby="account_email_help">
                <div class="form-text" id="account_email_help">You will sign in with this address.</div>
            </div>
            <x-auth.new-password-fields />
            <button class="btn btn-primary w-100" type="submit" data-loading-text="Activating…">Set password and activate</button>
        </form>
    </x-auth.card>
@endsection
