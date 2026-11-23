<?php

namespace App\Notifications\Account;

/**
 * Invitation to set the password of an admin-created Doctor Assistant account. Sent immediately (not queued) so the
 * raw token is never written to the queue. The email contains no password.
 */
class AssistantInvitationNotification extends AccountMailNotification
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly int $expiresInMinutes,
        private readonly bool $isReminder = false,
    ) {}

    protected function subject(): string
    {
        return $this->isReminder
            ? 'Reminder: set up your '.config('app.name', 'myDoc').' Doctor Assistant account'
            : 'You are invited to join '.config('app.name', 'myDoc').' as a Doctor Assistant';
    }

    protected function heading(): string
    {
        return 'Set up your Doctor Assistant account';
    }

    protected function introduction(): string
    {
        return 'An administrator has created a Doctor Assistant account for you on '.config('app.name', 'myDoc')
            .'. Choose your password to set up the account.';
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
        return $this->trustedUrl('assistant.invitation.show', ['token' => $this->token, 'email' => $notifiable->email]);
    }

    protected function actionLabel(): string
    {
        return 'Set your password';
    }
}
