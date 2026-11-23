
@props(['appointment', 'canStart' => false])

<div class="appointment-management-card">
    <div class="appointment-management-card__patient">
        <div class="appointment-management-card__avatar">
            <img
                src="{{ asset('images/patient.png') }}"
                alt="{{ $appointment->patient->name }} profile image"
            >
        </div>

        <div class="appointment-management-card__patient-info">
            <span class="appointment-management-card__id">
                #APT{{ str_pad($appointment->id, 4, '0', STR_PAD_LEFT) }}
            </span>

            <h4 class="appointment-management-card__name">
                {{ $appointment->patient->name }}
            </h4>
        </div>
    </div>

    <div class="appointment-management-card__schedule">
        <div class="appointment-management-card__datetime">
            <span>
                <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                {{ $appointment->slot->starts_at->format('d M Y') }}
            </span>

            <span>
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                {{ $appointment->slot->starts_at->format('g:i A') }}
            </span>

            <x-appointments.location :location="$appointment->slot->location" compact />

            @if ($appointment->follow_up_of_id)
                <span><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Follow-up to <a href="{{ $workspace->url('doctor.appointments.show', $appointment->follow_up_of_id) }}">{{ App\Models\Appointment::referenceFor($appointment->follow_up_of_id) }}</a></span>
            @endif
        </div>

        <p class="appointment-management-card__type">
            {{ $appointment->consultation_type === 'physical'
                ? 'Physical Visit'
                : ucfirst($appointment->consultation_type) . ' Call' }}
        </p>
    </div>

    <div class="appointment-management-card__footer">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a
                class="appointment-management-card__icon"
                href="{{ $workspace->url('doctor.appointments.show', $appointment) }}"
                aria-label="View appointment details"
                title="View details"
            >
                <i class="fa-regular fa-eye" aria-hidden="true"></i>
            </a>

            <x-ui.status-badge :status="$appointment->status" />
        </div>

        @if ($appointment->status === 'booked')
            <div class="d-flex flex-wrap gap-2 mt-3">
                <form
                    method="POST"
                    action="{{ $workspace->url('doctor.appointments.confirm', $appointment) }}"
                >
                    @csrf
                    <button class="btn btn-sm btn-success" type="submit">
                        <i class="fa-solid fa-check me-1"></i>
                        Approve
                    </button>
                </form>

                <form
                    method="POST"
                    action="{{ $workspace->url('doctor.appointments.reject', $appointment) }}"
                    onsubmit="return confirm('Are you sure you want to reject this appointment?')"
                >
                    @csrf
                    <button class="btn btn-sm btn-outline-danger" type="submit">
                        <i class="fa-solid fa-xmark me-1"></i>
                        Reject
                    </button>
                </form>
            </div>
        @endif

        @if ($canStart && $appointment->status === 'confirmed' && $workspace->routeAvailable('doctor.appointments.start'))
            @if (in_array($appointment->consultation_type, ['voice', 'video'], true))
                <details class="appointment-management-card__launch">
                    <summary class="appointment-management-card__start">
                        Start Now
                    </summary>

                    <div class="appointment-management-card__launch-menu">
                        <form
                            method="POST"
                            action="{{ route('doctor.appointments.start', $appointment) }}"
                        >
                            @csrf

                            <button type="submit" class="appointment-management-card__launch-option">
                                {{ $appointment->consultation_type === 'voice'
                                    ? 'Voice Call'
                                    : 'Video Call' }}
                            </button>
                        </form>
                    </div>
                </details>
            @elseif ($appointment->consultation_type === 'physical')
                <span class="appointment-management-card__physical">
                    Physical Visit
                </span>
            @endif
        @endif
    </div>
</div>
