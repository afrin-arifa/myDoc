@extends('layouts.app')

@section('title', 'Set up your assistant account | myDoc')

@section('content')
    <x-auth.card eyebrow="Doctor Assistant invitation" title="Set your password"
        description="Welcome, {{ $name }}. Choose a password for your myDoc Doctor Assistant account.">
        <form method="POST" action="{{ route('assistant.invitation.accept') }}" data-disable-on-submit>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="mb-3">
                <label class="form-label" for="account_email">Account email</label>
                <input class="form-control" id="account_email" type="email" value="{{ $email }}" readonly
                    aria-describedby="account_email_help">
                <div class="form-text" id="account_email_help">This is the address your account uses.</div>
            </div>
            <x-auth.new-password-fields />
            <button class="btn btn-primary w-100" type="submit" data-loading-text="Saving…">Set password</button>
        </form>
    </x-auth.card>
@endsection
