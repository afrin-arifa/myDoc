@props(['selected' => null])

@php
    $bookableTypes = App\Models\Appointment::bookableConsultationTypes();
    $onlyOneType = count($bookableTypes) === 1;
    $hasError = $errors->has('consultation_type');
    $describedBy = trim(($onlyOneType ? 'consultation-type-help ' : '').($hasError ? 'consultation-type-error' : ''));
@endphp

<fieldset class="consultation-options">
    <legend class="h5 mb-3">How would you like to consult?</legend>
    <div class="row g-3">
        @foreach ([
            'physical' => ['label' => 'Physical visit', 'description' => 'Meet your doctor in person.', 'icon' => 'PV'],
            'voice' => ['label' => 'Voice call', 'description' => 'Speak with your doctor by phone.', 'icon' => 'VC'],
            'video' => ['label' => 'Video call', 'description' => 'Meet securely by video.', 'icon' => 'VV'],
        ] as $type => $details)
            @continue(! in_array($type, $bookableTypes, true))
            <div class="col-md-4">
                <label class="consultation-option" for="consultation-{{ $type }}">
                    <input class="visually-hidden" id="consultation-{{ $type }}" name="consultation_type" type="radio"
                        value="{{ $type }}" @checked($onlyOneType || old('consultation_type', $selected) === $type) required
                        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                        @if ($hasError) aria-invalid="true" @endif>
                    <span class="consultation-option__icon" aria-hidden="true">{{ $details['icon'] }}</span>
                    <span>
                        <strong>{{ $details['label'] }}</strong>
                        <small>{{ $details['description'] }}</small>
                    </span>
                </label>
            </div>
        @endforeach
    </div>
    @if ($onlyOneType)
        <p class="small text-secondary mt-2 mb-0" id="consultation-type-help">Voice and video consultations are not available yet.</p>
    @endif
    @error('consultation_type')
        <div class="invalid-feedback d-block" id="consultation-type-error">{{ $message }}</div>
    @enderror
</fieldset>
