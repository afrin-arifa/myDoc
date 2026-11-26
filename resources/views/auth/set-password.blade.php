@extends('layouts.app')

@section('title', 'Choose your password | myDoc')

@section('content')
    <x-auth.card eyebrow="Welcome to myDoc" title="Choose your own password"
        description="Your clinic created this account with a one-time password. Choose a new password to continue.">
        <p class="small text-secondary mb-3"><i class="fa-regular fa-envelope me-1" aria-hidden="true"></i>Signed in as <strong>{{ $user->email }}</strong></p>
        <form method="POST" action="{{ route('password.first.update') }}" data-disable-on-submit>
            @csrf
            @method('PUT')
            <x-auth.new-password-fields />
            <button class="btn btn-primary w-100" type="submit" data-loading-text="Saving…">Save password and continue</button>
        </form>
        <form class="text-center mt-3" method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-link btn-sm" type="submit">Not you? Log out</button>
        </form>
    </x-auth.card>
@endsection
