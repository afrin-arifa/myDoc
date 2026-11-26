@extends('layouts.app')

@section('title', 'Appointment details | myDoc')

@section('content')
    @php
        $slot = $appointment->slot;
        $patient = $appointment->patient;
        $isOpen = in_array($appointment->status, ['booked', 'confirmed'], true);
        $canCancel = $isOpen && $workspace->routeAvailable('doctor.appointments.cancel') && auth()->user()->can('cancel', $appointment);
        $canComplete = $appointment->status === 'confirmed' && $workspace->routeAvailable('doctor.appointments.complete');
        $when = match (true) {
            $slot->starts_at->isToday() => 'Today',
            $slot->starts_at->isTomorrow() => 'Tomorrow',
            $slot->starts_at->isYesterday() => 'Yesterday',
            default => $slot->starts_at->diffForHumans(['parts' => 1]),
        };
    @endphp
    <div class="container py-5 appointment-detail">
        <a class="back-link mb-4" href="{{ $workspace->url('doctor.appointments.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to appointments</a>
        <x-assistant.workspace-banner class="mb-4" />
        <header class="appointment-detail__header">
            <div>
                <p class="hero__eyebrow mb-2">{{ $workspace->inAssistantWorkspace() ? 'Doctor Assistant' : 'Doctor space' }}</p>
                <h1 class="h2 mb-0">Appointment details</h1>
            </div>
            <span class="appointment-detail__reference">{{ $appointment->reference() }}</span>
        </header>

        <section class="appointment-detail__summary" aria-label="Patient and time">
            <div class="appointment-detail__patient">
                <x-ui.avatar :name="$patient->name" :src="$patient->photoUrl()" size="lg" />
                <div>
                    <h2 class="appointment-detail__name">{{ $patient->name }}@if ($patient->is_walk_in) <span class="badge text-bg-light align-middle">Walk-in</span>@endif</h2>
                    <p class="appointment-detail__contact"><i class="fa-regular {{ $patient->email ? 'fa-envelope' : 'fa-address-card' }}" aria-hidden="true"></i>{{ $patient->contactLabel() }}</p>
                    @if ($workspace->inAssistantWorkspace())
                        <p class="appointment-detail__contact"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i>With {{ $slot->doctorProfile->user->name }}</p>
                    @endif
                </div>
            </div>
            <div @class(['appointment-detail__when', 'is-today' => $slot->starts_at->isToday()])>
                <x-ui.status-badge :status="$appointment->status" />
                <span class="appointment-detail__date"><i class="fa-regular fa-calendar" aria-hidden="true"></i>{{ $slot->starts_at->format('D, M j, Y') }}</span>
                <span class="appointment-detail__time"><i class="fa-regular fa-clock" aria-hidden="true"></i>{{ $slot->starts_at->format('g:i A') }} – {{ $slot->ends_at->format('g:i A') }}<span class="appointment-detail__relative">{{ $when }}</span></span>
            </div>
        </section>

        <dl class="appointment-detail__facts">
            <div class="appointment-detail__fact">
                <dt><i class="fa-regular fa-clock" aria-hidden="true"></i>Requested</dt>
                <dd>{{ ($appointment->booked_at ?? $appointment->created_at)?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Location</dt>
                <dd><x-appointments.location :location="$slot->location" /></dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-solid fa-stethoscope" aria-hidden="true"></i>Consultation</dt>
                <dd><x-appointments.consultation-type :type="$appointment->consultation_type" /></dd>
            </div>
            <div class="appointment-detail__fact">
                <dt><i class="fa-regular fa-money-bill-1" aria-hidden="true"></i>{{ App\Services\ConsultationFee::label($appointment->fee_type) ?? 'Consultation fee' }}</dt>
                <dd @class(['text-secondary' => $appointment->consultation_fee === null]) data-appointment-fee>{{ App\Services\ConsultationFee::format($appointment->consultation_fee) }}</dd>
            </div>
        </dl>

        <section class="appointment-detail__notes" aria-label="Visit details">
            @if ($appointment->consultation_type === 'physical')
                <p><strong>Visit details:</strong> In-person appointment at the configured clinic location.</p>
            @elseif ($appointment->status === 'confirmed')
                <p><strong>Online visit:</strong> No voice/video provider is configured yet.</p>
            @endif
            <x-appointments.follow-up-links :appointment="$appointment" audience="staff" />
            @if ($appointment->cancelled_at)
                <p><strong>Cancelled:</strong> {{ $appointment->cancelled_at->format('D, M j, Y g:i A') }}</p>
            @endif
            @if ($appointment->status === 'cancelled' && $appointment->cancellation_reason)
                <div class="appointment-detail__reason">
                    <strong>Cancellation reason (staff only):</strong>
                    <p class="mb-0" data-cancellation-reason>{{ $appointment->cancellation_reason }}</p>
                </div>
            @endif
        </section>

        @if ($isOpen || $canBookFollowUp || ($canStart && $workspace->routeAvailable('doctor.appointments.start')))
            <div class="appointment-detail__actions">
                <div class="appointment-detail__action-group">
                    @if ($appointment->status === 'booked')
                        <form method="POST" action="{{ $workspace->url('doctor.appointments.confirm', $appointment) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Confirm</button>
                        </form>
                    @endif
                    @if ($canStart && $workspace->routeAvailable('doctor.appointments.start'))
                        <details class="consultation-launch">
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
                    @if ($canComplete)
                        <form method="POST" action="{{ route('doctor.appointments.complete', $appointment) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check-double me-1" aria-hidden="true"></i>Mark completed</button>
                        </form>
                    @endif
                    @if ($isOpen)
                        <a class="btn btn-outline-primary" href="{{ $workspace->url('doctor.appointments.reschedule', $appointment) }}"><i class="fa-regular fa-calendar me-1" aria-hidden="true"></i>Reschedule</a>
                    @endif
                    @if ($canBookFollowUp)
                        <a class="btn btn-outline-primary" href="{{ $workspace->url('doctor.appointments.create', ['follow_up' => $appointment->getRouteKey()]) }}"><i class="fa-solid fa-rotate-right me-1" aria-hidden="true"></i>Book follow-up</a>
                    @endif
                </div>
                @if ($appointment->status === 'booked' || $canCancel)
                    <div class="appointment-detail__action-group">
                        @if ($appointment->status === 'booked')
                            <form method="POST" action="{{ $workspace->url('doctor.appointments.reject', $appointment) }}">
                                @csrf
                                <button class="btn btn-outline-danger" type="submit">Reject</button>
                            </form>
                        @endif
                        @if ($canCancel)
                            <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancel-appointment">Cancel appointment</button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    @if ($canCancel)
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
