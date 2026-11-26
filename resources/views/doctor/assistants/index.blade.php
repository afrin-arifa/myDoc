@extends('layouts.app')

@section('title', 'Assistants | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container-fluid doctor-workspace__container">
        <header class="doctor-page-header">
            <div>
                <p class="doctor-eyebrow">Doctor workspace</p>
                <h1 class="doctor-page-title">Your assistants</h1>
                <p class="text-secondary mb-0">People who manage appointments, schedule and records for you. A myDoc administrator adds or removes them.</p>
            </div>
        </header>

        @if ($assignments->isEmpty())
            <x-ui.empty-state class="mb-4" title="No assistants yet" message="Ask a myDoc administrator to assign an assistant to you." />
        @else
            <div class="row row-cols-1 row-cols-md-2 row-cols-xxl-3 g-4 mb-4">
                @foreach ($assignments as $assignment)
                    <div class="col">
                        <article class="doctor-panel assistant-card h-auto d-flex align-items-center gap-3 position-relative">
                            <x-ui.avatar :name="$assignment->assistant->name" :src="$assignment->assistant->photoUrl()" size="lg" />
                            <div class="min-w-0">
                                <h2 class="h6 mb-1"><a class="stretched-link link-body-emphasis text-decoration-none" href="{{ route('doctor.assistants.show', $assignment->assistant) }}">{{ $assignment->assistant->name }}</a></h2>
                                <p class="small text-secondary mb-1 text-break">{{ $assignment->assistant->email }}{{ $assignment->assistant->phone ? ' · '.$assignment->assistant->phone : '' }}</p>
                                <p class="small text-secondary mb-0">Since {{ $assignment->created_at->format('M j, Y') }}</p>
                            </div>
                            <span class="assistant-card__open ms-auto" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif

        <section class="doctor-panel" aria-labelledby="activity-heading">
            <div class="doctor-panel__header">
                <div class="doctor-profile-section mb-0"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="activity-heading">Assistant activity</h2><p>Everything assistants changed in your workspace. Records cannot be edited.</p></div></div>
                @if ($actors->isNotEmpty())
                    <form class="d-flex gap-2" method="GET" action="{{ route('doctor.assistants.index') }}">
                        <label class="visually-hidden" for="assistant">Assistant</label>
                        <select class="form-select" id="assistant" name="assistant"><option value="">All assistants</option>@foreach ($actors as $actor)<option value="{{ $actor->getRouteKey() }}" @selected($assistantId === $actor->id)>{{ $actor->name }}</option>@endforeach</select>
                        <button class="btn btn-outline-primary" type="submit">Filter</button>
                    </form>
                @endif
            </div>
            @if ($events->isEmpty())
                <p class="text-secondary mb-0">No assistant activity yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle">
                        <caption class="visually-hidden">Assistant activity, newest first</caption>
                        <thead><tr><th scope="col">When</th><th scope="col">Assistant</th><th scope="col">Action</th><th scope="col">Details</th></tr></thead>
                        <tbody>
                            @foreach ($events as $event)
                                <tr>
                                    <td class="text-nowrap">{{ $event->created_at->format('M j, Y g:i A') }}</td>
                                    <td>{{ $event->actor?->name ?? 'Former account' }}</td>
                                    <td>{{ $event->actionLabel() }}</td>
                                    <td><x-assistant.event-details :event="$event" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $events->links() }}</div>
            @endif
        </section>
    </div></div>
@endsection
