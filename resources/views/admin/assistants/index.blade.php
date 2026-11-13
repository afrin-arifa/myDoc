@extends('layouts.app')
@section('title', 'Doctor assistants | myDoc')
@section('content')
<div class="container py-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">Doctor assistants</h1>
            <p class="text-secondary mb-0">Invite assistants, manage their account status and assign them to doctors.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.assistants.create') }}"><i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>Invite assistant</a>
    </div>

    <form class="row g-3 align-items-end mb-4" method="GET" action="{{ route('admin.assistants.index') }}">
        <div class="col-md-6">
            <label class="form-label" for="search">Search</label>
            <input class="form-control" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name or email" maxlength="100">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="status">Account status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button class="btn btn-outline-primary" type="submit">Filter</button>
        </div>
    </form>

    @if ($assistants->isEmpty())
        <x-ui.empty-state title="No assistants found"
            :message="array_filter($filters) ? 'Try a different search or status filter.' : 'Invite the first Doctor Assistant to get started.'"
            :action-label="array_filter($filters) ? null : 'Invite assistant'"
            :action-url="array_filter($filters) ? null : route('admin.assistants.create')" />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">Doctor assistants</caption>
                <thead>
                    <tr><th scope="col">Assistant</th><th scope="col">Status</th><th scope="col">Assigned doctors</th><th scope="col">Created</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($assistants as $assistant)
                        <tr>
                            <td><strong>{{ $assistant->name }}</strong><br><span class="small text-secondary">{{ $assistant->email }}</span></td>
                            <td><x-admin.account-status :status="$statuses[$assistant->id]" :labels="$statusLabels" /></td>
                            <td>{{ $assistant->assistant_assignments_count }}</td>
                            <td>{{ $assistant->created_at->format('M j, Y') }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.assistants.show', $assistant) }}">Manage<span class="visually-hidden"> {{ $assistant->name }}</span></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $assistants->links() }}
    @endif
</div>
@endsection
