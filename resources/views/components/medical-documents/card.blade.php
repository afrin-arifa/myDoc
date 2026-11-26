{{--
    A medical document card, shared by the patient and doctor Medical records pages.
    viewer="patient" (default): the owner's view, with the linked doctor and a Delete action.
    viewer="doctor": a treating doctor's (or their assistant's) view, showing whose document it is, linking the
    appointment to its page in the current workspace, and offering Download only (staff never delete patient documents).
    viewer="admin": the platform-wide view, with the patient's contact, the linked doctor and a Details link to the
    activity log.
    Images load through the authorized preview route; nothing links to storage. Download and preview use the current
    workspace's routes, so in an assistant workspace they carry the selected doctor.
--}}
@props(['document', 'viewer' => 'patient'])

@php
    $name = $document->title ?: $document->original_name;
    $downloadUrl = $workspace->url('medical-documents.download', $document);
    $previewUrl = $workspace->url('medical-documents.preview', $document);
    $isAdminView = $viewer === 'admin';
    $isDoctorView = $viewer === 'doctor' || $isAdminView;
@endphp

<article class="card document-card h-100">
    <div class="document-card__media">
        @if ($document->isPreviewableImage())
            <button class="document-card__preview" type="button" data-bs-toggle="modal" data-bs-target="#document-preview"
                data-preview-src="{{ $previewUrl }}" data-preview-title="{{ $name }}"
                data-preview-download="{{ $downloadUrl }}" aria-label="Preview {{ $name }}">
                <img class="document-card__image" src="{{ $previewUrl }}" alt="Preview of {{ $name }}"
                    loading="lazy" decoding="async" data-preview-thumbnail>
                <span class="document-card__placeholder" data-preview-fallback hidden>
                    <i class="fa-regular fa-image" aria-hidden="true"></i>
                    <span class="small">Preview unavailable</span>
                </span>
                <span class="document-card__zoom" aria-hidden="true"><i class="fa-solid fa-magnifying-glass-plus"></i></span>
            </button>
        @else
            <div class="document-card__placeholder document-card__placeholder--pdf">
                <i class="fa-regular fa-file-pdf" aria-hidden="true"></i>
                <span class="small">PDF document</span>
            </div>
        @endif
        <span class="document-card__type">{{ $document->fileTypeLabel() }}</span>
    </div>

    <div class="card-body d-flex flex-column">
        <span class="badge document-card__category align-self-start mb-2">{{ $document->categoryLabel() }}</span>
        <h3 class="h6 mb-1 text-truncate" title="{{ $name }}">{{ $name }}</h3>
        @if ($document->title)
            <p class="small text-secondary text-truncate mb-0" title="{{ $document->original_name }}">{{ $document->original_name }}</p>
        @endif

        <dl class="document-card__meta small my-3">
            @if ($isDoctorView)
                <div>
                    <dt><i class="fa-regular fa-user" aria-hidden="true"></i> Patient</dt>
                    <dd class="text-truncate" title="{{ $document->patient->name }}">{{ $document->patient->name }}</dd>
                </div>
            @endif
            @if ($isAdminView)
                <div>
                    <dt><i class="fa-regular fa-envelope" aria-hidden="true"></i> Contact</dt>
                    <dd class="text-truncate" title="{{ $document->patient->contactLabel() }}">{{ $document->patient->contactLabel() }}</dd>
                </div>
            @endif
            <div>
                <dt><i class="fa-regular fa-calendar" aria-hidden="true"></i> Uploaded</dt>
                <dd><time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('M j, Y') }}</time></dd>
            </div>
            @if ($document->uploaded_by !== null && $document->uploaded_by !== $document->patient_id && $document->relationLoaded('uploader') && $document->uploader)
                <div>
                    <dt><i class="fa-solid fa-user-pen" aria-hidden="true"></i> Added by</dt>
                    <dd class="text-truncate" title="{{ $document->uploader->name }}">{{ $document->uploader->name }}{{ $document->uploader->role === App\Models\User::ROLE_ASSISTANT ? ' (assistant)' : '' }}</dd>
                </div>
            @endif
            <div>
                <dt><i class="fa-regular fa-file" aria-hidden="true"></i> File</dt>
                <dd>{{ $document->fileTypeLabel() }} · {{ $document->humanSize() }}</dd>
            </div>
            <div>
                <dt><i class="fa-solid fa-stethoscope" aria-hidden="true"></i> Appointment</dt>
                <dd>
                    @if ($document->appointment && $isAdminView)
                        {{ $document->appointment->slot->starts_at->format('M j, Y') }} · {{ $document->appointment->slot->doctorProfile->user->name }}
                    @elseif ($document->appointment && $isDoctorView)
                        <a href="{{ $workspace->url('doctor.appointments.show', $document->appointment) }}">{{ $document->appointment->slot->starts_at->format('M j, Y g:i A') }}</a>
                    @elseif ($document->appointment)
                        {{ $document->appointment->slot->starts_at->format('M j, Y') }} · {{ $document->appointment->slot->doctorProfile->user->name }}
                    @else
                        <span class="text-secondary">Not linked</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="d-flex gap-2 mt-auto">
            @if ($isAdminView)
                <a class="btn btn-sm btn-outline-secondary flex-fill" href="{{ route('admin.medical-documents.show', $document) }}">
                    <i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i>Details<span class="visually-hidden"> of {{ $name }}</span>
                </a>
            @endif
            <a class="btn btn-sm btn-outline-primary flex-fill" href="{{ $downloadUrl }}">
                <i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download<span class="visually-hidden"> {{ $name }}</span>
            </a>
            @unless ($isDoctorView)
                <form class="flex-fill d-flex" method="POST" action="{{ route('medical-documents.destroy', $document) }}"
                    data-confirm="Delete this document permanently? Your doctors will no longer be able to see it.">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger flex-fill" type="submit">
                        <i class="fa-regular fa-trash-can me-1" aria-hidden="true"></i>Delete<span class="visually-hidden"> {{ $name }}</span>
                    </button>
                </form>
            @endunless
        </div>
    </div>
</article>
