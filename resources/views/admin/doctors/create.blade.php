@extends('layouts.app')
@section('title', 'Add doctor | myDoc')
@section('content')
<div class="container py-5">
    <a href="{{ route('admin.doctors.index') }}">&larr; Doctors</a>
    <h1 class="h2 mt-3 mb-1">Add doctor</h1>
    <p class="text-secondary mb-4">The doctor receives an invitation email to set their own password. The link is valid for {{ $invitationHours }} hours and works once. No password is set or shown here.</p>

    <form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('admin.doctors.store') }}" data-disable-on-submit>
        @csrf
        <h2 class="h5 mb-3">Account</h2>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="name">Full name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="off">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="off" aria-describedby="email_help">
                <div class="form-text" id="email_help">The invitation is sent here and becomes the doctor's sign-in.</div>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="phone">Phone (optional)</label>
                <input class="form-control" id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="30">
            </div>
        </div>

        <h2 class="h5 mb-3">Professional profile</h2>
        <div class="row g-3">
            <x-admin.doctor-profile-fields :specialties="$specialties" />
        </div>

        <div class="d-flex gap-2 mt-4">
            <button class="btn btn-primary" type="submit" data-loading-text="Creating…">Create doctor and send invitation</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.doctors.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
