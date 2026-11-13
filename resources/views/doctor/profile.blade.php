@extends('layouts.app')

@section('title', 'Professional profile | myDoc')

@section('content')
    <div class="doctor-workspace"><div class="container-fluid doctor-workspace__container"><div class="row g-4">
        <div class="col-xl-3 col-xxl-2"><x-doctors.profile-summary :profile="$profile" active="profile" :pending-count="$pendingCount" /></div>
        <div class="col-xl-9 col-xxl-10">
            <div class="doctor-page-header"><div><p class="doctor-eyebrow">Professional profile</p><h1 class="doctor-page-title">Your clinical profile</h1><p class="text-secondary mb-0">Keep the information patients see when choosing their care.</p></div></div>
            @php($missingSections = $profile->missingProfileSections())
            @if ($missingSections !== [])
                <x-ui.alert type="info" class="mb-4">
                    <p class="fw-semibold mb-1">Complete your profile</p>
                    <p class="mb-0">Patients see this information when choosing a doctor. Still missing: {{ implode(', ', $missingSections) }}.</p>
                </x-ui.alert>
            @endif
            <section class="doctor-panel">
                <form method="POST" action="{{ route('doctor.profile.update') }}" enctype="multipart/form-data" data-disable-on-submit>
                    @csrf @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="professional_title">Professional title</label><input class="form-control" id="professional_title" name="professional_title" value="{{ old('professional_title', $profile->professional_title) }}" required></div>
                        <div class="col-md-4"><label class="form-label" for="license_number">License number</label><input class="form-control" id="license_number" name="license_number" value="{{ old('license_number', $profile->license_number) }}" required></div>
                        <div class="col-12"><label class="form-label" for="qualifications">Qualifications</label><input class="form-control" id="qualifications" name="qualifications" value="{{ old('qualifications', $profile->qualifications) }}" maxlength="500" placeholder="e.g. MBBS, FCPS (Medicine)"></div>
                        <div class="col-12"><label class="form-label" for="bio">Biography</label><textarea class="form-control" id="bio" name="bio" rows="5">{{ old('bio', $profile->bio) }}</textarea></div>
                        <div class="col-md-4"><label class="form-label" for="consultation_fee">Consultation fee</label><input class="form-control" id="consultation_fee" name="consultation_fee" type="number" step="0.01" value="{{ old('consultation_fee', $profile->consultation_fee) }}" required></div>
                        <div class="col-md-4"><label class="form-label" for="years_of_experience">Years of experience</label><input class="form-control" id="years_of_experience" name="years_of_experience" type="number" min="0" value="{{ old('years_of_experience', $profile->years_of_experience) }}"></div>
                        <div class="col-12"><span class="form-label d-block">Specialties</span><div class="row g-2">
                            @foreach ($specialties as $specialty)
                                <div class="col-sm-4"><label class="form-check"><input class="form-check-input" name="specialty_ids[]" type="checkbox" value="{{ $specialty->id }}" @checked(in_array($specialty->id, old('specialty_ids', $profile->specialties->pluck('id')->all()), true))><span class="form-check-label">{{ $specialty->name }}</span></label></div>
                            @endforeach
                        </div></div>
                        <x-doctors.photo-field :profile="$profile" />
                        <div class="col-12"><label class="form-check"><input class="form-check-input" name="is_accepting_patients" type="checkbox" value="1" @checked(old('is_accepting_patients', $profile->is_accepting_patients))><span class="form-check-label">I am accepting new patients</span></label></div>
                    </div>
                    <button class="btn btn-primary mt-4" type="submit" data-loading-text="Saving…">Save professional profile</button>
                </form>
            </section>
        </div>
    </div></div></div>
@endsection
