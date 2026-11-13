@props(['profile'])

<div class="col-12">
    <label class="form-label" for="photo">Profile photo</label>
    <div class="d-flex align-items-center gap-3">
        @if ($profile?->photoUrl())
            <img class="rounded-circle object-fit-cover flex-shrink-0" src="{{ $profile->photoUrl() }}" alt="Current profile photo" width="64" height="64">
        @endif
        <div class="flex-grow-1">
            <input class="form-control @error('photo') is-invalid @enderror" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="photo_help">
            <div class="form-text" id="photo_help">JPG, PNG or WebP, up to 2 MB, between 64 and 4000 pixels on each side.</div>
            @error('photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    @if ($profile?->photo_path)
        <div class="form-check mt-2">
            <input class="form-check-input" id="remove_photo" name="remove_photo" type="checkbox" value="1">
            <label class="form-check-label" for="remove_photo">Remove current photo</label>
        </div>
    @endif
</div>
