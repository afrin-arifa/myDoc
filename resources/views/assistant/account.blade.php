@extends('layouts.app')

@section('title', 'My account | myDoc')

@section('content')
    <div class="container py-5">
        <a href="{{ route('assistant.dashboard') }}">&larr; Dashboard</a>
        <p class="hero__eyebrow mt-3 mb-2">Doctor Assistant</p>
        <h1 class="h2 mb-4">My account</h1>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="card border-0 shadow-sm p-4" aria-labelledby="account-details-heading">
                    <h2 class="h5 mb-3" id="account-details-heading">Account details</h2>
                    <dl class="row mb-0" data-account-details>
                        <dt class="col-sm-4 text-secondary fw-normal">Name</dt>
                        <dd class="col-sm-8">{{ $assistant->name }}</dd>
                        <dt class="col-sm-4 text-secondary fw-normal">Email</dt>
                        <dd class="col-sm-8 text-break">{{ $assistant->email }}</dd>
                        <dt class="col-sm-4 text-secondary fw-normal">Mobile number</dt>
                        <dd class="col-sm-8">{{ $assistant->phone ?: '—' }}</dd>
                        <dt class="col-sm-4 text-secondary fw-normal">Role</dt>
                        <dd class="col-sm-8">Doctor Assistant</dd>
                        <dt class="col-sm-4 text-secondary fw-normal">Assigned doctors</dt>
                        <dd class="col-sm-8 mb-0" data-assigned-doctors>
                            @forelse ($doctors as $doctor)
                                <a class="d-block" href="{{ route('assistant.workspace.appointments.index', $doctor) }}">{{ $doctor->user->name }}</a>
                            @empty
                                <span class="text-secondary">None yet</span>
                            @endforelse
                        </dd>
                    </dl>
                    <p class="small text-secondary mt-3 mb-0">
                        Your name, email, mobile number and doctor assignments are managed by a myDoc administrator.
                        Contact them if any of these details need to change.
                    </p>
                </section>
            </div>
            <div class="col-lg-5">
                <x-forms.password-form />
            </div>
        </div>
    </div>
@endsection
