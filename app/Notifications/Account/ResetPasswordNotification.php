<?php

namespace App\Notifications\Account;

/**
 * Password reset link from Laravel's "users" password broker. Sent immediately (not queued) so the raw token
 * never reaches the jobs table.
 */
class ResetPasswordNotification extends AccountMailNotification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    protected function tone(): string
    {
        return 'warning';
    }

    protected function subject(): string
    {
        return 'Reset your '.config('app.name', 'myDoc').' password';
    }

    protected function heading(): string
    {
        return 'Password reset request';
    }

    protected function introduction(): string
    {
        return 'We received a request to reset the password for your account. Use the button below to choose a new password.';
    }

    /**
     * @return array<string, string>
     */
    protected function details(object $notifiable): array
    {
        return [
            'Link valid for' => $this->expiryText((int) config('auth.passwords.users.expire', 60)),
        ];
    }

    protected function closing(): ?string
    {
        return 'The link can be used once. If you did not request a password reset, no action is needed and your password stays the same.';
    }

    protected function actionUrl(object $notifiable): string
    {
        return route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
    }

    protected function actionLabel(): string
    {
        return 'Reset password';
    }
}
