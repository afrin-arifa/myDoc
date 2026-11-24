@props(['user', 'form', 'visibility' => 'Seen by you, your doctors and myDoc staff.'])

{{-- Photo (or initials) that doubles as the upload control. The fields belong to the profile form given by its id. --}}
<div class="doctor-photo-editor patient-photo-editor" data-photo-editor>
    <div class="doctor-photo-editor__frame">
        <img src="{{ $user->photoUrl() ?? '' }}" alt="Your profile photo" width="160" height="160" data-photo-preview data-photo-default="" @if (! $user->photo_path) hidden @endif>
        <div data-photo-fallback @if ($user->photo_path) hidden @endif><x-ui.avatar class="patient-profile__avatar" :name="$user->name" size="xl" /></div>
        <input class="visually-hidden @error('photo') is-invalid @enderror" id="photo" name="photo" type="file" form="{{ $form }}" accept="image/jpeg,image/png,image/webp" aria-describedby="photo_help" data-max-file-bytes="2097152" data-max-file-label="2 MB">
        <label class="doctor-photo-editor__button" for="photo" title="{{ $user->photo_path ? 'Change photo' : 'Add photo' }}"><i class="fa-solid fa-camera" aria-hidden="true"></i><span class="visually-hidden">{{ $user->photo_path ? 'Change photo' : 'Add photo' }}</span></label>
        <div class="invalid-feedback" data-file-size-error>@error('photo'){{ $message }}@enderror</div>
    </div>
    <p class="form-text mb-0" id="photo_help">JPG, PNG or WebP, up to 2&nbsp;MB. {{ $visibility }}</p>
    @if ($user->photo_path)
        <div class="form-check small mb-0">
            <input class="form-check-input" id="remove_photo" name="remove_photo" type="checkbox" value="1" form="{{ $form }}" data-photo-remove>
            <label class="form-check-label" for="remove_photo">Remove current photo</label>
        </div>
    @endif
    <p class="doctor-photo-editor__pending" role="status" data-photo-pending></p>
    <button class="btn btn-outline-primary btn-sm" type="submit" form="{{ $form }}" data-photo-save hidden>Save photo</button>
</div>
