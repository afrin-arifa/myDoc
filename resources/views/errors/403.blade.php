@extends('layouts.app')

@section('title', 'No access | myDoc')

@section('content')
    <div class="container py-5">
        <section class="doctor-panel error-page">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <h1 class="h3">You cannot open this page</h1>
            @if (auth()->user()?->role === App\Models\User::ROLE_ASSISTANT)
                <p class="text-secondary">You may no longer be assigned to this doctor, or this action is for doctors only. If this looks wrong, contact your myDoc administrator.</p>
            @else
                <p class="text-secondary">Your account does not have access to it. If this looks wrong, contact your myDoc administrator.</p>
            @endif
            <a class="btn btn-primary" href="{{ auth()->check() ? route('home') : route('login') }}">{{ auth()->check() ? 'Go to my dashboard' : 'Log in' }}</a>
        </section>
    </div>
@endsection
