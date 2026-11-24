@extends('layouts.app')
@section('title', 'Waiting lists | myDoc')
@section('content')
<div class="container py-5">
    <h1 class="h2 mb-1">Waiting lists</h1>
    <p class="text-secondary mb-4">Patients waiting for a time with each doctor. Offers are made automatically or from the doctor's workspace; this page is read only.</p>

    <form class="filter-bar d-flex flex-wrap align-items-end gap-2 mb-4" method="GET" action="{{ route('admin.waitlist.index') }}">
        <div>
            <label class="form-label" for="doctor">Doctor</label>
            <select class="form-select" id="doctor" name="doctor">
                <option value="">All doctors</option>
                @foreach ($doctors as $doctor)
                    <option value="{{ $doctor->id }}" @selected((int) ($filters['doctor'] ?? 0) === $doctor->id)>{{ $doctor->user->name }}{{ $doctor->waitlist_enabled ? '' : ' (off)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                @foreach (App\Models\WaitlistEntry::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-outline-primary" type="submit">Filter</button>
        @if ($filters !== [])
            <a class="btn btn-link" href="{{ route('admin.waitlist.index') }}">Clear</a>
        @endif
    </form>

    @if ($entries->isEmpty())
        <x-ui.empty-state title="No waiting list entries" message="Nobody matches these filters." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">Waiting list entries, newest first</caption>
                <thead>
                    <tr><th scope="col">Patient</th><th scope="col">Doctor</th><th scope="col">Wants</th><th scope="col">Status</th><th scope="col">Current offer</th><th scope="col">Joined</th></tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr data-waitlist-entry="{{ $entry->id }}">
                            <td>{{ $entry->patient->name }}<br><span class="small text-secondary">{{ $entry->patient->email }}</span></td>
                            <td>{{ $entry->doctorProfile->user->name }}</td>
                            <td class="small">{{ $entry->date_from->format('M j') }} – {{ $entry->date_to->format('M j, Y') }}<br>{{ App\Models\WaitlistEntry::timeOfDayLabel($entry->time_of_day) }} · {{ $entry->location?->name ?? 'Any location' }}</td>
                            <td><span class="badge text-bg-light">{{ $entry->statusLabel() }}</span>@if ($entry->expired_offer_count)<br><span class="small text-secondary">{{ $entry->expired_offer_count }} unanswered</span>@endif</td>
                            <td class="small">{{ $entry->pendingOffer ? $entry->pendingOffer->slot->starts_at->format('M j, g:i A').' until '.$entry->pendingOffer->expires_at->format('g:i A') : '—' }}</td>
                            <td class="small text-nowrap">{{ $entry->created_at->format('M j, Y g:i A') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $entries->links() }}
    @endif
</div>
@endsection
