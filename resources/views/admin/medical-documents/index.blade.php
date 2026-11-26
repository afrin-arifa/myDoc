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
        <p class="small text-secondary mb-3">{{ $documents->total() }} {{ Str::plural('document', $documents->total()) }}</p>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 row-cols-xxl-4 g-4">
            @foreach ($documents as $document)
                <div class="col">
                    <x-medical-documents.card :document="$document" viewer="admin" />
                </div>
            @endforeach
        </div>
        <div class="mt-4">
            {{ $documents->links() }}
        </div>
    @endif
</div>

<x-medical-documents.preview-modal />
@endsection
