<?php

namespace App\Services;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Resolves the doctor context of an action and authorizes the actor for it, keeping two identities apart:
 *
 *  - the actor: the signed-in user who performs the action (recorded in audit fields), and
 *  - the doctor context: the DoctorProfile whose appointments, schedule, patients or records are managed.
 *
 * A doctor's context is their own profile; a Doctor Assistant's context is a doctor they are currently assigned
 * to. Whether the actor may use an ability for that doctor is always decided by DoctorAccess (database-backed, on
 * every call). A doctor context is never taken from a submitted form field: only from a route-bound DoctorProfile
 * (the assistant workspace, added in a later phase) or the doctor's own profile.
 */
class DoctorWorkspace
{
    /**
     * Route parameter that carries an explicit doctor context, e.g. /assistant/doctors/{doctorProfile}/...
     */
    public const ROUTE_PARAMETER = 'doctorProfile';

    /**
     * Request attribute holding the doctor profile authorized by the works-for-doctor middleware.
     */
    public const REQUEST_ATTRIBUTE = 'workspaceDoctorProfile';

    /**
     * Doctor workspace routes and their assistant workspace equivalents (assistant.workspace.{suffix}, prefixed with
     * the selected {doctorProfile}). Doctor-only routes (dashboard, profile, settings, Google Calendar, starting and
     * completing consultations) deliberately have no assistant equivalent.
     *
     * @var array<string, string>
     */
    public const ASSISTANT_ROUTES = [
        'doctor.appointments.index' => 'appointments.index',
        'doctor.appointments.create' => 'appointments.create',
        'doctor.appointments.store' => 'appointments.store',
        'doctor.appointments.show' => 'appointments.show',
        'doctor.appointments.confirm' => 'appointments.confirm',
        'doctor.appointments.reject' => 'appointments.reject',
        'doctor.appointments.cancel' => 'appointments.cancel',
        'doctor.appointments.reschedule' => 'appointments.reschedule',
        'doctor.appointments.reschedule.store' => 'appointments.reschedule.store',
        'doctor.patients.search' => 'patients.search',
        'doctor.patients.index' => 'patients.index',
        'doctor.schedule' => 'schedule',
        'doctor.availability.store' => 'availability.store',
        'doctor.availability.update' => 'availability.update',
        'doctor.availability.destroy' => 'availability.destroy',
        'doctor.slots.preview' => 'slots.preview',
        'doctor.slots.generate' => 'slots.generate',
        'doctor.slots.block' => 'slots.block',
        'doctor.slots.unblock' => 'slots.unblock',
        'doctor.medical-records.index' => 'medical-records.index',
    ];

    public function __construct(private readonly DoctorAccess $access) {}

    /**
     * The doctor selected in the current assistant workspace URL, or null on doctor-owned pages.
     */
    public function currentDoctorProfile(): ?DoctorProfile
    {
        $profile = app()->bound('request') ? $this->selectedProfile(request()) : null;

        return $profile instanceof DoctorProfile ? $profile : null;
    }

    /**
     * The doctor selected by the request: the profile authorized by the works-for-doctor middleware, or a
     * route-bound {doctorProfile}. Null on doctor-owned routes. A non-model value is returned as is so callers refuse it.
     */
    private function selectedProfile(Request $request): mixed
    {
        return $request->attributes->get(self::REQUEST_ATTRIBUTE) ?? $request->route(self::ROUTE_PARAMETER);
    }

    public function inAssistantWorkspace(): bool
    {
        return $this->currentDoctorProfile() !== null;
    }

    /**
     * URL for a doctor workspace route in the current context: the doctor's own route on doctor pages, or the
     * assistant workspace route for the selected doctor. Shared views use this so one view serves both.
     *
     * @param  mixed  $parameters
     */
    public function url(string $doctorRoute, $parameters = []): string
    {
        $profile = $this->currentDoctorProfile();

        if ($profile === null) {
            return route($doctorRoute, $parameters);
        }

        $suffix = self::ASSISTANT_ROUTES[$doctorRoute]
            ?? throw new \LogicException("[{$doctorRoute}] is not available in the assistant workspace.");

        $parameters = is_array($parameters) ? $parameters : [$parameters];

        return route('assistant.workspace.'.$suffix, [self::ROUTE_PARAMETER => $profile, ...$parameters]);
    }

    /**
     * Whether a doctor workspace route exists in the current context (doctor-only routes do not exist for assistants).
     */
    public function routeAvailable(string $doctorRoute): bool
    {
        return ! $this->inAssistantWorkspace() || isset(self::ASSISTANT_ROUTES[$doctorRoute]);
    }

    /**
     * The doctor this request works for, after checking the actor may use the ability for them.
     *
     * Returns null only when a signed-in doctor has not created their profile yet, so existing pages keep their own
     * handling for that case. Any other actor without a usable doctor context is refused.
     *
     * @throws AuthorizationException
     */
    public function forRequest(Request $request, string $ability): ?DoctorProfile
    {
        $actor = $request->user();
        $routeProfile = $this->selectedProfile($request);

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        if ($routeProfile !== null) {
            if (! $routeProfile instanceof DoctorProfile) {
                throw new AuthorizationException;
            }

            return $this->authorize($actor, $routeProfile, $ability);
        }

        if ($actor->role !== 'doctor') {
            throw new AuthorizationException;
        }

        $ownProfile = $actor->doctorProfile;

        return $ownProfile === null ? null : $this->authorize($actor, $ownProfile, $ability);
    }

    /**
     * Authorize the actor for a doctor context in a service. When no context is given the actor's own doctor
     * profile is used, which only a doctor has: an assistant must always pass the doctor explicitly, otherwise the
     * call is refused.
     *
     * @throws AuthorizationException
     */
    public function authorize(User $actor, ?DoctorProfile $doctorProfile, string $ability): DoctorProfile
    {
        $doctorProfile ??= $actor->role === 'doctor' ? $actor->doctorProfile : null;

        if ($doctorProfile === null || ! $this->access->canActFor($actor, $doctorProfile, $ability)) {
            throw new AuthorizationException('You cannot perform this action for this doctor.');
        }

        return $doctorProfile;
    }

    /**
     * A record reached through a doctor context must belong to that doctor; anything else is reported as missing so
     * other doctors' records are not revealed.
     */
    public function ensureBelongs(DoctorProfile $doctorProfile, int|string|null $recordDoctorProfileId): void
    {
        abort_unless($recordDoctorProfileId !== null && (int) $recordDoctorProfileId === (int) $doctorProfile->id, 404);
    }
}
