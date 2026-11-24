@extends('layouts.app')

@section('title', 'My profile | myDoc')

@section('content')
    <div class="container py-5">
        <header class="doctor-detail__hero patient-profile__hero">
            <x-users.photo-editor :user="$user" form="patient-profile-form" />
            <div class="doctor-detail__intro">
                <p class="doctor-detail__title">My profile</p>
                <h1 class="doctor-detail__name">{{ $user->name }}</h1>
                <ul class="patient-profile__contact" aria-label="Contact details">
                    <li><i class="fa-regular fa-envelope" aria-hidden="true"></i>{{ $user->email }}</li>
                    <li><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>{{ $user->phone ?: 'No mobile number yet' }}</li>
                </ul>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('patient.appointments.index') }}"><i class="fa-regular fa-calendar me-2" aria-hidden="true"></i>Appointment history</a>
            </div>
            <dl class="doctor-detail__facts">
                @if ($user->created_at)
                    <div><dt>Member since</dt><dd>{{ $user->created_at->format('M Y') }}</dd></div>
                @endif
                <div><dt>SMS reminders</dt><dd>{{ $user->sms_notifications_enabled ? 'On' : 'Off' }}</dd></div>
                <div><dt>Email updates</dt><dd>On</dd></div>
            </dl>
        </header>

        <div class="row g-4">
            <div class="col-lg-7">
                <form class="doctor-panel" id="patient-profile-form" method="POST" action="{{ route('patient.profile.update') }}" enctype="multipart/form-data" aria-labelledby="personal-heading" data-disable-on-submit>
                    @csrf @method('PUT')
                    <div class="doctor-profile-section"><i class="fa-regular fa-user" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="personal-heading">Personal information</h2><p>Used for your bookings and appointment updates.</p></div></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="name">Name</label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="email" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="phone">Mobile number <span class="fw-normal text-secondary">(optional)</span></label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" maxlength="30" autocomplete="tel" placeholder="01712345678" aria-describedby="phone_help">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="phone_help">Bangladeshi mobile number, e.g. 01712345678 or +8801712345678.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address">Address <span class="fw-normal text-secondary">(optional)</span></label>
                            <textarea class="form-control @error('address') is-invalid @enderror" id="address" name="address" rows="3" maxlength="1000" autocomplete="street-address">{{ old('address', $user->address) }}</textarea>
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="patient-profile__preference"><x-forms.sms-preference class="form-switch mb-0" :user="$user" /></div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save profile</button>
                </form>
            </div>
            <div class="col-lg-5 d-grid gap-4 align-content-start">
                <x-forms.password-form />
                <form class="doctor-panel patient-profile__danger" method="POST" action="{{ route('patient.account.deactivate') }}" data-confirm="Deactivate your account? You will be signed out and need to contact support to restore access.">
                    @csrf @method('DELETE')
                    <div class="doctor-profile-section"><i class="fa-solid fa-user-slash" aria-hidden="true"></i><div><h2 class="doctor-panel__title">Deactivate account</h2><p>You will be signed out and can contact support to restore access.</p></div></div>
                    <button class="btn btn-outline-danger" type="submit">Deactivate my account</button>
                </form>
            </div>
        </div>
    </div>
@endsection
