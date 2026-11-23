@extends('layouts.app')

@section('title', 'Overview | myDoc')

@section('content')
    <div class="doctor-workspace">
        <div class="container-fluid doctor-workspace__container">
            <div class="row g-4">
                <div class="col-xl-3 col-xxl-2">
                    <x-doctors.profile-summary :profile="$profile" active="overview" :pending-count="$pendingCount" />
                </div>
                <div class="col-xl-9 col-xxl-10">
                    <div class="doctor-page-header">
                        <div>
                            <p class="doctor-eyebrow">Doctor workspace</p>
                            <h1 class="doctor-page-title">Good morning, {{ auth()->user()->name }}.</h1>
                            <p class="text-secondary mb-0">Here is your clinical day at a glance.</p>
                        </div>
                        <a class="btn btn-primary" href="{{ route('doctor.appointments.index') }}">New appointment</a>
                    </div>

                    <div class="row g-3 mb-4">
                        @foreach ([
                            ['label' => 'Total appointments', 'value' => $appointmentCounts['total'], 'tone' => 'blue'],
                            ['label' => App\Models\Appointment::statusLabel('booked'), 'value' => $appointmentCounts['booked'], 'tone' => 'amber'],
                            ['label' => 'Confirmed', 'value' => $appointmentCounts['confirmed'], 'tone' => 'green'],
                            ['label' => 'Cancelled, rejected or expired', 'value' => $appointmentCounts['cancelled'], 'tone' => 'rose'],
                        ] as $stat)
                            <div class="col-sm-6 col-xxl-3">
                                <div class="doctor-stat-card doctor-stat-card--{{ $stat['tone'] }}">
                                    <span class="doctor-stat-card__label">{{ $stat['label'] }}</span>
                                    <strong>{{ $stat['value'] }}</strong>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="row g-4">
                        <div class="col-xl-7">
                            <section class="doctor-panel h-100">
                                <div class="doctor-panel__header">
                                    <div>
                                        <p class="doctor-eyebrow mb-1">Today · {{ today()->format('D, M j') }}</p>
                                        <h2 class="doctor-panel__title">Today's schedule</h2>
                                    </div>
                                    <a href="{{ route('doctor.schedule') }}">View schedule</a>
                                </div>
                                @forelse ($todaySlots as $slot)
                                    @php($appointment = $slot->appointments->first())
                                    @php($status = $appointment?->status ?? ($slot->status === 'available' ? 'available' : $slot->status))
                                    <div doctor-calendar-paneliv class="doctor-schedule-row">
                                        <div class="doctor-schedule-row__time">
                                            <strong>{{ $slot->starts_at->format('g:i') }}</strong>
                                            <span>{{ $slot->starts_at->format('A') }}</span>
                                            <small>{{ $slot->ends_at->format('g:i A') }}</small>
                                        </div>
                                        <div class="doctor-schedule-row__details">
                                            <strong>{{ $appointment?->patient?->name ?? 'Available slot' }}</strong>
                                            <span>{{ $appointment ? ucfirst($appointment->consultation_type) . ' consultation' : 'Open for booking' }}</span>
                                        </div>
                                        <x-ui.status-badge :status="$status" />
                                    </div>
                                @empty
                                    <x-ui.empty-state title="Your day is clear" message="Generate slots or update your weekly availability to build your schedule." action-label="Manage schedule" :action-url="route('doctor.schedule')" />
                                @endforelse
                            </section>
                        </div>

                        <div class="col-xl-5">
                            <section class="doctor-panel h-100">
                                <div class="doctor-panel__header">
                                    <div>
                                        <p class="doctor-eyebrow mb-1">Needs your attention</p>
                                        <h2 class="doctor-panel__title">Pending approvals</h2>
                                    </div>
                                    <span class="doctor-panel__counter">{{ $pendingCount }}</span>
                                </div>
                                @forelse ($pendingAppointments as $appointment)
                                    <div class="doctor-approval-row">
                                        <div>
                                            <strong>{{ $appointment->patient->name }}</strong>
                                            <span>{{ $appointment->slot->starts_at->format('M j · g:i A') }} · {{ ucfirst($appointment->consultation_type) }}</span>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <form method="POST" action="{{ route('doctor.appointments.confirm', $appointment) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('doctor.appointments.reject', $appointment) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                                            </form>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-secondary mb-0">No appointment requests are waiting for review.</p>
                                @endforelse
                            </section>
                        </div>
                    </div>

                    <section class="doctor-panel mt-4">
                        <div class="doctor-panel__header">
                            <div>
                                <p class="doctor-eyebrow mb-1">Activity</p>
                                <h2 class="doctor-panel__title">Recent appointments</h2>
                            </div>
                            <a href="{{ route('doctor.appointments.index') }}">Open appointment manager</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table doctor-table align-middle mb-0">
                                <thead><tr><th>Patient</th><th>Date and time</th><th>Consultation</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    @forelse ($appointments as $appointment)
                                        <tr>
                                            <td><strong>{{ $appointment->patient->name }}</strong></td>
                                            <td>{{ $appointment->slot->starts_at->format('M j, Y · g:i A') }}</td>
                                            <td>{{ ucfirst($appointment->consultation_type) }}</td>
                                            <td><x-ui.status-badge :status="$appointment->status" /></td>
                                            <td class="text-end"><a href="{{ route('doctor.appointments.show', $appointment) }}">View details</a></td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-secondary" colspan="5">Appointments will appear here after patients book your slots.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endsection
