@extends('layouts.app')

@section('title', ($workspace->inAssistantWorkspace() ? 'Appointments for '.$profile->user->name : 'Doctor appointments').' | myDoc')

@section('content')
    @php($baseQuery = request()->except(['tab', 'page']))
    @php($forAssistant = $workspace->inAssistantWorkspace())

    <div class="container py-5">
        <div class="row g-4">
            <div class="col-12">
                <nav class="small mb-3" aria-label="Breadcrumb">
                    <a href="{{ $forAssistant ? route('assistant.dashboard') : route('doctor.dashboard') }}">Dashboard</a>
                    @if ($forAssistant)
                        <span class="text-secondary"> / </span><a href="{{ route('assistant.doctors.index') }}">My doctors</a>
                        <span class="text-secondary"> / {{ $profile->user->name }}</span>
                    @endif
                    <span class="text-secondary"> / Appointments</span>
                </nav>
                <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                    <div>
                        @if ($forAssistant)
                            <p class="hero__eyebrow mb-2">Doctor Assistant</p>
                            <h1 class="h2 mb-2">Appointments for {{ $profile->user->name }}</h1>
                            <p class="text-secondary mb-0">Review this doctor's appointments and keep each patient visit on track.</p>
                        @else
                            <p class="hero__eyebrow mb-2">Doctor workspace</p>
                            <h1 class="h2 mb-2">Appointment management</h1>
                            <p class="text-secondary mb-0">Review your schedule and keep each patient visit on track.</p>
                        @endif
                    </div>
                    <span class="text-secondary small">{{ $appointments->total() }} results</span>
                </div>

                <nav class="appointment-tabs mb-4" aria-label="Appointment status">
                    <a class="{{ request('tab', 'upcoming') === 'upcoming' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.appointments.index', array_merge($baseQuery, ['tab' => 'upcoming'])) }}">Upcoming</a>
                    <a class="{{ request('tab') === 'cancelled' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.appointments.index', array_merge($baseQuery, ['tab' => 'cancelled'])) }}">Cancelled</a>
                    <a class="{{ request('tab') === 'completed' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.appointments.index', array_merge($baseQuery, ['tab' => 'completed'])) }}">Completed</a>
                    <a class="{{ request('tab') === 'all' ? 'is-active' : '' }}" href="{{ $workspace->url('doctor.appointments.index', array_merge($baseQuery, ['tab' => 'all'])) }}">All</a>
                </nav>

                <form class="appointment-filters card border-0 shadow-sm mb-4" method="GET">
                    @if (request('tab'))
                        <input name="tab" type="hidden" value="{{ request('tab') }}">
                    @endif
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="search">Search patient</label>
                                <input class="form-control" id="search" name="search" type="search" value="{{ request('search') }}" placeholder="Name or email">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All statuses</option>
                                    @foreach (App\Models\Appointment::STATUS_LABELS as $status => $statusLabel)
                                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="period">Period</label>
                                <select class="form-select" id="period" name="period">
                                    <option value="">Any period</option>
                                    <option value="upcoming" @selected(request('period') === 'upcoming')>Upcoming</option>
                                    <option value="past" @selected(request('period') === 'past')>Past</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="date_from">From</label>
                                <input class="form-control" id="date_from" name="date_from" type="date" value="{{ request('date_from') }}">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="date_to">To</label>
                                <input class="form-control" id="date_to" name="date_to" type="date" value="{{ request('date_to') }}">
                            </div>
                            <div class="col-md-2 align-self-end d-grid">
                                <button class="btn btn-primary" type="submit">Filter</button>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="appointment-management-list">
                    @forelse ($appointments as $appointment)
                        <x-appointments.doctor-card
                            :appointment="$appointment"
                            :can-start="in_array($appointment->id, $startableAppointmentIds, true)"
                        />
                    @empty
                        <x-ui.empty-state
                            title="No appointments found"
                            :message="'Try a different filter or check back when a patient books one of '.($forAssistant ? $profile->user->name."'s" : 'your').' slots.'"
                            action-label="Clear filters"
                            :action-url="$workspace->url('doctor.appointments.index')"
                        />
                    @endforelse
                </div>

                {{ $appointments->links() }}
            </div>
        </div>
    </div>
@endsection
