@extends('layouts.app')
@section('title', 'Add assistant | myDoc')
@section('content')
<div class="container py-5">
    <a class="back-link" href="{{ route('admin.assistants.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Doctor assistants</a>
    <h1 class="h2 mt-3 mb-1">Add a Doctor Assistant</h1>
    <p class="text-secondary mb-4">Create the login an assistant uses to help doctors with appointments and schedules. They log in on the same page as everyone else.</p>

    <form class="doctor-panel" data-account-setup method="POST" action="{{ route('admin.assistants.store') }}" data-disable-on-submit>
        @csrf
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required maxlength="255">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="phone">Mobile number (optional)</label>
                <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="30" placeholder="01712345678">
                @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        @php($setup = old('setup', 'password'))
        <fieldset class="mt-4">
            <legend class="form-label">How will they get their password?</legend>
            <div class="account-setup">
                <label class="account-setup__option">
                    <input class="form-check-input" type="radio" name="setup" value="password" @checked($setup === 'password')>
                    <span><strong>Set a password now</strong><small>The account is active at once. Give the password to the assistant yourself.</small></span>
                </label>
                <label class="account-setup__option">
                    <input class="form-check-input" type="radio" name="setup" value="invitation" @checked($setup === 'invitation')>
                    <span><strong>Email an invitation</strong><small>They choose their own password. The link works once and is valid for 72 hours. Assign doctors after that.</small></span>
                </label>
            </div>
            @error('setup')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </fieldset>
        <div class="row g-3 mt-1" data-setup-password @if ($setup !== 'password') hidden @endif>
            <div class="col-md-6">
                <label class="form-label" for="password">Password</label>
                <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="new-password" maxlength="255" aria-describedby="password_help" @if ($setup === 'password') required @endif>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text" id="password_help">At least 8 characters, with upper- and lower-case letters and a number.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password_confirmation">Confirm password</label>
                <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="255" @if ($setup === 'password') required @endif>
            </div>
            <div class="col-12">
                <span class="form-label d-block" id="doctors-label">Doctors they help (optional)</span>
                @if ($doctors->isEmpty())
                    <p class="form-text m-0">There are no active doctors yet. You can assign doctors later.</p>
                @else
                    <div class="doctor-specialty-picker" role="group" aria-labelledby="doctors-label">
                        @foreach ($doctors as $doctor)
                            <label class="doctor-specialty-option"><input class="form-check-input" name="doctor_profile_ids[]" type="checkbox" value="{{ $doctor->id }}" data-optional @checked(in_array((string) $doctor->id, array_map('strval', (array) old('doctor_profile_ids', [])), true))><span>{{ $doctor->user->name }}</span></label>
                        @endforeach
                    </div>
                    <p class="form-text mb-0">Without a doctor they can log in but have nothing to manage. You can change this later.</p>
                @endif
                @error('doctor_profile_ids')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
            <div class="col-12"><p class="form-text m-0">Ask the assistant to change it after their first login, under Account.</p></div>
        </div>
        <button class="btn btn-primary mt-4" type="submit" data-loading-text="Creating…">Create assistant account</button>
    </form>
</div>
@endsection
