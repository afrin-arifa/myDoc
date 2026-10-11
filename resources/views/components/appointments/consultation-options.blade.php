@props(['selected' => null])

<fieldset class="consultation-options">
    <legend class="h5 mb-3">How would you like to consult?</legend>
    <div class="row g-3">
        @foreach ([
            'physical' => ['label' => 'Physical visit', 'description' => 'Meet your doctor in person.', 'icon' => 'PV'],
            'voice' => ['label' => 'Voice call', 'description' => 'Speak with your doctor by phone.', 'icon' => 'VC'],
            'video' => ['label' => 'Video call', 'description' => 'Meet securely by video.', 'icon' => 'VV'],
        ] as $type => $details)
            <div class="col-md-4">
                <label class="consultation-option" for="consultation-{{ $type }}">
                    <input class="visually-hidden" id="consultation-{{ $type }}" name="consultation_type" type="radio"
                        value="{{ $type }}" @checked(old('consultation_type', $selected) === $type) required>
                    <span class="consultation-option__icon" aria-hidden="true">{{ $details['icon'] }}</span>
                    <span>
                        <strong>{{ $details['label'] }}</strong>
                        <small>{{ $details['description'] }}</small>
                    </span>
                </label>
            </div>
        @endforeach
    </div>
    @error('consultation_type')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</fieldset>
