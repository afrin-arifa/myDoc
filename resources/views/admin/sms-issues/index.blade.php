@extends('layouts.app')
@section('title', 'SMS delivery issues | myDoc')
@section('content')
@php
    $statusLabels = [
        'unconfirmed' => ['label' => 'Unconfirmed', 'class' => 'text-bg-warning', 'help' => 'The provider may or may not have delivered it. Never resent automatically, to avoid a duplicate.'],
        'failed' => ['label' => 'Failed', 'class' => 'text-bg-secondary', 'help' => 'A temporary failure the provider confirmed (e.g. balance or rate limit). Retried automatically up to five times.'],
        'undelivered' => ['label' => 'Undelivered', 'class' => 'text-bg-danger', 'help' => 'The provider rejected it, usually a configuration problem such as the sender id. Not retried.'],
    ];
    $eventLabels = [
        'booked' => 'Booking',
        'confirmed' => 'Confirmation',
        'rejected' => 'Rejection',
        'cancelled' => 'Cancellation',
        'rescheduled' => 'Reschedule',
        'reminder' => 'Reminder',
    ];
    $timezone = config('app.timezone', 'UTC');
@endphp
<div class="container py-5">
    <h1 class="h2 mb-1">SMS delivery issues</h1>
    <p class="text-secondary mb-4">Appointment SMS that were not confirmed as delivered. Recipients, phone numbers and message text are never shown, and messages cannot be resent from here.</p>

    <div class="row g-3 mb-4">
        @foreach ($statusLabels as $value => $meta)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100 p-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="badge {{ $meta['class'] }}">{{ $meta['label'] }}</span>
                        <span class="h4 mb-0" data-sms-issue-count="{{ $value }}">{{ $counts[$value] }}</span>
                    </div>
                    <p class="small text-secondary mb-0">{{ $meta['help'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <form class="d-flex flex-wrap align-items-end gap-2 mb-4" method="GET" action="{{ route('admin.sms-issues.index') }}">
        <div>
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All issues</option>
                @foreach ($statusLabels as $value => $meta)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-outline-primary" type="submit">Filter</button>
        @if ($status)
            <a class="btn btn-link" href="{{ route('admin.sms-issues.index') }}">Clear</a>
        @endif
    </form>

    @if ($issues->isEmpty())
        <x-ui.empty-state :title="$status ? 'No '.strtolower($statusLabels[$status]['label']).' SMS' : 'No SMS delivery issues'"
            message="Every recorded appointment SMS was either delivered, deliberately skipped, or is still waiting to be sent." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">SMS delivery issues</caption>
                <thead>
                    <tr>
                        <th scope="col">Notification</th>
                        <th scope="col">Appointment</th>
                        <th scope="col">Event</th>
                        <th scope="col">Status</th>
                        <th scope="col">Provider code</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Provider request</th>
                        <th scope="col">Last updated</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($issues as $issue)
                        <tr>
                            <td>#{{ $issue->id }}<br><span class="small text-secondary">{{ $issue->attempts }} {{ Str::plural('attempt', $issue->attempts) }}</span></td>
                            <td><a href="{{ route('admin.appointments.show', $issue->appointment_id) }}">{{ App\Models\Appointment::referenceFor($issue->appointment_id) }}</a></td>
                            <td>{{ $eventLabels[$issue->type] ?? $issue->type }}</td>
                            <td><span class="badge {{ $statusLabels[$issue->status]['class'] ?? 'text-bg-light' }}">{{ $statusLabels[$issue->status]['label'] ?? $issue->status }}</span></td>
                            <td><code>{{ $issue->provider_reference ?? '—' }}</code></td>
                            <td class="small">{{ $issue->last_error ?? '—' }}</td>
                            <td class="small text-nowrap">{{ $issue->provider_requested_at?->copy()->setTimezone($timezone)->format('M j, Y g:i A T') ?? 'Not contacted' }}</td>
                            <td class="small text-nowrap">{{ $issue->updated_at->copy()->setTimezone($timezone)->format('M j, Y g:i A T') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $issues->links() }}
    @endif
</div>
@endsection
