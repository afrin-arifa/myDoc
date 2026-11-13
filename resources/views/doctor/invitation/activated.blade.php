@extends('layouts.app')

@section('title', 'Account activated | myDoc')

@section('content')
    <x-auth.card eyebrow="Doctor invitation" title="Your account is active"
        description="Your password has been set. Log in to complete your professional profile so patients can find you.">
        <ol class="text-secondary mb-4 ps-3">
            <li>Log in with your email address and new password.</li>
            <li>Open <strong>Profile</strong> and add your biography, qualifications, specialties and photo.</li>
            <li>Set your weekly availability and generate appointment slots.</li>
        </ol>
        <a class="btn btn-primary w-100" href="{{ route('login') }}">Log in</a>
    </x-auth.card>
@endsection
