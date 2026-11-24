@props(['profile'])

{{-- Large photo that doubles as the upload control; a chosen image is previewed before the form is saved. --}}
<div class="doctor-photo-editor" data-photo-editor>
    <div class="doctor-photo-editor__frame">
        <img src="{{ $profile->photoUrl() ?? asset('images/doctor.png') }}" alt="Profile photo of {{ $profile->user->name }}" width="160" height="160" data-photo-preview data-photo-default="{{ asset('images/doctor.png') }}">
        <input class="visually-hidden @error('photo') is-invalid @enderror" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="photo_help" data-max-file-bytes="2097152" data-max-file-label="2 MB">
        <label class="doctor-photo-editor__button" for="photo" title="{{ $profile->photo_path ? 'Change photo' : 'Add photo' }}"><i class="fa-solid fa-camera" aria-hidden="true"></i><span class="visually-hidden">{{ $profile->photo_path ? 'Change photo' : 'Add photo' }}</span></label>
        <div class="invalid-feedback" data-file-size-error>@error('photo'){{ $message }}@enderror</div>
    </div>
    <p class="form-text mb-0" id="photo_help">JPG, PNG or WebP, up to 2&nbsp;MB.<br>64–4000 pixels on each side.</p>
    <p class="doctor-photo-editor__pending" role="status" data-photo-pending></p>
    @if ($profile->photo_path)
        <div class="form-check small mb-0">
            <input class="form-check-input" id="remove_photo" name="remove_photo" type="checkbox" value="1" data-photo-remove>
            <label class="form-check-label" for="remove_photo">Remove current photo</label>
        </div>
    @endif
</div>
