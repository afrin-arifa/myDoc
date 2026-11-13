@props(['appointments' => null, 'compact' => false])

<form method="POST" action="{{ route('patient.documents.store') }}" enctype="multipart/form-data" data-disable-on-submit>
    @csrf
    <div class="row g-3">
        <div class="{{ $compact ? 'col-md-6' : 'col-md-4' }}">
            <label class="form-label" for="category">Document type</label>
            <select class="form-select @error('category') is-invalid @enderror" id="category" name="category" required>
                <option value="">Choose a type</option>
                @foreach (App\Models\MedicalDocument::CATEGORIES as $value => $label)
                    <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="{{ $compact ? 'col-md-6' : 'col-md-4' }}">
            <label class="form-label" for="title">Title (optional)</label>
            <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title') }}" maxlength="150" placeholder="e.g. Blood test March">
            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($appointments !== null)
            <div class="col-md-4">
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
        <div class="col-12">
            <label class="form-label" for="document">File</label>
            <input class="form-control @error('document') is-invalid @enderror" id="document" name="document" type="file" required
                accept="application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp" aria-describedby="document_help document_error"
                data-max-file-bytes="{{ App\Models\MedicalDocument::maxUploadBytes() }}" data-max-file-label="{{ App\Models\MedicalDocument::maxUploadLabel() }}">
            <div class="form-text" id="document_help">PDF, JPG, PNG or WebP, up to {{ App\Models\MedicalDocument::maxUploadLabel() }}. Only you, doctors you have appointments with, and administrators can see it.</div>
            <div class="invalid-feedback" id="document_error" data-file-size-error>@error('document'){{ $message }}@enderror</div>
        </div>
    </div>
    <button class="btn btn-primary mt-3" type="submit" data-loading-text="Uploading…">Upload document</button>
</form>
