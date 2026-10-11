
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
        </div>

        <p class="appointment-management-card__type">
            {{ $appointment->consultation_type === 'physical'
                ? 'Physical Visit'
                : ucfirst($appointment->consultation_type) . ' Call' }}
        </p>
    </div>

    <div class="appointment-management-card__footer">
        <div class="appointment-management-card__actions">
            <a
                class="appointment-management-card__icon"
                href="{{ route('doctor.appointments.show', $appointment) }}"
                aria-label="View appointment details"
                title="View details"
            >
                <i class="fa-regular fa-eye" aria-hidden="true"></i>
            </a>

            <span
                class="appointment-management-card__icon"
                title="{{ ucfirst($appointment->status) }}"
                aria-label="Appointment status: {{ $appointment->status }}"
            >
                <i class="fa-solid fa-user-check" aria-hidden="true"></i>
            </span>

            <span
                class="appointment-management-card__icon"
                title="{{ ucfirst($appointment->status) }}"
                aria-hidden="true"
            >
                <i class="fa-solid fa-circle-xmark"></i>
            </span>
        </div>

        @if ($canStart && $appointment->status === 'confirmed')
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
            @else
                <span class="appointment-management-card__physical">
                    Physical Visit
                </span>
            @endif
        @endif
    </div>
</div>
