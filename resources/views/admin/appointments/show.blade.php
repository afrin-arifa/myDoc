@extends('layouts.app')
@section('title', 'Appointment oversight | myDoc')
@section('content')
<div class="container py-5"><a class="link-secondary" href="{{ route('admin.appointments.index') }}">&larr; Back to appointments</a><h1 class="h2 my-4">Appointment details</h1><div class="card border-0 shadow-sm"><div class="card-body"><p><strong>Doctor:</strong> {{ $appointment->slot->doctorProfile->user->name }}</p><p><strong>Patient:</strong> {{ $appointment->patient->name }} ({{ $appointment->patient->email }})</p><p><strong>Time:</strong> {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p><p><strong>Status:</strong> {{ ucfirst($appointment->status) }}</p></div></div></div>
@endsection
