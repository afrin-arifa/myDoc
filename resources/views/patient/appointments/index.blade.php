@extends('layouts.app')

@section('title', 'My appointments | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Patient space</p>
        <h1 class="h2 mb-4">My appointments</h1>
        @forelse ($appointments as $appointment)
            <article class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-column flex-md-row justify-content-between gap-3">
                    <div>
                        <h2 class="h5 mb-1">
                            <a class="link-body-emphasis text-decoration-none"
                                href="{{ route('patient.appointments.show', $appointment) }}">
                                {{ $appointment->slot->doctorProfile->user->name }}
                            </a>
                        </h2>
                        <p class="text-secondary mb-1">
                            {{ $appointment->slot->starts_at->format('D, M j, Y g:i A') }}
                        </p>
                        <span class="badge text-bg-light">{{ ucfirst($appointment->status) }}</span>
                    </div>
                    @if (in_array($appointment->status, ['booked', 'confirmed'], true))
                        <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" class="align-self-md-center">
                            @csrf
                            <button class="btn btn-outline-danger" type="submit">Cancel appointment</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-secondary">You do not have any appointments yet.</p>
        @endforelse
        {{ $appointments->links() }}
    </div>
@endsection
