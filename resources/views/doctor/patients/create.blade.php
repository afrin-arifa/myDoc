@extends('layouts.app')

@section('title', 'Add patient | myDoc')

@section('content')
    <div class="container py-5">
        <a class="back-link mb-4" href="{{ $workspace->url('doctor.patients.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Patients</a>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="doctor-page-header">
                    <div>
                        <p class="doctor-eyebrow">Patients</p>
                        <h1 class="doctor-page-title">Add a patient account</h1>
                        <p class="text-secondary mb-0">Create a myDoc account for a patient {{ $workspace->inAssistantWorkspace() ? 'of '.$profile->user->name : 'at your clinic' }}. You will get a one-time password to give them.</p>
                    </div>
                </div>
                <form class="card border-0 shadow-sm p-4" method="POST" action="{{ $workspace->url('doctor.patients.store') }}" data-disable-on-submit>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="name">Full name</label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="off">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="off" aria-describedby="email_help">
                        <div class="form-text" id="email_help">The patient signs in with this address.</div>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="phone">Mobile number (optional)</label>
                        <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="30" placeholder="01XXXXXXXXX" autocomplete="off">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary align-self-start" type="submit" data-loading-text="Creating…"><i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>Create account</button>
                </form>
            </div>
            <div class="col-lg-5">
                <aside class="credentials-help">
                    <h2 class="h6"><i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>How the one-time password works</h2>
                    <ol>
                        <li>After you save, the email and a one-time password appear once on the patients page.</li>
                        <li>Give them to the patient in person or by phone. myDoc does not email the password.</li>
                        <li>The password works for {{ $passwordLifetimeHours }} hours. At first sign-in the patient must choose their own.</li>
                        <li>If it is lost or expires before then, issue a new one from the patient list.</li>
                    </ol>
                    <p class="small text-secondary mb-0">The new account counts as {{ $workspace->inAssistantWorkspace() ? $profile->user->name."'s" : 'your' }} patient, so they see {{ $workspace->inAssistantWorkspace() ? 'the doctor’s' : 'your' }} announcements and can be booked from the patient search.</p>
                </aside>
            </div>
        </div>
    </div>
@endsection
