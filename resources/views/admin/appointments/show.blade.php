@extends('layouts.app')
@section('title', 'Appointment oversight | myDoc')
@section('content')
<div class="container py-5"><a class="link-secondary" href="{{ route('admin.appointments.index') }}">&larr; Back to appointments</a><h1 class="h2 my-4">Appointment details</h1><div class="card border-0 shadow-sm"><div class="card-body"><p><strong>Doctor:</strong> {{ $appointment->slot->doctorProfile->user->name }}</p><p><strong>Patient:</strong> {{ $appointment->patient->name }} ({{ $appointment->patient->email }})</p><p><strong>Time:</strong> {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p><p><strong>Consultation fee:</strong> {{ number_format((float) $appointment->slot->doctorProfile->consultation_fee, 2) }}</p><p><strong>Status:</strong> <span class="status-badge status-badge--{{ $appointment->status === 'confirmed' ? 'success' : ($appointment->status === 'booked' ? 'warning' : 'danger') }}">{{ ucfirst($appointment->status) }}</span></p></div></div></div>
@endsection
