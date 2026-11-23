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
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Talks to the doctor's own Google Calendar. Events are private to the doctor's calendar and carry only a generic
 * title, the consultation type and the chamber where it is held: never the patient's name, contact details, notes or
 * documents.
 *
 * Every Google request has a timeout (config services.google.calendar.timeout), so a slow Google API cannot hold a
 * queue worker or a web request. Lost authorization (revoked or expired refresh token, or a 401) is reported as
 * GoogleCalendarAuthorizationException so callers can ask the doctor to reconnect instead of retrying.
 */
class GoogleCalendarService
{
    /**
     * Both dependencies are optional: by default the Calendar service is built from the doctor's authenticated client
     * and requests go through a Guzzle client with the configured timeouts.
     */
    public function __construct(
        private readonly ?Calendar $calendar = null,
        private readonly ?ClientInterface $httpClient = null,
    ) {}

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
            $this->throwIfUnauthorized($exception);

            if ($exception->getCode() !== 409) {
                throw $exception;
            }

            $this->call(fn () => $calendar->events->update('primary', $eventId, $this->event($appointment, $eventId)));
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
            $this->throwIfUnauthorized($exception);

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
            $this->throwIfUnauthorized($exception);

            if ($exception->getCode() !== 404 && $exception->getCode() !== 410) {
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

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (GoogleServiceException $exception) {
            $this->throwIfUnauthorized($exception);

            throw $exception;
        }
    }

    private function throwIfUnauthorized(GoogleServiceException $exception): void
    {
        if ($exception->getCode() === 401) {
            throw new GoogleCalendarAuthorizationException('Google authorization is no longer valid.', 401, $exception);
        }
    }

    private function client(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google.calendar.client_id'));
        $client->setClientSecret((string) config('services.google.calendar.client_secret'));
        $client->setRedirectUri((string) config('services.google.calendar.redirect_uri'));
        // Same options as the library's default HTTP client (base URI, and HTTP errors returned rather than thrown so
        // OAuth errors such as "invalid_grant" can be read), plus timeouts.
        $client->setHttpClient($this->httpClient ?? new HttpClient([
            'base_uri' => $client->getConfig('base_path'),
            'http_errors' => false,
            'timeout' => (float) config('services.google.calendar.timeout', 10),
            'connect_timeout' => (float) config('services.google.calendar.connect_timeout', 5),
        ]));
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
        // The client judges expiry from "created" + "expires_in"; without "created" it treats every token as
        // expired and refreshes it on every request.
        $client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            'created' => now()->getTimestamp(),
            'expires_in' => $connection->token_expires_at
                ? max(0, (int) now()->diffInSeconds($connection->token_expires_at, false))
                : 0,
        ]);

        if ($client->isAccessTokenExpired()) {
            if (! $connection->refresh_token) {
                throw new GoogleCalendarAuthorizationException('Google authorization has expired and cannot be refreshed.');
            }

            $tokens = $client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

            if (($tokens['error'] ?? null) === 'invalid_grant') {
                // Revoked by the doctor in their Google account, expired, or the password changed.
                throw new GoogleCalendarAuthorizationException('Google authorization was revoked or has expired.');
            }

            if (isset($tokens['error']) || ! isset($tokens['access_token'])) {
                throw new RuntimeException('Google authorization could not be refreshed.');
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

    private function consultationLabel(?string $consultationType): string
    {
        return match ($consultationType) {
            'voice' => 'Voice call',
            'video' => 'Video call',
            default => 'Physical visit',
        };
    }

    private function event(Appointment $appointment, string $eventId): Event
    {
        $appointment->loadMissing('slot.location');

        return new Event([
            'id' => $eventId,
            'summary' => 'myDoc Appointment',
            'description' => 'Consultation: '.$this->consultationLabel($appointment->consultation_type),
            // The doctor's own chamber (practice details, not patient data).
            'location' => $appointment->slot->location?->summary(),
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
