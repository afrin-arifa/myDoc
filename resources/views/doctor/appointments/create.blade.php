@extends('layouts.app')

@section('title', 'New appointment | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container doctor-workspace__container"><div class="row justify-content-center"><div class="col-xl-8">
        <a class="back-link" href="{{ $workspace->url('doctor.schedule') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to schedule</a>
        <x-assistant.workspace-banner class="mt-3 mb-0" />
        <div class="doctor-page-header mt-3"><div><p class="doctor-eyebrow">Manual booking</p><h1 class="doctor-page-title">New appointment</h1><p class="text-secondary mb-0">Book an available slot{{ $workspace->inAssistantWorkspace() ? ' with '.$workspace->currentDoctorProfile()->user->name : '' }} for a registered or walk-in patient.</p></div></div>
        <section class="doctor-panel"><form class="mb-4" method="GET" action="{{ $workspace->url('doctor.appointments.create') }}"><label class="form-label" for="date">Appointment date</label><div class="input-group"><input class="form-control" id="date" name="date" type="date" value="{{ $selectedDate->toDateString() }}"><button class="btn btn-outline-primary" type="submit">Load slots</button></div>@if ($followUpOf)<input type="hidden" name="follow_up" value="{{ $followUpOf->getRouteKey() }}">@endif</form><form method="POST" action="{{ $workspace->url('doctor.appointments.store') }}">@csrf
            <div class="row g-3"><input name="date" type="hidden" value="{{ $selectedDate->toDateString() }}">
                @if ($followUpOf)
                <div class="col-12">
                    <div class="alert alert-info mb-0" role="status" data-follow-up-banner>
                        Follow-up for <strong>{{ $followUpOf->patient->name }}</strong> to appointment {{ $followUpOf->reference() }} on {{ $followUpOf->slot->starts_at->format('D, M j, Y') }}.
                        <a class="alert-link" href="{{ $workspace->url('doctor.appointments.create', ['date' => $selectedDate->toDateString()]) }}">Book a regular appointment instead</a>
                    </div>
                    <input name="patient_id" type="hidden" value="{{ $followUpOf->patient->id }}">
                    <input name="follow_up_of_id" type="hidden" value="{{ $followUpOf->id }}">
                </div>
                @else
                @php($patientMode = old('walk_in_name') || old('walk_in_phone') ? 'walk_in' : (old('patient_email') ? 'email' : 'returning'))
                <fieldset class="col-12" data-patient-modes>
                    <legend class="form-label">Who is the appointment for?</legend>
                    <div class="account-setup">
                        @foreach ([
                            'returning' => ['Returning patient', 'Has booked with '.($workspace->inAssistantWorkspace() ? 'this doctor' : 'you').' before.'],
                            'email' => ['New, has an account', 'Book by their myDoc email.'],
                            'walk_in' => ['Walk-in or phone call', 'No account needed, only name and mobile.'],
                        ] as $mode => [$label, $hint])
                            <label class="account-setup__option">
                                <input class="form-check-input" type="radio" name="patient_mode" value="{{ $mode }}" @checked($patientMode === $mode)>
                                <span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="col-12" data-patient-panel="returning" data-patient-picker>
                    <label class="form-label" for="patient_search">Returning patient</label>
                    <input class="form-control" id="patient_search" type="search" autocomplete="off" placeholder="Search by name, email or phone" aria-describedby="patient_search_help" data-patient-search data-search-url="{{ $workspace->url('doctor.patients.search') }}">
                    <input name="patient_id" type="hidden" value="{{ old('patient_id') }}" data-patient-id>
                    <input name="patient_label" type="hidden" value="{{ old('patient_label') }}" data-patient-label>
                    <div class="form-text" id="patient_search_help">Only patients who have booked with {{ $workspace->inAssistantWorkspace() ? $workspace->currentDoctorProfile()->user->name : 'you' }} before are listed.</div>
                    <div class="small fw-semibold mt-1" data-patient-selected aria-live="polite">@if (old('patient_id') && old('patient_label'))Selected: {{ old('patient_label') }}@endif</div>
                    <div class="list-group mt-2" data-patient-search-results></div>
                </div>
                <div class="col-12" data-patient-panel="email">
                    <label class="form-label" for="patient_email">Registered patient email</label>
                    <input class="form-control @error('patient_email') is-invalid @enderror" id="patient_email" name="patient_email" type="email" autocomplete="off" value="{{ old('patient_email') }}" aria-describedby="patient_email_help">
                    @error('patient_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="patient_email_help">For a first visit, enter the exact email of a registered patient account.</div>
                </div>
                <div class="col-12" data-patient-panel="walk_in">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="walk_in_name">Patient name</label><input class="form-control @error('walk_in_name') is-invalid @enderror" id="walk_in_name" name="walk_in_name" value="{{ old('walk_in_name') }}" maxlength="255" autocomplete="off">@error('walk_in_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="walk_in_phone">Mobile number</label><input class="form-control @error('walk_in_phone') is-invalid @enderror" id="walk_in_phone" name="walk_in_phone" type="tel" value="{{ old('walk_in_phone') }}" maxlength="30" autocomplete="off" placeholder="01712345678">@error('walk_in_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-12"><div class="form-check"><input class="form-check-input" id="walk_in_sms" name="walk_in_sms" type="checkbox" value="1" @checked(old('walk_in_sms'))><label class="form-check-label" for="walk_in_sms">The patient agrees to appointment SMS on this number</label></div></div>
                    </div>
                </div>
                @endif
                <div class="col-12">@if ($slots->isEmpty())<p class="form-label mb-1">Time</p><p class="text-secondary mb-0">No open times on {{ $selectedDate->format('D, M j, Y') }}. Choose another day above.</p>@else<x-appointments.slot-picker :slots="$slots" :selected="$selectedSlotId" :legend="'Open times on '.$selectedDate->format('D, M j, Y')" />@endif</div>
                <div class="col-md-6"><label class="form-label" for="consultation_type">Consultation type</label><select class="form-select" id="consultation_type" name="consultation_type" required>@foreach (['physical' => 'Physical visit', 'voice' => 'Voice call', 'video' => 'Video call'] as $type => $typeLabel)@if (in_array($type, App\Models\Appointment::bookableConsultationTypes(), true))<option value="{{ $type }}" @selected(old('consultation_type') === $type)>{{ $typeLabel }}</option>@endif @endforeach</select></div>
                <div class="col-12"><label class="form-label" for="provider_notes">Notes</label><textarea class="form-control" id="provider_notes" name="provider_notes" rows="4">{{ old('provider_notes') }}</textarea></div>
            </div><button class="btn btn-primary mt-4" type="submit">Create appointment</button>
        </form></section>
    </div></div></div></div>
@endsection
