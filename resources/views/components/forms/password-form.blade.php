<form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('profile.password.update') }}">
    @csrf
    @method('PUT')
    <h2 class="h5 mb-3">Change password</h2>
    <label class="form-label" for="current_password">Current password</label>
    <input class="form-control mb-3" id="current_password" name="current_password" type="password" required autocomplete="current-password">
    <label class="form-label" for="password">New password</label>
    <input class="form-control mb-3" id="password" name="password" type="password" required autocomplete="new-password">
    <label class="form-label" for="password_confirmation">Confirm new password</label>
    <input class="form-control mb-3" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
    <button class="btn btn-outline-primary" type="submit">Update password</button>
</form>
