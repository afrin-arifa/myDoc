@props(['appointment', 'cancelable' => false])

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between gap-3">
        <div>
            <h2 class="h5 mb-1">
                <a class="link-body-emphasis text-decoration-none" href="{{ route('patient.appointments.show', $appointment) }}">
                    {{ $appointment->slot->doctorProfile->user->name }}
                </a>
            </h2>
            <p class="text-secondary mb-1">{{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p>
            <x-appointments.consultation-type :type="$appointment->consultation_type" />
            <div class="mt-2">
                <x-ui.status-badge :status="$appointment->status" />
            </div>
        </div>
        @if ($cancelable)
            <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" class="align-self-md-center">
                @csrf
                <button class="btn btn-outline-danger" type="submit">Cancel appointment</button>
            </form>
        @endif
    </div>
</div>
