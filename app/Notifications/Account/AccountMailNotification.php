<?php

namespace App\Notifications\Account;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Account emails (invitations, activation, password reset) rendered with the same branded layout as the
 * appointment emails. They never contain passwords; links that carry tokens are only built when the email is sent.
 */
abstract class AccountMailNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract protected function subject(): string;

    abstract protected function heading(): string;

    abstract protected function introduction(): string;

    abstract protected function actionUrl(object $notifiable): string;

    abstract protected function actionLabel(): string;

    /**
     * One of: success, warning, info.
     */
    protected function tone(): string
    {
        return 'info';
    }

    /**
     * @return array<string, string>
     */
    protected function details(object $notifiable): array
    {
        return [];
    }

    protected function closing(): ?string
    {
        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->subject();
        $appName = config('app.name', 'myDoc');
        $footer = 'This is an automated account message from '.$appName.'. '.$appName.' will never ask for your password by email.';

        return (new MailMessage)
            ->subject($subject)
            ->view(
                ['html' => 'emails.appointments.notification', 'text' => 'emails.appointments.notification-text'],
                [
                    'subject' => $subject,
                    'tone' => $this->tone(),
                    'heading' => $this->heading(),
                    'recipientName' => $notifiable->name ?? null,
                    'introduction' => $this->introduction(),
                    'details' => $this->details($notifiable),
                    'scheduleChange' => null,
                    'closing' => $this->closing(),
                    'actionUrl' => $this->actionUrl($notifiable),
                    'actionLabel' => $this->actionLabel(),
                    'appName' => $appName,
                    'footerNote' => $footer,
                    'footerTextNote' => $footer,
                ]
            );
    }

    /**
     * An absolute link for the email, always on APP_URL. These emails are sent during a web request (password reset,
     * invitations), where route() would use the request's Host header: an attacker could then make a genuine email
     * carry a reset or invitation token to a host they control. Only the path comes from the router.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function trustedUrl(string $routeName, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($routeName, $parameters, absolute: false);
    }

    protected function expiryText(int $minutes): string
    {
        if ($minutes % 60 !== 0) {
            return $minutes.' '.str('minute')->plural($minutes);
        }

        $hours = intdiv($minutes, 60);

        return $hours.' '.str('hour')->plural($hours);
    }
}
