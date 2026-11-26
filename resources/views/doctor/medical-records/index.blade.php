@extends('layouts.app')

@section('title', 'Medical records | myDoc')

@section('content')
    @php($forAssistant = $workspace->inAssistantWorkspace())
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-12">
                <div class="doctor-page-header d-flex flex-wrap justify-content-between align-items-end gap-3">
                    <div>
                        <p class="doctor-eyebrow">Patients</p>
                        <h1 class="doctor-page-title">Medical records</h1>
                        <p class="text-secondary mb-0">Documents shared by patients who have a booked, confirmed or completed appointment with {{ $forAssistant ? $profile->user->name : 'you' }}.</p>
                    </div>
                    @if ($canUpload)
                        <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#upload-document" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="upload-document">
                            <i class="fa-solid fa-arrow-up-from-bracket me-1" aria-hidden="true"></i>Upload for a patient
                        </button>
                    @endif
                </div>

                @if ($canUpload)
                    <section @class(['collapse', 'show' => $errors->any()]) id="upload-document" aria-labelledby="upload-document-heading">
                        <div class="card border-0 shadow-sm p-4 mb-4">
                            <h2 class="h5 mb-1" id="upload-document-heading">Upload a document for a patient</h2>
                            <p class="small text-secondary mb-3">For example a prescription from {{ $profile->user->name }}. Only patients with an appointment with {{ $profile->user->name }} can be chosen.</p>
                            <x-medical-documents.upload-form :action="$workspace->url('doctor.medical-records.store')" :patient-search-url="$workspace->url('doctor.medical-records.patients')" default-category="prescription" />
                        </div>
                    </section>
                @endif

                <form class="filter-bar row g-2 align-items-end mb-4" method="GET" action="{{ $workspace->url('doctor.medical-records.index') }}" role="search">
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
                        :message="array_filter($filters) ? 'No documents match these filters.' : 'Documents '.($forAssistant ? $profile->user->name."'s" : 'your').' patients upload will appear here.'"
                        :action-label="array_filter($filters) ? 'Show all records' : null" :action-url="$workspace->url('doctor.medical-records.index')" />
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
