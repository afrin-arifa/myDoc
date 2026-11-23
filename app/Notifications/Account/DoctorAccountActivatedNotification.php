<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Confirms a doctor's account activation. Contains no token, so it is safe to queue; it is dispatched only after
 * the activation transaction commits.
 */
class DoctorAccountActivatedNotification extends AccountMailNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct()
    {
        $this->afterCommit();
    }

    protected function tone(): string
    {
        return 'success';
    }

    protected function subject(): string
    {
        return 'Your '.config('app.name', 'myDoc').' doctor account is active';
    }

    protected function heading(): string
    {
        return 'Account activated';
    }

    protected function introduction(): string
    {
        return 'Your doctor account is now active. Sign in with your email address and the password you just created.';
    }

    protected function closing(): ?string
    {
        return 'Next, complete your professional profile so patients can find you. '
            .'If you did not activate this account, contact your administrator immediately.';
    }

    protected function actionUrl(object $notifiable): string
    {
        return $this->trustedUrl('login');
    }

    protected function actionLabel(): string
    {
        return 'Sign in';
    }
}
