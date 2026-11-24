@extends('layouts.app')

@section('title', 'Account settings | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container-fluid doctor-workspace__container"><div class="row g-4">
        <div class="col-12">
            <div class="doctor-page-header"><div><p class="doctor-eyebrow">Account settings</p><h1 class="doctor-page-title">Your account</h1><p class="text-secondary mb-0">Manage personal details, security, and connected services.</p></div></div>
            <div class="row g-4">
                <div class="col-xl-7"><section class="doctor-panel"><h2 class="doctor-panel__title mb-3">Personal information</h2><form method="POST" action="{{ route('doctor.account.update') }}">@csrf @method('PUT')
                    <div class="row g-3"><div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" value="{{ old('name', $profile->user->name) }}" required></div><div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" value="{{ old('email', $profile->user->email) }}" required></div><div class="col-md-6"><label class="form-label" for="phone">Mobile number</label><input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" type="tel" autocomplete="tel" placeholder="01712345678" value="{{ old('phone', $profile->user->phone) }}">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><div class="col-12"><label class="form-label" for="address">Address</label><textarea class="form-control" id="address" name="address" rows="3">{{ old('address', $profile->user->address) }}</textarea></div><div class="col-12"><x-forms.sms-preference :user="$profile->user" /></div></div>
                    <button class="btn btn-primary mt-4" type="submit">Save personal information</button>
                </form></section></div>
                <div class="col-xl-5"><x-forms.password-form /></div>
                @php
                    $calendar = $profile->googleCalendarConnection;
                    $calendarState = match (true) {
                        $calendar === null => ['label' => 'Not connected', 'class' => 'text-bg-light'],
                        $calendar->is_connected => ['label' => 'Connected', 'class' => 'text-bg-success'],
                        default => ['label' => 'Reconnection needed', 'class' => 'text-bg-warning'],
                    };
                @endphp
                <div class="col-12"><section class="doctor-panel"><div class="doctor-panel__header"><div><p class="doctor-eyebrow mb-1">Connected service</p><h2 class="doctor-panel__title">Google Calendar</h2></div><span class="badge rounded-pill {{ $calendarState['class'] }}" data-google-calendar-state>{{ $calendarState['label'] }}</span></div>
                    @if ($calendar)
                        @if ($calendar->is_connected)
                            <p class="text-secondary">Confirmed appointments are synced to your primary Google Calendar{{ $calendar->google_account_email ? ' (' . $calendar->google_account_email . ')' : '' }} as private "myDoc Appointment" events, without patient details.
                                {{ $calendar->last_synced_at ? 'Last synced '.$calendar->last_synced_at->copy()->setTimezone(config('app.timezone'))->format('M j, Y g:i A T').'.' : 'Nothing has been synced yet.' }}</p>
                        @else
                            <x-ui.alert type="warning" role="alert">Google no longer accepts myDoc's access to your calendar (access may have been removed from your Google account, or it expired). Appointments are not being synced. Reconnect to resume; events for appointments cancelled meanwhile are removed after you reconnect.</x-ui.alert>
                        @endif
                        @if ($calendarIssueCount > 0)
                            <p class="small text-secondary">{{ $calendarIssueCount }} {{ Str::plural('appointment', $calendarIssueCount) }} could not be updated in Google Calendar yet. myDoc retries automatically{{ $calendar->is_connected ? '' : ' after you reconnect' }}.</p>
                        @endif
                        <div class="d-flex flex-wrap gap-2">
                            @unless ($calendar->is_connected)
                                <a class="btn btn-primary" href="{{ route('doctor.google-calendar.connect') }}">Reconnect Google Calendar</a>
                            @endunless
                            <form method="POST" action="{{ route('doctor.google-calendar.disconnect') }}" data-confirm="Disconnect Google Calendar? Events already in your Google Calendar are not deleted.">@csrf @method('DELETE')<button class="btn btn-outline-danger" type="submit">Disconnect Google Calendar</button></form>
                        </div>
                    @else
                        <p class="text-secondary">Connect your primary Google Calendar to keep confirmed appointments in sync. Existing external events are not automatically removed when disconnecting.</p>
                        <a class="btn btn-primary" href="{{ route('doctor.google-calendar.connect') }}">Connect Google Calendar</a>
                    @endif
                </section></div>
            </div>
        </div>
    </div></div></div>
@endsection
