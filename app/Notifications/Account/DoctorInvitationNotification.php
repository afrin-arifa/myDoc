<?php

namespace App\Notifications\Account;

/**
 * Invitation for an admin-created doctor account. Sent immediately (not queued) so the raw token only exists in
 * memory and in the email itself, never in the jobs table or logs.
 */
class DoctorInvitationNotification extends AccountMailNotification
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly int $expiresInMinutes,
        private readonly bool $isReminder = false,
    ) {}

    protected function subject(): string
    {
        return $this->isReminder
            ? 'Reminder: activate your '.config('app.name', 'myDoc').' doctor account'
            : 'You are invited to join '.config('app.name', 'myDoc').' as a doctor';
    }

    protected function heading(): string
    {
        return 'Activate your doctor account';
    }

    protected function introduction(): string
    {
        return 'An administrator has created a doctor account for you on '.config('app.name', 'myDoc')
            .'. Set your password to activate it, then sign in to complete your professional profile.';
    }

    /**
     * @return array<string, string>
     */
    protected function details(object $notifiable): array
    {
        return [
            'Account email' => $notifiable->email,
            'Link valid for' => $this->expiryText($this->expiresInMinutes),
        ];
    }

    protected function closing(): ?string
    {
        return 'The link can be used once. If it has expired, ask your administrator to send a new invitation. '
            .'If you were not expecting this email, you can ignore it.';
    }

    protected function actionUrl(object $notifiable): string
    {
        return route('doctor.invitation.show', ['token' => $this->token, 'email' => $notifiable->email]);
    }

    protected function actionLabel(): string
    {
        return 'Set password and activate';
    }
}
