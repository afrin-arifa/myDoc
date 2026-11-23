@extends('layouts.app')
@section('title', 'Notification delivery issues | myDoc')
@section('content')
@php
    $statusLabels = [
        'unconfirmed' => ['label' => 'Unconfirmed', 'class' => 'text-bg-warning', 'help' => 'SMS only. The provider may or may not have delivered it. Never resent, to avoid a duplicate.'],
        'failed' => ['label' => 'Failed', 'class' => 'text-bg-secondary', 'help' => 'A temporary failure (email sending error, or an SMS the provider confirmed it did not accept). Retried automatically up to five times.'],
        'undelivered' => ['label' => 'Undelivered', 'class' => 'text-bg-danger', 'help' => 'SMS only. The provider rejected it, usually a configuration problem such as the sender id. Not retried.'],
        'exhausted' => ['label' => 'Retries exhausted', 'class' => 'text-bg-dark', 'help' => 'Automatic retries ran out and the message was not delivered. Fix the cause, then retry it here.'],
    ];
    $channelLabels = ['email' => 'Email', 'sms' => 'SMS'];
    $eventLabels = [
        'booked' => 'Booking',
        'confirmed' => 'Confirmation',
        'rejected' => 'Rejection',
        'cancelled' => 'Cancellation',
        'staff_cancelled' => 'Cancellation by clinic',
        'rescheduled' => 'Reschedule',
        'reminder' => 'Reminder',
        'expired' => 'Expiry',
        'waitlist_offer' => 'Waiting list offer',
    ];
    $timezone = config('app.timezone', 'UTC');
    $filtered = $status || $channel;
@endphp
<div class="container py-5">
    <h1 class="h2 mb-1">Notification delivery issues</h1>
    <p class="text-secondary mb-4">Appointment emails and SMS that were not delivered as expected. Recipients, email addresses, phone numbers and message text are never shown. Only messages that were certainly not delivered can be retried.</p>

    <div class="row g-3 mb-4">
        @foreach ($statusLabels as $value => $meta)
            <div class="col-md-6 col-xl-3">
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
            <label class="form-label" for="channel">Channel</label>
            <select class="form-select" id="channel" name="channel">
                <option value="">Email and SMS</option>
                @foreach ($channelLabels as $value => $label)
                    <option value="{{ $value }}" @selected($channel === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
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
        @if ($filtered)
            <a class="btn btn-link" href="{{ route('admin.sms-issues.index') }}">Clear</a>
        @endif
    </form>

    @if ($issues->isEmpty())
        <x-ui.empty-state :title="$filtered ? 'No matching delivery issues' : 'No notification delivery issues'"
            message="Every recorded appointment email and SMS was either delivered, deliberately skipped, or is still waiting to be sent." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">Notification delivery issues</caption>
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
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($issues as $issue)
                        <tr>
                            <td>#{{ $issue->id }} <span class="badge text-bg-light">{{ $channelLabels[$issue->channel] ?? $issue->channel }}</span><br><span class="small text-secondary">{{ $issue->attempts }} {{ Str::plural('attempt', $issue->attempts) }}</span></td>
                            <td>
                                @if ($issue->appointment_id)
                                    <a href="{{ route('admin.appointments.show', $issue->appointment_id) }}">{{ App\Models\Appointment::referenceFor($issue->appointment_id) }}</a>
                                @else
                                    <a href="{{ route('admin.waitlist.index', ['offer' => $issue->waitlist_offer_id]) }}">Waiting list offer #{{ $issue->waitlist_offer_id }}</a>
                                @endif
                            </td>
                            <td>{{ $eventLabels[$issue->type] ?? $issue->type }}</td>
                            <td><span class="badge {{ $statusLabels[$issue->status]['class'] ?? 'text-bg-light' }}">{{ $statusLabels[$issue->status]['label'] ?? $issue->status }}</span></td>
                            <td><code>{{ $issue->provider_reference ?? '—' }}</code></td>
                            <td class="small">{{ $issue->last_error ?? '—' }}</td>
                            <td class="small text-nowrap">
                                @if ($issue->channel === 'sms')
                                    {{ $issue->provider_requested_at?->copy()->setTimezone($timezone)->format('M j, Y g:i A T') ?? 'Not contacted' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small text-nowrap">{{ $issue->updated_at->copy()->setTimezone($timezone)->format('M j, Y g:i A T') }}</td>
                            <td>
                                @can('retry', $issue)
                                    <form method="POST" action="{{ route('admin.sms-issues.retry', $issue) }}"
                                        data-confirm="Retry notification #{{ $issue->id }}? It is skipped instead of sent if the appointment has changed since.">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Retry</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $issues->links() }}
    @endif
</div>
@endsection
