@extends('layouts.app')

@section('title', 'Account settings | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container-fluid doctor-workspace__container"><div class="row g-4">
        <div class="col-xl-3 col-xxl-2"><x-doctors.profile-summary :profile="$profile" active="settings" :pending-count="$pendingCount" /></div>
        <div class="col-xl-9 col-xxl-10">
            <div class="doctor-page-header"><div><p class="doctor-eyebrow">Account settings</p><h1 class="doctor-page-title">Your account</h1><p class="text-secondary mb-0">Manage personal details, security, and connected services.</p></div></div>
            <div class="row g-4">
                <div class="col-xl-7"><section class="doctor-panel"><h2 class="doctor-panel__title mb-3">Personal information</h2><form method="POST" action="{{ route('doctor.account.update') }}">@csrf @method('PUT')
                    <div class="row g-3"><div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" value="{{ old('name', $profile->user->name) }}" required></div><div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" value="{{ old('email', $profile->user->email) }}" required></div><div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="{{ old('phone', $profile->user->phone) }}"></div><div class="col-12"><label class="form-label" for="address">Address</label><textarea class="form-control" id="address" name="address" rows="3">{{ old('address', $profile->user->address) }}</textarea></div></div>
                    <button class="btn btn-primary mt-4" type="submit">Save personal information</button>
                </form></section></div>
                <div class="col-xl-5"><x-forms.password-form /></div>
                <div class="col-12"><section class="doctor-panel"><div class="doctor-panel__header"><div><p class="doctor-eyebrow mb-1">Connected service</p><h2 class="doctor-panel__title">Google Calendar</h2></div><span class="badge rounded-pill {{ $profile->googleCalendarConnection ? 'text-bg-success' : 'text-bg-light' }}">{{ $profile->googleCalendarConnection ? 'Connected' : 'Not connected' }}</span></div>
                    @if ($profile->googleCalendarConnection)
                        <p class="text-secondary">Events are synced to your primary Google Calendar{{ $profile->googleCalendarConnection->google_account_email ? ' (' . $profile->googleCalendarConnection->google_account_email . ')' : '' }}.</p>
                        <form method="POST" action="{{ route('doctor.google-calendar.disconnect') }}">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit">Disconnect Google Calendar</button></form>
                    @else
                        <p class="text-secondary">Connect your primary Google Calendar to keep confirmed appointments in sync. Existing external events are not automatically removed when disconnecting.</p>
                        <a class="btn btn-primary" href="{{ route('doctor.google-calendar.connect') }}">Connect Google Calendar</a>
                    @endif
                </section></div>
            </div>
        </div>
    </div></div></div>
@endsection
