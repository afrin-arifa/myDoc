@extends('layouts.app')

@section('title', 'Waiting list | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Waiting list</p>
        <h1 class="h2 mb-2">Your waiting list requests</h1>
        <p class="text-secondary mb-4">When a time you asked for opens, we hold it for you for a short while and let you know. You can join a doctor's waiting list from their profile.</p>

        @if ($entries->isEmpty())
            <x-ui.empty-state title="You are not on any waiting list"
                message="If a doctor has no suitable times, choose “Join the waiting list” on their profile."
                action-label="Find a doctor" :action-url="route('doctors.index')" />
        @else
            <div class="d-grid gap-3">
                @foreach ($entries as $entry)
                    @php($offer = $entry->pendingOffer)
                    <article class="card border-0 shadow-sm" data-waitlist-entry="{{ $entry->id }}">
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <h2 class="h5 mb-0">{{ $entry->doctorProfile->user->name }}</h2>
                                <span class="badge {{ $entry->status === 'offered' ? 'text-bg-success' : 'text-bg-light' }}">{{ $entry->statusLabel() }}</span>
                            </div>
                            <p class="text-secondary mb-1">{{ $entry->date_from->format('D, M j') }} – {{ $entry->date_to->format('D, M j, Y') }} · {{ App\Models\WaitlistEntry::timeOfDayLabel($entry->time_of_day) }}</p>
                            <p class="small text-secondary mb-3">{{ $entry->location?->name ?? 'Any location' }}@if ($entry->follow_up_of_id) · Follow-up to {{ App\Models\Appointment::referenceFor($entry->follow_up_of_id) }}@endif</p>

                            @if ($offer && $offer->isOpen())
                                <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
                                    <span>A time is held for you: {{ $offer->slot->starts_at->format('D, M j, g:i A') }}, until {{ $offer->expires_at->format('g:i A') }}.</span>
                                    <a class="btn btn-sm btn-success" href="{{ route('patient.waitlist.offers.show', $offer) }}">View offer</a>
                                </div>
                            @elseif ($entry->status === 'paused')
                                <p class="small mb-3">Paused because earlier offers were not answered in time.</p>
                            @elseif ($entry->status === 'booked' && App\Models\Appointment::urlKey($entry->booked_appointment_id))
                                <p class="small mb-3"><a href="{{ route('patient.appointments.show', App\Models\Appointment::urlKey($entry->booked_appointment_id)) }}">View the booked appointment</a></p>
                            @endif

                            <div class="d-flex flex-wrap gap-2">
                                @if ($entry->status === 'paused')
                                    <form method="POST" action="{{ route('patient.waitlist.resume', $entry) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-primary" type="submit">Resume</button></form>
                                @endif
                                @if (in_array($entry->status, App\Models\WaitlistEntry::OPEN_STATUSES, true))
                                    <form method="POST" action="{{ route('patient.waitlist.destroy', $entry) }}" data-confirm="Leave {{ $entry->doctorProfile->user->name }}'s waiting list?{{ $offer ? ' The time held for you will be offered to someone else.' : '' }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Leave waiting list</button></form>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
