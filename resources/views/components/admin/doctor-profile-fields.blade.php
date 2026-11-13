@props(['specialties', 'doctor' => null])

<div class="col-md-6">
    <label class="form-label" for="professional_title">Professional title</label>
    <input class="form-control" id="professional_title" name="professional_title" value="{{ old('professional_title', $doctor?->professional_title) }}" required maxlength="255">
</div>
<div class="col-md-6">
    <label class="form-label" for="license_number">License number</label>
    <input class="form-control" id="license_number" name="license_number" value="{{ old('license_number', $doctor?->license_number) }}" required maxlength="255">
</div>
<div class="col-md-6">
    <label class="form-label" for="consultation_fee">Consultation fee</label>
    <input class="form-control" id="consultation_fee" name="consultation_fee" type="number" step="0.01" min="0" value="{{ old('consultation_fee', $doctor?->consultation_fee) }}" required>
</div>
<div class="col-md-6">
    <label class="form-label" for="years_of_experience">Years of experience</label>
    <input class="form-control" id="years_of_experience" name="years_of_experience" type="number" min="0" max="100" value="{{ old('years_of_experience', $doctor?->years_of_experience) }}">
</div>
<div class="col-12">
    <label class="form-label" for="qualifications">Qualifications</label>
    <input class="form-control" id="qualifications" name="qualifications" value="{{ old('qualifications', $doctor?->qualifications) }}" maxlength="500" placeholder="e.g. MBBS, FCPS (Medicine)">
</div>
<div class="col-12">
    <label class="form-label" for="bio">Biography</label>
    <textarea class="form-control" id="bio" name="bio" rows="4" maxlength="5000">{{ old('bio', $doctor?->bio) }}</textarea>
</div>
<div class="col-12">
    <span class="form-label d-block">Specialties</span>
    @forelse ($specialties as $specialty)
        <label class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="specialty_ids[]" value="{{ $specialty->id }}"
                @checked(in_array($specialty->id, array_map('intval', old('specialty_ids', $doctor?->specialties->pluck('id')->all() ?? [])), true))>
            <span class="form-check-label">{{ $specialty->name }}</span>
        </label>
    @empty
        <p class="small text-secondary mb-0">No active specialties yet. <a href="{{ route('admin.specialties.index') }}">Add specialties</a>.</p>
    @endforelse
</div>
<div class="col-12">
    <div class="form-check">
        <input class="form-check-input" id="is_accepting_patients" name="is_accepting_patients" value="1" type="checkbox" @checked(old('is_accepting_patients', $doctor?->is_accepting_patients ?? true))>
        <label class="form-check-label" for="is_accepting_patients">Accepting patients (the doctor is only listed publicly once the account is active)</label>
    </div>
</div>
