@props(['appointments' => null, 'compact' => false, 'action' => null, 'patients' => null, 'defaultCategory' => null])
{{--
    Medical document upload. Patients upload for themselves (optionally linking one of their appointments); staff pass
    $patients and $action to upload for a patient in the doctor's care, with Prescription chosen by default.
--}}

<form method="POST" action="{{ $action ?? route('patient.documents.store') }}" enctype="multipart/form-data" data-disable-on-submit>
    @csrf
    <div class="row g-3">
        @if ($patients !== null)
            <div class="col-12">
                <label class="form-label" for="patient">Patient</label>
                <select class="form-select @error('patient') is-invalid @enderror" id="patient" name="patient" required>
                    <option value="">Choose a patient</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->getRouteKey() }}" @selected(old('patient') === $patient->getRouteKey())>{{ $patient->name }}{{ $patient->email || $patient->phone ? ' · '.($patient->email ?? $patient->phone) : '' }}</option>
                    @endforeach
                </select>
                @error('patient')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12">
            <fieldset class="document-type-picker" @error('category') aria-describedby="category_error" @enderror>
                <legend class="form-label">Document type</legend>
                <div class="document-type-picker__grid">
                    @foreach (App\Models\MedicalDocument::CATEGORIES as $value => $label)
                        <label class="document-type-option">
                            <input class="document-type-option__input" type="radio" name="category" value="{{ $value }}" required @checked(old('category', $defaultCategory) === $value)>
                            <span class="document-type-option__card">
                                <i class="fa-solid fa-circle-check document-type-option__check" aria-hidden="true"></i>
                                <i class="{{ App\Models\MedicalDocument::CATEGORY_ICONS[$value] }}" aria-hidden="true"></i>
                                <span>{{ $label }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('category')<div class="text-danger small mt-1" id="category_error">{{ $message }}</div>@enderror
            </fieldset>
        </div>
        <div class="{{ $compact || $appointments === null ? 'col-12' : 'col-md-6' }}">
            <label class="form-label" for="title">Title (optional)</label>
            <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title') }}" maxlength="150" placeholder="e.g. Blood test March">
            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($appointments !== null)
            <div class="col-md-6">
                <label class="form-label" for="appointment_id">Related appointment (optional)</label>
                <select class="form-select @error('appointment_id') is-invalid @enderror" id="appointment_id" name="appointment_id">
                    <option value="">Not linked to an appointment</option>
                    @foreach ($appointments as $appointment)
                        <option value="{{ $appointment->id }}" @selected((string) old('appointment_id') === (string) $appointment->id)>
                            {{ $appointment->slot->starts_at->format('M j, Y g:i A') }} · {{ $appointment->slot->doctorProfile->user->name }} ({{ ucfirst($appointment->status) }})
                        </option>
                    @endforeach
                </select>
                @error('appointment_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Notes (optional)</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="2" maxlength="2000">{{ old('description') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12" data-file-field>
            <label class="form-label" for="document">File</label>
            <div class="document-dropzone @error('document') is-invalid @enderror" data-dropzone>
                <input class="document-dropzone__input @error('document') is-invalid @enderror" id="document" name="document" type="file" required
                    accept="application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp" aria-describedby="document_help document_error"
                    data-max-file-bytes="{{ App\Models\MedicalDocument::maxUploadBytes() }}" data-max-file-label="{{ App\Models\MedicalDocument::maxUploadLabel() }}">
                <div class="document-dropzone__empty" data-dropzone-empty>
                    <span class="document-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>
                    <span class="document-dropzone__title">Drop your file here or <span class="document-dropzone__browse">browse</span></span>
                    <span class="document-dropzone__hint" id="document_help">PDF, JPG, PNG or WebP · up to {{ App\Models\MedicalDocument::maxUploadLabel() }}</span>
                </div>
                <div class="document-dropzone__chosen" data-dropzone-chosen hidden>
                    <span class="document-dropzone__file-icon"><i class="fa-regular fa-file-lines" aria-hidden="true" data-dropzone-file-icon></i></span>
                    <span class="document-dropzone__file">
                        <span class="document-dropzone__file-name" data-dropzone-file-name></span>
                        <span class="document-dropzone__file-size" data-dropzone-file-size></span>
                    </span>
                    <span class="document-dropzone__change">Change</span>
                </div>
            </div>
            <div class="text-danger small mt-1" id="document_error" data-file-size-error>@error('document'){{ $message }}@enderror</div>
        </div>
    </div>
    <div class="document-upload__footer">
        <p class="document-upload__privacy mb-0">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            {{ $patients !== null ? 'The patient, their doctors and administrators can see it. It is recorded under your account.' : 'Only you, doctors you have appointments with, and administrators can see it.' }}
        </p>
        <button class="btn btn-primary" type="submit" data-loading-text="Uploading…">
            <i class="fa-solid fa-arrow-up-from-bracket me-1" aria-hidden="true"></i>Upload document
        </button>
    </div>
</form>
