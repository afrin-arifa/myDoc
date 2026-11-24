@extends('layouts.app')

@section('title', 'Professional profile | myDoc')

@section('content')
    @php
        $missingSections = $profile->missingProfileSections();
        $selectedSpecialtyIds = array_map('intval', $errors->any() ? (array) old('specialty_ids', []) : $profile->specialties->pluck('id')->all());
        $isAcceptingPatients = (bool) ($errors->any() ? old('is_accepting_patients') : $profile->is_accepting_patients);
    @endphp
    <div class="doctor-workspace"><div class="container-fluid doctor-workspace__container">
        <form method="POST" action="{{ route('doctor.profile.update') }}" enctype="multipart/form-data" data-disable-on-submit>
            @csrf @method('PUT')

            <section class="doctor-panel doctor-profile-header" aria-labelledby="doctor-profile-name">
                <x-doctors.photo-editor :profile="$profile" />
                <div class="doctor-profile-header__body">
                    <p class="doctor-eyebrow">Professional profile</p>
                    <h1 class="doctor-page-title" id="doctor-profile-name">{{ $profile->user->name }}</h1>
                    <p class="doctor-profile-header__title">{{ $profile->professional_title }}@if ($profile->qualifications)<span class="text-secondary"> · {{ $profile->qualifications }}</span>@endif</p>
                    <div class="doctor-profile-header__tags">
                        <span class="status-badge status-badge--{{ $profile->is_accepting_patients ? 'success' : 'neutral' }}">{{ $profile->is_accepting_patients ? 'Accepting patients' : 'Not accepting patients' }}</span>
                        @foreach ($profile->specialties as $specialty)
                            <span class="doctor-card__specialty">{{ $specialty->name }}</span>
                        @endforeach
                    </div>
                    <p class="small text-secondary mb-0">Patients see this information when choosing their care. <a href="{{ route('doctor.settings') }}">Edit your name and contact details</a></p>
                </div>
                <dl class="doctor-profile-header__facts">
                    <div><dt>Experience</dt><dd>{{ $profile->years_of_experience !== null ? $profile->years_of_experience.' '.Str::plural('year', $profile->years_of_experience) : 'Not specified' }}</dd></div>
                    <div><dt>Consultation</dt><dd>{{ App\Services\ConsultationFee::format($profile->consultation_fee) }}</dd></div>
                    <div><dt>License</dt><dd>{{ $profile->license_number }}</dd></div>
                </dl>
            </section>

            @if ($missingSections !== [])
                <x-ui.alert type="info" class="mb-4">
                    <p class="fw-semibold mb-1">Complete your profile</p>
                    <p class="mb-0">Patients see this information when choosing a doctor. Still missing: {{ implode(', ', $missingSections) }}.</p>
                </x-ui.alert>
            @endif

            <div class="row g-4">
                <div class="col-xl-8 d-grid gap-4 align-content-start">
                    <section class="doctor-panel" aria-labelledby="credentials-title">
                        <div class="doctor-profile-section"><i class="fa-solid fa-user-doctor" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="credentials-title">Credentials</h2><p>Your title, license and training.</p></div></div>
                        <div class="row g-3">
                            <div class="col-md-8"><label class="form-label" for="professional_title">Professional title</label><input class="form-control @error('professional_title') is-invalid @enderror" id="professional_title" name="professional_title" value="{{ old('professional_title', $profile->professional_title) }}" maxlength="255" required>@error('professional_title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-4"><label class="form-label" for="license_number">License number</label><input class="form-control @error('license_number') is-invalid @enderror" id="license_number" name="license_number" value="{{ old('license_number', $profile->license_number) }}" maxlength="255" required>@error('license_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-8"><label class="form-label" for="qualifications">Qualifications</label><input class="form-control @error('qualifications') is-invalid @enderror" id="qualifications" name="qualifications" value="{{ old('qualifications', $profile->qualifications) }}" maxlength="500" placeholder="e.g. MBBS, FCPS (Medicine)">@error('qualifications')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-4"><label class="form-label" for="years_of_experience">Years of experience</label><input class="form-control @error('years_of_experience') is-invalid @enderror" id="years_of_experience" name="years_of_experience" type="number" min="0" max="100" value="{{ old('years_of_experience', $profile->years_of_experience) }}">@error('years_of_experience')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        </div>
                    </section>

                    <section class="doctor-panel" aria-labelledby="about-title">
                        <div class="doctor-profile-section"><i class="fa-solid fa-align-left" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="about-title">About you</h2><p>Introduce yourself and how you care for patients.</p></div></div>
                        <label class="form-label" for="bio">Biography</label>
                        <textarea class="form-control @error('bio') is-invalid @enderror" id="bio" name="bio" rows="6" maxlength="5000">{{ old('bio', $profile->bio) }}</textarea>
                        @error('bio')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </section>
                </div>

                <div class="col-xl-4 d-grid gap-4 align-content-start">
                    <section class="doctor-panel" aria-labelledby="availability-title">
                        <div class="doctor-profile-section"><i class="fa-solid fa-user-check" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="availability-title">Availability</h2><p>When off, patients cannot find or book you.</p></div></div>
                        <div class="form-check form-switch mb-0"><input class="form-check-input" id="is_accepting_patients" name="is_accepting_patients" type="checkbox" role="switch" value="1" @checked($isAcceptingPatients)><label class="form-check-label" for="is_accepting_patients">I am accepting new patients</label></div>
                    </section>

                    <section class="doctor-panel" aria-labelledby="fees-title">
                        <div class="doctor-profile-section"><i class="fa-solid fa-wallet" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="fees-title">Fees</h2><p>Existing appointments keep the fee they were booked with.</p></div></div>
                        <div class="row g-3">
                            <div class="col-sm-6 col-xl-12"><label class="form-label" for="consultation_fee">Consultation fee ({{ App\Services\ConsultationFee::CURRENCY }})</label><input class="form-control @error('consultation_fee') is-invalid @enderror" id="consultation_fee" name="consultation_fee" type="number" step="0.01" min="0" value="{{ old('consultation_fee', $profile->consultation_fee) }}" required>@error('consultation_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-sm-6 col-xl-12"><label class="form-label" for="follow_up_fee">Follow-up fee ({{ App\Services\ConsultationFee::CURRENCY }})</label><input class="form-control @error('follow_up_fee') is-invalid @enderror" id="follow_up_fee" name="follow_up_fee" type="number" step="0.01" min="0" value="{{ old('follow_up_fee', $profile->follow_up_fee) }}" aria-describedby="follow_up_fee_help">@error('follow_up_fee')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text" id="follow_up_fee_help">Optional. Leave empty to charge the consultation fee for follow-ups.</div></div>
                        </div>
                    </section>

                    <section class="doctor-panel" aria-labelledby="specialties-title">
                        <div class="doctor-profile-section"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i><div><h2 class="doctor-panel__title" id="specialties-title">Specialties</h2><p>Choose every area you practise.</p></div></div>
                        <div class="doctor-specialty-picker" role="group" aria-labelledby="specialties-title">
                            @foreach ($specialties as $specialty)
                                <label class="doctor-specialty-option"><input class="form-check-input" name="specialty_ids[]" type="checkbox" value="{{ $specialty->id }}" @checked(in_array($specialty->id, $selectedSpecialtyIds, true))><span>{{ $specialty->name }}</span></label>
                            @endforeach
                        </div>
                    </section>
                </div>
            </div>

            <div class="doctor-profile-actions"><button class="btn btn-primary" type="submit" data-loading-text="Saving…">Save professional profile</button></div>
        </form>
    </div></div>
@endsection
