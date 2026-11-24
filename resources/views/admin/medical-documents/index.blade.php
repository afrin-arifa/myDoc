@extends('layouts.app')
@section('title', 'Medical documents | myDoc')
@section('content')
<div class="container py-5">
    <h1 class="h2 mb-1">Medical documents</h1>
    <p class="text-secondary mb-4">All patient documents on the platform. Downloads and deletions are recorded in each document's activity log.</p>

    <form class="filter-bar row g-3 align-items-end mb-4" method="GET" action="{{ route('admin.medical-documents.index') }}" role="search">
        <div class="col-md-4">
            <label class="form-label" for="search">Search</label>
            <input class="form-control" id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Patient, doctor, title or filename">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="category">Document type</label>
            <select class="form-select" id="category" name="category">
                <option value="">All types</option>
                @foreach (App\Models\MedicalDocument::CATEGORIES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['category'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="from">Uploaded from</label>
            <input class="form-control" id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="to">Uploaded to</label>
            <input class="form-control" id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
        </div>
        <div class="col-md-1 d-grid">
            <button class="btn btn-outline-primary" type="submit">Filter</button>
        </div>
    </form>

    @if ($documents->isEmpty())
        <x-ui.empty-state title="No documents found" :message="array_filter($filters) ? 'No documents match these filters.' : 'Patients have not uploaded any documents yet.'" />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">Medical documents</caption>
                <thead>
                    <tr><th scope="col">Patient</th><th scope="col">Document</th><th scope="col">Type</th><th scope="col">Linked doctor</th><th scope="col">Uploaded</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($documents as $document)
                        <tr>
                            <td><strong>{{ $document->patient->name }}</strong><br><span class="small text-secondary">{{ $document->patient->email }}</span></td>
                            <td>{{ $document->displayTitle() }}<br><span class="small text-secondary">{{ $document->original_name }} · {{ $document->fileTypeLabel() }} · {{ $document->humanSize() }}</span></td>
                            <td><span class="badge text-bg-light">{{ $document->categoryLabel() }}</span></td>
                            <td>{{ $document->appointment?->slot?->doctorProfile?->user?->name ?? '—' }}</td>
                            <td>{{ $document->created_at->format('M j, Y') }}</td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.medical-documents.show', $document) }}">Details</a>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('medical-documents.download', $document) }}">Download</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    @endif
</div>
@endsection
