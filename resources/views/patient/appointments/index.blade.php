@extends('layouts.app')

@section('title', 'My appointments | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Patient space</p>
        <h1 class="h2 mb-4">My appointments</h1>
        @forelse ($appointments as $appointment)
            <x-appointments.appointment-card
                :appointment="$appointment"
                :cancelable="in_array($appointment->status, ['booked', 'confirmed'], true)"
            />
        @empty
            <p class="text-secondary">You do not have any appointments yet.</p>
        @endforelse
        {{ $appointments->links() }}
    </div>
@endsection
