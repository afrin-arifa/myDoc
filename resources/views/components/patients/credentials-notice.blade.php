@props(['credentials'])
{{--
    Shown once, right after staff create a patient account or issue a new one-time password. The password is not
    stored anywhere it could be shown again: it is only in this one-request flash message.
--}}
<section class="credentials-notice" role="status" aria-labelledby="credentials-heading">
    <div class="credentials-notice__header">
        <span class="credentials-notice__icon" aria-hidden="true"><i class="fa-solid fa-key"></i></span>
        <div>
            <h2 class="credentials-notice__title" id="credentials-heading">{{ $credentials['is_new'] ? 'Account created for '.$credentials['name'] : 'New one-time password for '.$credentials['name'] }}</h2>
            <p class="credentials-notice__lead">Give these sign-in details to the patient now. The password is shown only once.</p>
        </div>
    </div>
    <dl class="credentials-notice__details">
        <div>
            <dt>Email</dt>
            <dd><code data-copy-source="credential-email">{{ $credentials['email'] }}</code>
                <button class="credentials-notice__copy" type="button" data-copy="credential-email" aria-label="Copy email"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></dd>
        </div>
        <div>
            <dt>One-time password</dt>
            <dd><code class="credentials-notice__password" data-copy-source="credential-password">{{ $credentials['password'] }}</code>
                <button class="credentials-notice__copy" type="button" data-copy="credential-password" aria-label="Copy one-time password"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></dd>
        </div>
    </dl>
    <ul class="credentials-notice__rules">
        <li><i class="fa-regular fa-clock" aria-hidden="true"></i>Works until {{ $credentials['expires_at'] }}.</li>
        <li><i class="fa-solid fa-lock" aria-hidden="true"></i>The patient must choose their own password when they first sign in.</li>
        <li><i class="fa-solid fa-rotate" aria-hidden="true"></i>Lost or expired? Use “New one-time password” in the patient list.</li>
    </ul>
</section>
