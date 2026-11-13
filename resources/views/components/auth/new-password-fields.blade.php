<div class="mb-3">
    <label class="form-label" for="password">New password</label>
    <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password"
        required autocomplete="new-password" aria-describedby="password_help">
    <div class="form-text" id="password_help">At least 8 characters, with upper- and lower-case letters and a number.</div>
    @error('password')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-4">
    <label class="form-label" for="password_confirmation">Confirm new password</label>
    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required
        autocomplete="new-password">
</div>
