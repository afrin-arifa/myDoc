<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentGoogleCalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendarService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleCalendarController extends Controller
{
    public function connect(Request $request, GoogleCalendarService $service): RedirectResponse
    {
        $state = Str::random(64);
        $request->session()->put('google_calendar_oauth_state', $state);

        return redirect()->away($service->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendarService $service): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('google_calendar_oauth_state');
        $state = (string) $request->query('state');

        abort_unless($expectedState !== '' && $state !== '' && hash_equals($expectedState, $state), 419);

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('doctor.dashboard')
                ->with('error', 'Google Calendar connection was cancelled.');
        }

        $profile = $request->user()->doctorProfile;
        abort_unless($profile, 403);

        try {
            $tokens = $service->exchangeCode($request->string('code')->toString());
            $connection = $profile->googleCalendarConnection()->first();
            $connectionData = $service->connectionData($tokens);
        } catch (GoogleServiceException|RuntimeException $exception) {
            Log::warning('Google Calendar authorization failed.', [
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
            ]);

            return redirect()->route('doctor.dashboard')
                ->with('error', 'Google Calendar could not be connected. Please try again.');
        }

        $connection = GoogleCalendarConnection::query()->updateOrCreate(
            ['doctor_profile_id' => $profile->id],
            [
                ...$connectionData,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $connection?->refresh_token,
                'is_connected' => true,
            ]
        );
        AppointmentGoogleCalendarEvent::query()
            ->whereHas('appointment.slot', fn ($query) => $query->where('doctor_profile_id', $profile->id))
            ->update([
                'google_calendar_connection_id' => $connection->id,
                'google_event_id' => null,
                'status' => 'pending',
                'last_error' => null,
                'synced_at' => null,
            ]);
        Appointment::query()
            ->where('status', 'confirmed')
            ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profile->id))
            ->pluck('id')
            ->each(fn (int $appointmentId): mixed => SyncAppointmentToGoogleCalendar::dispatch(
                $appointmentId,
                'sync'
            )->afterCommit());

        return redirect()->route('doctor.dashboard')
            ->with('status', 'Google Calendar is now connected.');
    }

    public function disconnect(Request $request, GoogleCalendarService $service): RedirectResponse
    {
        $connection = $request->user()->doctorProfile?->googleCalendarConnection;

        if ($connection) {
            try {
                $service->revoke($connection);
            } catch (GoogleServiceException|RuntimeException $exception) {
                Log::warning('Google Calendar token revocation failed.', [
                    'user_id' => $request->user()->id,
                    'exception' => $exception::class,
                ]);
            }

            $connection->delete();
        }

        return back()->with(
            'status',
            'Google Calendar has been disconnected. Existing Google Calendar events were not automatically deleted.'
        );
    }
}
