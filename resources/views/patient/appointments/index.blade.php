@extends('layouts.app')

@section('title', 'My appointments | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Patient space</p>
        <h1 class="h2 mb-4">My appointments</h1>
        @forelse ($appointments as $appointment)
            <x-appointments.appointment-card
                :appointment="$appointment"
                :cancelable="$appointment->isCancellableByPatient()"
            />
        @empty
            <x-ui.empty-state class="text-center py-5" icon="fa-regular fa-calendar" title="You do not have any appointments yet." message="Find a doctor and choose a time that suits you."
                action-label="Find a doctor" :action-url="route('doctors.index')" />
        @endforelse
        {{ $appointments->links() }}
    </div>
@endsection
