@extends('layouts.app')

@section('title', 'Appointment details | myDoc')

@section('content')
    <div class="container py-5">
        <a class="link-secondary d-inline-block mb-4" href="{{ $workspace->url('doctor.appointments.index') }}">&larr; Back to appointments</a>
        <x-assistant.workspace-banner class="mb-4" />
        <p class="hero__eyebrow mb-2">{{ $workspace->inAssistantWorkspace() ? 'Doctor Assistant' : 'Doctor space' }}</p>
        <h1 class="h2 mb-4">Appointment details</h1>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 detail-list">
                @if ($workspace->inAssistantWorkspace())
                    <p><strong>Doctor:</strong> {{ $appointment->slot->doctorProfile->user->name }}</p>
                @endif
                <p><strong>Patient:</strong> {{ $appointment->patient->name }}</p>
                <p><strong>Contact:</strong> {{ $appointment->patient->email }}</p>
                <p><strong>Date and time:</strong> {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p>
                <p><strong>Location:</strong> <x-appointments.location :location="$appointment->slot->location" compact /></p>
                <x-appointments.fee :appointment="$appointment" />
                <p><strong>Consultation:</strong> <x-appointments.consultation-type :type="$appointment->consultation_type" /></p>
                <p><strong>Status:</strong> <x-ui.status-badge :status="$appointment->status" /></p>
                <x-appointments.follow-up-links :appointment="$appointment" audience="staff" class="small mb-3" />
                @if ($canBookFollowUp)
                    <a class="btn btn-outline-primary mb-3" href="{{ $workspace->url('doctor.appointments.create', ['follow_up' => $appointment->id]) }}">Book follow-up</a>
                @endif
                @if ($appointment->status === 'cancelled' && $appointment->cancellation_reason)
                    <p class="mb-1"><strong>Cancellation reason (staff only):</strong></p>
                    <p class="text-secondary" data-cancellation-reason>{{ $appointment->cancellation_reason }}</p>
                @endif
                @if ($appointment->cancelled_at)
                    <p><strong>Cancelled:</strong> {{ $appointment->cancelled_at->format('D, M j, Y g:i A') }}</p>
                @endif
                @if ($appointment->consultation_type === 'physical')
                    <p><strong>Visit details:</strong> In-person appointment at the configured clinic location.</p>
                @elseif ($appointment->status === 'confirmed')
                    <p><strong>Online visit:</strong> No voice/video provider is configured yet.</p>
                @endif
                @if ($appointment->status === 'booked')
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ $workspace->url('doctor.appointments.confirm', $appointment) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit">Confirm</button>
                        </form>
                        <form method="POST" action="{{ $workspace->url('doctor.appointments.reject', $appointment) }}">
                            @csrf
                            <button class="btn btn-outline-danger" type="submit">Reject</button>
                        </form>
                    </div>
                @endif
                @if (in_array($appointment->status, ['booked', 'confirmed'], true))
                    <a class="btn btn-outline-primary mt-3" href="{{ $workspace->url('doctor.appointments.reschedule', $appointment) }}">Reschedule</a>
                @endif
                @if (in_array($appointment->status, ['booked', 'confirmed'], true) && $workspace->routeAvailable('doctor.appointments.cancel') && auth()->user()->can('cancel', $appointment))
                    <button class="btn btn-outline-danger mt-3" type="button" data-bs-toggle="modal" data-bs-target="#cancel-appointment">Cancel appointment</button>
                @endif
                @if ($appointment->status === 'confirmed' && $workspace->routeAvailable('doctor.appointments.complete'))
                    <form class="d-inline-block mt-3" method="POST" action="{{ route('doctor.appointments.complete', $appointment) }}">
                        @csrf
                        <button class="btn btn-outline-success" type="submit">Mark completed</button>
                    </form>
                @endif
                @if ($canStart && $workspace->routeAvailable('doctor.appointments.start'))
                    <details class="consultation-launch mt-3">
                        <summary class="btn btn-primary">Start now</summary>
                        <div class="consultation-launch__menu">
                            <p class="small text-secondary mb-2">This is a {{ $appointment->consultation_type }} consultation.</p>
                            <form method="POST" action="{{ route('doctor.appointments.start', $appointment) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary w-100" type="submit">{{ ucfirst($appointment->consultation_type) }} call</button>
                            </form>
                        </div>
                    </details>
                @endif
            </div>
        </div>
    </div>

    @if (in_array($appointment->status, ['booked', 'confirmed'], true) && $workspace->routeAvailable('doctor.appointments.cancel') && auth()->user()->can('cancel', $appointment))
        <div class="modal fade" id="cancel-appointment" tabindex="-1" aria-labelledby="cancel-appointment-title" aria-hidden="true"
            @if ($errors->has('reason')) data-show-on-load @endif>
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ $workspace->url('doctor.appointments.cancel', $appointment) }}" data-disable-on-submit>
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="cancel-appointment-title">Cancel this appointment?</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>{{ $appointment->patient->name }}'s appointment on {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }} will be cancelled and the patient will be notified by email (and SMS if they opted in). This cannot be undone.</p>
                        <label class="form-label" for="reason">Reason for cancelling</label>
                        <textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason" rows="3" required
                            maxlength="{{ App\Services\DoctorAppointmentService::CANCELLATION_REASON_MAX_LENGTH }}" aria-describedby="reason_help">{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text" id="reason_help">Visible to clinic staff only. It is not sent to the patient.</div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Keep appointment</button>
                        <button class="btn btn-danger" type="submit" data-loading-text="Cancelling…">Cancel appointment</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
