@extends('layouts.app')

@section('title', 'My account | myDoc')

@section('content')
    <div class="container py-5">
        <header class="doctor-detail__hero patient-profile__hero">
            <x-users.photo-editor :user="$assistant" form="assistant-photo-form" visibility="Seen by you, your doctors and administrators." />
            <div class="doctor-detail__intro">
                <p class="doctor-detail__title">My account</p>
                <h1 class="doctor-detail__name">{{ $assistant->name }}</h1>
                <ul class="patient-profile__contact mb-0" aria-label="Contact details">
                    <li><i class="fa-regular fa-envelope" aria-hidden="true"></i>{{ $assistant->email }}</li>
                    <li><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>{{ $assistant->phone ?: 'No mobile number yet' }}</li>
                </ul>
            </div>
            <dl class="doctor-detail__facts">
                @if ($assistant->created_at)
                    <div><dt>Member since</dt><dd>{{ $assistant->created_at->format('M Y') }}</dd></div>
                @endif
                <div><dt>Role</dt><dd>Assistant</dd></div>
                <div><dt>Doctors</dt><dd>{{ $doctors->count() }}</dd></div>
            </dl>
        </header>
        <form id="assistant-photo-form" method="POST" action="{{ route('assistant.account.photo') }}" enctype="multipart/form-data" data-disable-on-submit>@csrf @method('PUT')</form>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="doctor-panel" aria-labelledby="account-details-heading">
                    <div class="doctor-profile-section"><i class="fa-regular fa-id-card" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="account-details-heading">Account details</h2><p>Managed by a myDoc administrator.</p></div></div>
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
                                <a class="d-block fw-semibold" href="{{ route('assistant.workspace.appointments.index', $doctor) }}">{{ $doctor->user->name }}</a>
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
