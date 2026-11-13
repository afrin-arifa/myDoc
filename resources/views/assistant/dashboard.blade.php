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
            <section class="mb-5" aria-labelledby="doctors-heading">
                <div class="d-flex justify-content-between align-items-end mb-3">
                    <h2 class="h4 mb-0" id="doctors-heading">Your doctors</h2>
                    <a class="small" href="{{ route('assistant.doctors.index') }}">All doctors</a>
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
