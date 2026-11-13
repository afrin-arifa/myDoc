@extends('layouts.app')

@section('title', 'Password set | myDoc')

@section('content')
    <x-auth.card eyebrow="Doctor Assistant invitation" title="Your password is set"
        description="Your Doctor Assistant account is ready. Sign in with your email address and new password to open your workspace.">
        <a class="btn btn-primary w-100" href="{{ route('login') }}">Log in</a>
    </x-auth.card>
@endsection
