@extends('layouts.app')
@section('title', 'Medical document | myDoc')
@section('content')
<div class="container py-5">
    <a class="back-link" href="{{ route('admin.medical-documents.index') }}"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Medical documents</a>
    <h1 class="h2 mt-3 mb-4">{{ $document->displayTitle() }}</h1>
    <div class="row g-4">
        <div class="col-lg-7">
            <section class="card border-0 shadow-sm p-4" aria-labelledby="details-heading">
                <h2 class="h5" id="details-heading">Details</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Patient</dt><dd class="col-sm-8">{{ $document->patient->name }} ({{ $document->patient->contactLabel() }})</dd>
                    <dt class="col-sm-4">Type</dt><dd class="col-sm-8">{{ $document->categoryLabel() }}</dd>
                    <dt class="col-sm-4">Original file name</dt><dd class="col-sm-8">{{ $document->original_name }}</dd>
                    <dt class="col-sm-4">File</dt><dd class="col-sm-8">{{ $document->fileTypeLabel() }} · {{ $document->humanSize() }}</dd>
                    <dt class="col-sm-4">Uploaded</dt><dd class="col-sm-8">{{ $document->created_at->format('M j, Y g:i A') }} by {{ $document->uploader?->name ?? 'a removed account' }}</dd>
                    <dt class="col-sm-4">Appointment</dt>
                    <dd class="col-sm-8">
                        @if ($document->appointment)
                            <a href="{{ route('admin.appointments.show', $document->appointment) }}">{{ $document->appointment->slot->starts_at->format('M j, Y g:i A') }}</a>
                            with {{ $document->appointment->slot->doctorProfile->user->name }}
                        @else
                            Not linked
                        @endif
                    </dd>
                    @if ($document->description)
                        <dt class="col-sm-4">Patient notes</dt><dd class="col-sm-8">{{ $document->description }}</dd>
                    @endif
                </dl>
                <div class="d-flex gap-2 mt-3">
                    <a class="btn btn-primary" href="{{ route('medical-documents.download', $document) }}">Download</a>
                    <form method="POST" action="{{ route('medical-documents.destroy', $document) }}" data-confirm="Delete this medical document permanently? This cannot be undone.">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger" type="submit">Delete document</button>
                    </form>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card border-0 shadow-sm p-4" aria-labelledby="activity-heading">
                <h2 class="h5" id="activity-heading">Activity</h2>
                @forelse ($document->events as $event)
                    <p class="small mb-2"><strong>{{ ucfirst($event->action) }}</strong> by {{ $event->actor?->name ?? 'a removed account' }}{{ $event->actor ? ' ('.$event->actor->role.')' : '' }}<br><span class="text-secondary">{{ $event->created_at->format('M j, Y g:i A') }}</span></p>
                @empty
                    <p class="small text-secondary mb-0">No activity recorded.</p>
                @endforelse
            </section>
        </div>
    </div>
</div>
@endsection
