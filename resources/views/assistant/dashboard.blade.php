@extends('layouts.app')

@section('title', 'Assistant dashboard | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Doctor Assistant</p>
        <h1 class="h2 mb-1">Welcome, {{ $assistant->name }}</h1>
        <p class="text-secondary mb-4">{{ $assistant->email }} · You work for {{ $doctors->count() }} {{ Str::plural('doctor', $doctors->count()) }}. Every action you take is recorded under your own account.</p>

        @if ($doctors->isEmpty())
            <x-ui.empty-state class="text-center py-5" icon="fa-solid fa-user-doctor" title="No doctors assigned yet"
                message="An administrator assigns you to the doctors you support. Once assigned, their workspaces appear here." />
        @else
            <div class="row g-3 mb-4">
                @foreach ([
                    'today' => ['Today', 'fa-regular fa-calendar', 'primary'],
                    'pending' => [App\Models\Appointment::statusLabel('booked'), 'fa-regular fa-hourglass-half', 'warning'],
                    'upcoming' => ['Upcoming', 'fa-regular fa-clock', 'info'],
                ] as $key => [$label, $icon, $tone])
                    <div class="col-sm-4"><x-ui.stat :label="$label" :value="$stats[$key]" :icon="$icon" :tone="$tone" /></div>
                @endforeach
            </div>

            <x-announcements.team-notices :announcements="$teamNotices" :show-doctor="$doctors->count() > 1" />

            @if ($pending->isNotEmpty())
                <section class="doctor-panel mb-4" aria-labelledby="pending-heading" data-pending-requests>
                    <div class="doctor-profile-section mb-2"><i class="fa-regular fa-hourglass-half" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="pending-heading">Requests to answer</h2><p>Soonest first. Confirm or reject before the time passes.</p></div></div>
                    @foreach ($pending as $appointment)
                        <div class="admin-profile__assistant flex-wrap">
                            <x-ui.avatar :name="$appointment->patient->name" :src="$appointment->patient->photoUrl()" size="sm" />
                            <div class="min-w-0 flex-grow-1"><strong>{{ $appointment->patient->name }}</strong><span>{{ $appointment->slot->starts_at->format('D, M j, g:i A') }} · {{ $appointment->slot->doctorProfile->user->name }}</span></div>
                            <div class="doctor-row-actions">
                                <form method="POST" action="{{ route('assistant.workspace.appointments.confirm', [$appointment->slot->doctorProfile, $appointment]) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Confirm<span class="visually-hidden"> {{ $appointment->patient->name }}</span></button></form>
                                <form method="POST" action="{{ route('assistant.workspace.appointments.reject', [$appointment->slot->doctorProfile, $appointment]) }}" data-confirm="Reject this request? The time becomes available again and the patient is told.">@csrf<button class="btn btn-sm btn-outline-danger" type="submit">Reject<span class="visually-hidden"> {{ $appointment->patient->name }}</span></button></form>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="mb-5" aria-labelledby="doctors-heading">
                <div class="d-flex justify-content-between align-items-end mb-3">
                    <h2 class="h4 mb-0" id="doctors-heading">Your doctors</h2>
                    <a class="section-link" href="{{ route('assistant.doctors.index') }}">All doctors<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </div>
                @include('assistant.partials.doctor-cards', ['doctors' => $doctors])
            </section>

            <section aria-labelledby="upcoming-heading">
                <h2 class="h4 mb-3" id="upcoming-heading">Upcoming appointments</h2>
                @if ($upcoming->isEmpty())
                    <x-ui.empty-state title="No upcoming appointments" message="Booked and confirmed appointments for your doctors will appear here." />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <caption class="visually-hidden">Next appointments across your doctors</caption>
                            <thead><tr><th scope="col">When</th><th scope="col">Doctor</th><th scope="col">Patient</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody>
                                @foreach ($upcoming as $appointment)
                                    <tr>
                                        <td class="text-nowrap">{{ $appointment->slot->starts_at->format('M j, Y g:i A') }}</td>
                                        <td>{{ $appointment->slot->doctorProfile->user->name }}</td>
                                        <td>{{ $appointment->patient->name }}</td>
                                        <td><x-ui.status-badge :status="$appointment->status" /></td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('assistant.workspace.appointments.show', [$appointment->slot->doctorProfile, $appointment]) }}">View</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
