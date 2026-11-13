@extends('layouts.app')

@section('title', 'Medical records | myDoc')

@section('content')
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-3">
                <x-doctors.profile-summary :profile="$profile" active="medical-records" :pending-count="$pendingCount" />
            </div>
            <div class="col-lg-9">
                <div class="doctor-page-header">
                    <div>
                        <p class="doctor-eyebrow">Patients</p>
                        <h1 class="doctor-page-title">Medical records</h1>
                        <p class="text-secondary mb-0">Documents shared by patients who have a booked, confirmed or completed appointment with you.</p>
                    </div>
                </div>

                <form class="row g-2 align-items-end mb-4" method="GET" action="{{ route('doctor.medical-records.index') }}" role="search">
                    <div class="col-md-6">
                        <label class="form-label" for="search">Search</label>
                        <input class="form-control" id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Patient name or document title">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="category">Document type</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">All types</option>
                            @foreach (App\Models\MedicalDocument::CATEGORIES as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['category'] ?? null) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-outline-primary" type="submit">Filter</button>
                    </div>
                </form>

                @if ($documents->isEmpty())
                    <x-ui.empty-state class="text-center py-5" :icon="array_filter($filters) ? 'fa-solid fa-filter-circle-xmark' : 'fa-regular fa-folder-open'"
                        title="No medical records found"
                        :message="array_filter($filters) ? 'No documents match these filters.' : 'Documents your patients upload will appear here.'"
                        :action-label="array_filter($filters) ? 'Show all records' : null" :action-url="route('doctor.medical-records.index')" />
                @else
                    <p class="small text-secondary mb-3">{{ $documents->total() }} {{ Str::plural('document', $documents->total()) }}</p>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-xxl-3 g-4">
                        @foreach ($documents as $document)
                            <div class="col">
                                <x-medical-documents.card :document="$document" viewer="doctor" />
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4">
                        {{ $documents->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <x-medical-documents.preview-modal />
@endsection
