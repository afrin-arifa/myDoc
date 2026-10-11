@extends('layouts.app')

@section('title', $doctor->user->name.' | myDoc')

@section('content')
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-7">
                <p class="hero__eyebrow mb-2">{{ $doctor->professional_title }}</p>
                <h1 class="h2 mb-3">{{ $doctor->user->name }}</h1>
                <p class="feature-card__description">{{ $doctor->bio }}</p>
                <p><strong>Experience:</strong> {{ $doctor->years_of_experience ?? 'Not specified' }} years</p>
                <p><strong>Consultation fee:</strong> {{ number_format((float) $doctor->consultation_fee, 2) }}</p>
                <p><strong>Availability:</strong> <span class="status-badge status-badge--success">Accepting patients</span></p>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($doctor->specialties as $specialty)
                        <span class="badge text-bg-light">{{ $specialty->name }}</span>
                    @endforeach
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Available times</h2>
                        @forelse ($doctor->appointmentSlots as $slot)
                            <div class="border-bottom py-3 d-flex justify-content-between align-items-center gap-3">
                                <span>{{ $slot->starts_at->format('D, M j, Y g:i A') }}</span>
                                @auth
                                    @if (auth()->user()->role === 'patient')
                                        <a class="btn btn-sm btn-primary" href="{{ route('appointment-slots.confirm', $slot) }}">Book</a>
                                    @endif
                                @else
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('login', ['redirect' => route('appointment-slots.confirm', $slot, false)]) }}">Log in to book</a>
                                @endauth
                            </div>
                        @empty
                            <p class="text-secondary mb-0">No future slots are currently available.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
