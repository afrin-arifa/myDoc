@extends('layouts.app')

@section('title', 'New appointment | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container doctor-workspace__container"><div class="row justify-content-center"><div class="col-xl-8">
        <a class="small text-secondary" href="{{ $workspace->url('doctor.schedule') }}">&larr; Back to schedule</a>
        <x-assistant.workspace-banner class="mt-3 mb-0" />
        <div class="doctor-page-header mt-3"><div><p class="doctor-eyebrow">Manual booking</p><h1 class="doctor-page-title">New appointment</h1><p class="text-secondary mb-0">Book an available slot{{ $workspace->inAssistantWorkspace() ? ' with '.$workspace->currentDoctorProfile()->user->name : '' }} for a registered patient.</p></div></div>
        <section class="doctor-panel"><form class="mb-4" method="GET" action="{{ $workspace->url('doctor.appointments.create') }}"><label class="form-label" for="date">Appointment date</label><div class="input-group"><input class="form-control" id="date" name="date" type="date" value="{{ $selectedDate->toDateString() }}"><button class="btn btn-outline-primary" type="submit">Load slots</button></div>@if ($followUpOf)<input type="hidden" name="follow_up" value="{{ $followUpOf->id }}">@endif</form><form method="POST" action="{{ $workspace->url('doctor.appointments.store') }}">@csrf
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
                <div class="col-md-6">
                    <label class="form-label" for="patient_search">Returning patient</label>
                    <input class="form-control" id="patient_search" type="search" autocomplete="off" placeholder="Search by name, email or phone" aria-describedby="patient_search_help" data-patient-search data-search-url="{{ $workspace->url('doctor.patients.search') }}">
                    <input name="patient_id" type="hidden" value="{{ old('patient_id') }}" data-patient-id>
                    <input name="patient_label" type="hidden" value="{{ old('patient_label') }}" data-patient-label>
                    <div class="form-text" id="patient_search_help">Only patients who have booked with {{ $workspace->inAssistantWorkspace() ? $workspace->currentDoctorProfile()->user->name : 'you' }} before are listed.</div>
                    <div class="small fw-semibold mt-1" data-patient-selected aria-live="polite">@if (old('patient_id') && old('patient_label'))Selected: {{ old('patient_label') }}@endif</div>
                    <div class="list-group mt-2" data-patient-search-results></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="patient_email">New patient email</label>
                    <input class="form-control" id="patient_email" name="patient_email" type="email" autocomplete="off" value="{{ old('patient_email') }}" aria-describedby="patient_email_help">
                    <div class="form-text" id="patient_email_help">For a first visit, enter the exact email of a registered patient account.</div>
                </div>
                @endif
                <div class="col-md-6"><label class="form-label" for="slot_id">Available 10-minute slot</label><select class="form-select" id="slot_id" name="slot_id" required><option value="">Select a slot</option>@foreach ($slots as $slot)<option value="{{ $slot->id }}" @selected(old('slot_id') == $slot->id)>{{ $slot->starts_at->format('g:i A') }} – {{ $slot->ends_at->format('g:i A') }} · {{ $slot->location?->name ?? 'Location not specified' }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="consultation_type">Consultation type</label><select class="form-select" id="consultation_type" name="consultation_type" required>@foreach (['physical' => 'Physical visit', 'voice' => 'Voice call', 'video' => 'Video call'] as $type => $typeLabel)@if (in_array($type, App\Models\Appointment::bookableConsultationTypes(), true))<option value="{{ $type }}" @selected(old('consultation_type') === $type)>{{ $typeLabel }}</option>@endif @endforeach</select></div>
                <div class="col-12"><label class="form-label" for="provider_notes">Notes</label><textarea class="form-control" id="provider_notes" name="provider_notes" rows="4">{{ old('provider_notes') }}</textarea></div>
            </div><button class="btn btn-primary mt-4" type="submit">Create appointment</button>
        </form></section>
    </div></div></div></div>
@endsection
