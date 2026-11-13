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
                    <x-ui.empty-state title="No medical records found"
                        :message="array_filter($filters) ? 'No documents match these filters.' : 'Documents your patients upload will appear here.'" />
                @else
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <caption class="visually-hidden">Patient medical documents</caption>
                            <thead>
                                <tr><th scope="col">Patient</th><th scope="col">Document</th><th scope="col">Type</th><th scope="col">Uploaded</th><th scope="col">Your appointment</th><th scope="col"><span class="visually-hidden">Download</span></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($documents as $document)
                                    <tr>
                                        <td><strong>{{ $document->patient->name }}</strong><br><span class="small text-secondary">{{ $document->patient->email }}</span></td>
                                        <td>{{ $document->displayTitle() }}<br><span class="small text-secondary">{{ $document->fileTypeLabel() }} · {{ $document->humanSize() }}</span></td>
                                        <td><span class="badge text-bg-light">{{ $document->categoryLabel() }}</span></td>
                                        <td>{{ $document->created_at->format('M j, Y') }}</td>
                                        <td>
                                            @if ($document->appointment)
                                                <a href="{{ route('doctor.appointments.show', $document->appointment) }}">{{ $document->appointment->slot->starts_at->format('M j, Y g:i A') }}</a>
                                            @else
                                                <span class="text-secondary">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('medical-documents.download', $document) }}">Download</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $documents->links() }}
                @endif
            </div>
        </div>
    </div>
@endsection
