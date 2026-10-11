@extends('layouts.app')

@section('title', 'Doctor appointments | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Doctor space</p>
        <h1 class="h2 mb-4">Appointments</h1>
        <form class="row g-3 mb-4" method="GET">
            <div class="col-md-3">
                <label class="form-label" for="period">Period</label>
                <select class="form-select" id="period" name="period">
                    <option value="">All appointments</option>
                    <option value="upcoming" @selected(request('period') === 'upcoming')>Upcoming</option>
                    <option value="past" @selected(request('period') === 'past')>Past</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach (['booked', 'confirmed', 'completed', 'cancelled', 'rejected'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="date">Date</label>
                <input class="form-control" id="date" name="date" type="date" value="{{ request('date') }}">
            </div>
            <div class="col-md-auto align-self-end"><button class="btn btn-primary" type="submit">Filter</button></div>
        </form>
        @forelse ($appointments as $appointment)
            <article class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h5"><a href="{{ route('doctor.appointments.show', $appointment) }}">{{ $appointment->patient->name }}</a></h2>
                    <p class="mb-1">{{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}</p>
                    <span class="badge text-bg-light">{{ ucfirst($appointment->status) }}</span>
                    @if ($appointment->status === 'booked')
                        <div class="mt-3 d-flex gap-2">
                            <form method="POST" action="{{ route('doctor.appointments.confirm', $appointment) }}">
                                @csrf
                                <button class="btn btn-sm btn-primary" type="submit">Confirm</button>
                            </form>
                            <form method="POST" action="{{ route('doctor.appointments.reject', $appointment) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                            </form>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-secondary">No appointments have been booked.</p>
        @endforelse
        {{ $appointments->links() }}
    </div>
@endsection
