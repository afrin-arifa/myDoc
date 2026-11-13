@extends('layouts.app')

@section('title', 'Medical records | myDoc')

@section('content')
    <div class="container py-5">
        <p class="hero__eyebrow mb-2">Patient space</p>
        <h1 class="h2 mb-2">Medical records</h1>
        <p class="text-secondary mb-4">Keep prescriptions, test results and reports in one private place for your doctors.</p>

        <section class="card border-0 shadow-sm p-4 mb-5" id="upload" aria-labelledby="upload-heading">
            <h2 class="h5 mb-3" id="upload-heading">Upload a document</h2>
            <x-medical-documents.upload-form :appointments="$appointments" />
        </section>

        <section aria-labelledby="documents-heading">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
                <div>
                    <h2 class="h4 mb-1" id="documents-heading">Your documents</h2>
                    @if ($documents->total() > 0)
                        <p class="small text-secondary mb-0">
                            {{ $documents->total() }} {{ Str::plural('document', $documents->total()) }}{{ $category ? ' · '.App\Models\MedicalDocument::CATEGORIES[$category] : '' }}
                        </p>
                    @endif
                </div>
                <form class="d-flex gap-2" method="GET" action="{{ route('patient.documents.index') }}">
                    <label class="visually-hidden" for="filter_category">Filter by type</label>
                    <select class="form-select" id="filter_category" name="category">
                        <option value="">All types</option>
                        @foreach (App\Models\MedicalDocument::CATEGORIES as $value => $label)
                            <option value="{{ $value }}" @selected($category === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Filter</button>
                    @if ($category)
                        <a class="btn btn-link text-nowrap" href="{{ route('patient.documents.index') }}">Clear</a>
                    @endif
                </form>
            </div>

            @if ($documents->isEmpty())
                @if ($category)
                    <x-ui.empty-state class="text-center py-5" icon="fa-solid fa-filter-circle-xmark" title="No documents of this type"
                        message="None of your documents are filed under this type yet."
                        action-label="Show all documents" :action-url="route('patient.documents.index')" />
                @else
                    <x-ui.empty-state class="text-center py-5" icon="fa-regular fa-folder-open" title="No documents yet"
                        message="Upload a prescription, lab result or report to keep it ready for your appointments."
                        action-label="Upload your first document" action-url="#upload" />
                @endif
            @else
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    @foreach ($documents as $document)
                        <div class="col">
                            <x-medical-documents.card :document="$document" />
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    {{ $documents->links() }}
                </div>
            @endif
        </section>
    </div>

    <x-medical-documents.preview-modal />
@endsection
