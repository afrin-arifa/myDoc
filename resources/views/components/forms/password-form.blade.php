{{-- Change-password form for every signed-in role; posts to the shared profile.password.update endpoint. --}}
<form class="card border-0 shadow-sm p-4" method="POST" action="{{ route('profile.password.update') }}">
    @csrf
    @method('PUT')
    <h2 class="h5 mb-3">Change password</h2>
    @foreach (['current_password' => ['Current password', 'current-password'], 'password' => ['New password', 'new-password'], 'password_confirmation' => ['Confirm new password', 'new-password']] as $field => [$label, $autocomplete])
        <div class="mb-3">
            <label class="form-label" for="{{ $field }}">{{ $label }}</label>
            <input @class(['form-control', 'is-invalid' => $errors->has($field)]) id="{{ $field }}" name="{{ $field }}" type="password" required autocomplete="{{ $autocomplete }}"
                @if ($field === 'password') aria-describedby="password_help" @endif>
            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
            @if ($field === 'password')
                <div class="form-text" id="password_help">{{ auth()->user()?->role === 'patient' ? 'At least 8 characters.' : 'At least 8 characters, with upper- and lower-case letters and a number.' }}</div>
            @endif
        </div>
    @endforeach
    <button class="btn btn-outline-primary align-self-start" type="submit">Update password</button>
</form>
