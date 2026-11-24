@extends('layouts.app')

@section('title', ($workspace->inAssistantWorkspace() ? 'Patients of '.$profile->user->name : 'My patients').' | myDoc')

@section('content')
    @php($forAssistant = $workspace->inAssistantWorkspace())
    @php($whose = $forAssistant ? $profile->user->name."'s" : 'your')
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-12">
                <div class="doctor-page-header">
                    <div>
                        <p class="doctor-eyebrow">Patients</p>
                        <h1 class="doctor-page-title">{{ $forAssistant ? 'Patients of '.$profile->user->name : 'My patients' }}</h1>
                        <p class="text-secondary mb-0">Patients who have booked at least one appointment with {{ $forAssistant ? $profile->user->name : 'you' }}.</p>
                    </div>
                </div>

                <form class="filter-bar row g-2 align-items-end mb-4" method="GET" action="{{ $workspace->url('doctor.patients.index') }}" role="search">
                    <div class="col-sm-9">
                        <label class="form-label" for="search">{{ $forAssistant ? 'Search patients' : 'Search your patients' }}</label>
                        <input class="form-control" id="search" name="search" type="search" value="{{ $search }}" maxlength="100" placeholder="Name, email or phone">
                    </div>
                    <div class="col-sm-3 d-grid">
                        <button class="btn btn-outline-primary" type="submit">Search</button>
                    </div>
                </form>

                @if ($patients->isEmpty())
                    <x-ui.empty-state
                        :title="$search ? 'No matching patients' : 'No patients yet'"
                        :message="$search ? 'None of '.$whose.' patients match that search.' : 'Patients appear here after they book an appointment with '.($forAssistant ? $profile->user->name : 'you').'.'"
                        :action-label="$search ? 'Clear search' : null"
                        :action-url="$search ? $workspace->url('doctor.patients.index') : null" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <caption class="visually-hidden">{{ ucfirst($whose) }} patients</caption>
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
                                            <x-ui.avatar :name="$patient->name" :src="$patient->photoUrl()" size="sm" class="me-2 float-start" /><strong>{{ $patient->name }}</strong>
                                            @if ($patient->deactivated_at)
                                                <span class="badge text-bg-light ms-1">Inactive account</span>
                                            @endif
                                            @if ($patient->is_walk_in)<span class="badge text-bg-light ms-1">Walk-in</span>@endif
                                            <br><span class="small text-secondary">{{ $patient->email ?? 'No account' }}</span>
                                        </td>
                                        <td>{{ $patient->phone ?: '—' }}</td>
                                        <td class="text-center">{{ $patient->appointment_count }}</td>
                                        <td class="text-center">{{ $patient->upcoming_count }}</td>
                                        <td>{{ $patient->last_appointment_at?->format('M j, Y') ?? '—' }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="{{ $workspace->url('doctor.appointments.index', ['tab' => 'all', 'search' => $patient->email ?? $patient->name]) }}">Appointment history</a>
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
