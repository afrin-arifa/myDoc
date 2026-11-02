@extends('layouts.app')

@section('title', 'New appointment | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container doctor-workspace__container"><div class="row justify-content-center"><div class="col-xl-8">
        <a class="small text-secondary" href="{{ route('doctor.schedule') }}">&larr; Back to schedule</a>
        <div class="doctor-page-header mt-3"><div><p class="doctor-eyebrow">Manual booking</p><h1 class="doctor-page-title">New appointment</h1><p class="text-secondary mb-0">Book an available slot for a registered patient.</p></div></div>
        <section class="doctor-panel"><form class="mb-4" method="GET" action="{{ route('doctor.appointments.create') }}"><label class="form-label" for="date">Appointment date</label><div class="input-group"><input class="form-control" id="date" name="date" type="date" value="{{ $selectedDate->toDateString() }}"><button class="btn btn-outline-primary" type="submit">Load slots</button></div></form><form method="POST" action="{{ route('doctor.appointments.store') }}">@csrf
            <div class="row g-3"><input name="date" type="hidden" value="{{ $selectedDate->toDateString() }}">
                <div class="col-md-6"><label class="form-label" for="patient_id">Patient</label><select class="form-select" id="patient_id" name="patient_id" required><option value="">Select a patient</option>@foreach ($patients as $patient)<option value="{{ $patient->id }}" @selected(old('patient_id') == $patient->id)>{{ $patient->name }} ({{ $patient->email }})</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="slot_id">Available 10-minute slot</label><select class="form-select" id="slot_id" name="slot_id" required><option value="">Select a slot</option>@foreach ($slots as $slot)<option value="{{ $slot->id }}" @selected(old('slot_id') == $slot->id)>{{ $slot->starts_at->format('g:i A') }} – {{ $slot->ends_at->format('g:i A') }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label" for="consultation_type">Consultation type</label><select class="form-select" id="consultation_type" name="consultation_type" required><option value="physical">Physical visit</option><option value="voice">Voice call</option><option value="video">Video call</option></select></div>
                <div class="col-12"><label class="form-label" for="provider_notes">Notes</label><textarea class="form-control" id="provider_notes" name="provider_notes" rows="4">{{ old('provider_notes') }}</textarea></div>
            </div><button class="btn btn-primary mt-4" type="submit">Create appointment</button>
        </form></section>
    </div></div></div></div>
@endsection
