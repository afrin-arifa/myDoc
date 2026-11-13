@props(['user'])

<div {{ $attributes->merge(['class' => 'form-check']) }}>
    <input name="sms_notifications_enabled" type="hidden" value="0">
    <input class="form-check-input @error('sms_notifications_enabled') is-invalid @enderror" id="sms_notifications_enabled" name="sms_notifications_enabled"
        type="checkbox" value="1" aria-describedby="sms_notifications_help" @checked(old('sms_notifications_enabled', $user->sms_notifications_enabled))>
    <label class="form-check-label fw-semibold" for="sms_notifications_enabled">Send me appointment updates and reminders by SMS</label>
    <div class="form-text" id="sms_notifications_help">
        Messages go to the mobile number above and contain only appointment times and references, never medical details.
        Email notifications continue either way.
    </div>
    @error('sms_notifications_enabled')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
