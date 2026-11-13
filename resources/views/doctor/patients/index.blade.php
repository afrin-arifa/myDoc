@extends('layouts.app')

@section('title', 'My patients | myDoc')

@section('content')
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-3">
                <x-doctors.profile-summary :profile="$profile" active="patients" :pending-count="$pendingCount" />
            </div>
            <div class="col-lg-9">
                <div class="doctor-page-header">
                    <div>
                        <p class="doctor-eyebrow">Patients</p>
                        <h1 class="doctor-page-title">My patients</h1>
                        <p class="text-secondary mb-0">Patients who have booked at least one appointment with you.</p>
                    </div>
                </div>

                <form class="row g-2 align-items-end mb-4" method="GET" action="{{ route('doctor.patients.index') }}" role="search">
                    <div class="col-sm-9">
                        <label class="form-label" for="search">Search your patients</label>
                        <input class="form-control" id="search" name="search" type="search" value="{{ $search }}" maxlength="100" placeholder="Name, email or phone">
                    </div>
                    <div class="col-sm-3 d-grid">
                        <button class="btn btn-outline-primary" type="submit">Search</button>
                    </div>
                </form>

                @if ($patients->isEmpty())
                    <x-ui.empty-state
                        :title="$search ? 'No matching patients' : 'No patients yet'"
                        :message="$search ? 'None of your patients match that search.' : 'Patients appear here after they book an appointment with you.'"
                        :action-label="$search ? 'Clear search' : null"
                        :action-url="$search ? route('doctor.patients.index') : null" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <caption class="visually-hidden">Your patients</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Patient</th>
                                    <th scope="col">Phone</th>
                                    <th scope="col" class="text-center">Appointments</th>
                                    <th scope="col" class="text-center">Upcoming</th>
                                    <th scope="col">Last appointment</th>
                                    <th scope="col"><span class="visually-hidden">History</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($patients as $patient)
                                    <tr>
                                        <td>
                                            <strong>{{ $patient->name }}</strong>
                                            @if ($patient->deactivated_at)
                                                <span class="badge text-bg-light ms-1">Inactive account</span>
                                            @endif
                                            <br><span class="small text-secondary">{{ $patient->email }}</span>
                                        </td>
                                        <td>{{ $patient->phone ?: '—' }}</td>
                                        <td class="text-center">{{ $patient->appointment_count }}</td>
                                        <td class="text-center">{{ $patient->upcoming_count }}</td>
                                        <td>{{ $patient->last_appointment_at?->format('M j, Y') ?? '—' }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('doctor.appointments.index', ['tab' => 'all', 'search' => $patient->email]) }}">Appointment history</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $patients->links() }}
                @endif
            </div>
        </div>
    </div>
@endsection
