<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\GoogleCalendarConnection;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Oauth2;
use Illuminate\Support\Carbon;
use RuntimeException;

class GoogleCalendarService
{
    public function __construct(private readonly ?Calendar $calendar = null) {}

    public function authorizationUrl(string $state): string
    {
        $client = $this->client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $tokens = $this->client()->fetchAccessTokenWithAuthCode($code);

        if (isset($tokens['error']) || ! isset($tokens['access_token'])) {
            throw new RuntimeException('Google authorization was not completed.');
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array{calendar_id: string, google_account_email: string|null, token_expires_at: Carbon|null}
     */
    public function connectionData(array $tokens): array
    {
        $client = $this->client();
        $client->setAccessToken($tokens);
        $email = null;

        try {
            $email = (new Oauth2($client))->userinfo->get()->getEmail();
        } catch (GoogleServiceException) {
            // Email is informational; Calendar synchronization does not depend on it.
        }

        return [
            'calendar_id' => 'primary',
            'google_account_email' => $email ?: null,
            'token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : null,
        ];
    }

    public function createEvent(GoogleCalendarConnection $connection, Appointment $appointment): string
    {
        $eventId = $this->eventId($appointment);
        $calendar = $this->calendar($connection);

        try {
            $calendar->events->insert(
                'primary',
                $this->event($appointment, $eventId)
            );
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 409) {
                throw $exception;
            }

            $calendar->events->update('primary', $eventId, $this->event($appointment, $eventId));
        }

        return $eventId;
    }

    public function updateEvent(
        GoogleCalendarConnection $connection,
        Appointment $appointment,
        string $googleEventId
    ): void {
        $calendar = $this->calendar($connection);

        try {
            $calendar->events->update(
                'primary',
                $googleEventId,
                $this->event($appointment, $googleEventId)
            );
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }

            $this->createEvent($connection, $appointment);
        }
    }

    public function deleteEvent(GoogleCalendarConnection $connection, string $googleEventId): void
    {
        try {
            $this->calendar($connection)->events->delete(
                'primary',
                $googleEventId
            );
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }
    }

    public function revoke(GoogleCalendarConnection $connection): void
    {
        $this->authenticatedClient($connection)->revokeToken();
    }

    private function calendar(GoogleCalendarConnection $connection): Calendar
    {
        return $this->calendar ?? new Calendar($this->authenticatedClient($connection));
    }

    private function client(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google.calendar.client_id'));
        $client->setClientSecret((string) config('services.google.calendar.client_secret'));
        $client->setRedirectUri((string) config('services.google.calendar.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([
            Calendar::CALENDAR_EVENTS,
            Oauth2::USERINFO_EMAIL,
        ]);

        return $client;
    }

    private function authenticatedClient(GoogleCalendarConnection $connection): Client
    {
        $client = $this->client();
        $client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            'expires_in' => $connection->token_expires_at
                ? max(0, now()->diffInSeconds($connection->token_expires_at, false))
                : 0,
        ]);

        if ($client->isAccessTokenExpired()) {
            if (! $connection->refresh_token) {
                throw new RuntimeException('Google authorization has expired.');
            }

            $tokens = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

            if (isset($tokens['error']) || ! isset($tokens['access_token'])) {
                throw new RuntimeException('Google authorization has expired.');
            }

            $refreshedToken = [
                ...$tokens,
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            ];
            $connection->update([
                'access_token' => $refreshedToken['access_token'],
                'refresh_token' => $refreshedToken['refresh_token'],
                'token_expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : $connection->token_expires_at,
            ]);
            $client->setAccessToken($refreshedToken);
        }

        return $client;
    }

    public function eventId(Appointment $appointment): string
    {
        return 'md'.strtr(hash('sha256', 'appointment:'.$appointment->id), [
            'a' => 'g',
            'b' => 'h',
            'c' => 'i',
            'd' => 'j',
            'e' => 'k',
            'f' => 'l',
        ]);
    }

    private function event(Appointment $appointment, string $eventId): Event
    {
        $appointment->loadMissing('slot');

        return new Event([
            'id' => $eventId,
            'summary' => 'myDoc Appointment',
            'description' => 'Appointment type: '.$appointment->consultation_type,
            'start' => new EventDateTime([
                'dateTime' => $appointment->slot->starts_at->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
            'end' => new EventDateTime([
                'dateTime' => $appointment->slot->ends_at->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
        ]);
    }
}
